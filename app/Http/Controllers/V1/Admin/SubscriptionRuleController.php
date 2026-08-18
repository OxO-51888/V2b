<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionRuleSave;
use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Services\AiRiskService;
use App\Services\NodeExitIpService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Throwable;

class SubscriptionRuleController extends Controller
{
    private const AI_PROVIDER = 'openai';
    private const AI_BASE_URL = 'https://api.openai.com/v1';
    private const AI_MODEL = 'gpt-5-nano';
    private const AI_RUNTIME_CACHE_KEY = 'SUBSCRIPTION_RULE_AI_RUNTIME_STATUS';
    private const CLOUDFLARE_IPS_URL = 'https://www.cloudflare.com/ips-v4';
    private const CLOUDFLARE_SYNC_INTERVAL = 86400;
    private const CLOUDFLARE_SYNC_FAILURE_CACHE_KEY = 'SUBSCRIPTION_RULE_CLOUDFLARE_SYNC_FAILURE_AT';
    private const CLOUDFLARE_SYNC_FAILURE_RETRY = 3600;
    private const NODE_ALIVE_REMARK = '连续3个两分钟窗口出现至少3组稳定网络时，超过8个真实在线IP交给AI审查，超过15个自动重置订阅；同一运营商网段会合并判断，重置后24小时内不重复重置。';
    private const RULE_DEFAULTS = [
        'pull_frequency' => ['condition' => 30, 'name' => '5分钟同订阅超过%d次'],
        'ip_spread' => ['condition' => 8, 'name' => '10分钟同订阅超过%d个真实IP'],
        'ip_multi_user' => ['condition' => 6, 'name' => '10分钟同IP拉取超过%d个用户'],
        'node_alive_ip_over_limit' => ['condition' => 8, 'name' => '同账号在线IP超过%d个AI审查'],
        'direct_ip_host' => ['condition' => null, 'name' => '直连IP或本地Host访问订阅'],
        'head_method_probe' => ['condition' => null, 'name' => 'HEAD/OPTIONS探测订阅接口'],
        'ua_scanner' => ['condition' => null, 'name' => 'Censys/Shodan等扫描器UA'],
        'ua_social' => ['condition' => null, 'name' => '微信QQ/Telegram内置打开订阅'],
        'ua_browser' => ['condition' => null, 'name' => '浏览器直接打开订阅链接'],
        'ua_cli_fetch' => ['condition' => null, 'name' => 'curl/wget/PowerShell命令行抓取'],
        'ua_api_fetch' => ['condition' => null, 'name' => 'Python/Go/Node接口工具抓取'],
        'ua_converter' => ['condition' => null, 'name' => '订阅转换器UA访问'],
        'ua_vendor' => ['condition' => null, 'name' => '厂商/电商App内置打开订阅'],
        'converter_query' => ['condition' => null, 'name' => '订阅转换器参数访问'],
        'header_browser_context' => ['condition' => null, 'name' => '浏览器上下文Header'],
        'flag_ua_mismatch' => ['condition' => null, 'name' => 'flag与User-Agent客户端不一致'],
        'untrusted_proxy_header' => ['condition' => null, 'name' => '不可信代理转发头'],
        'ua_blacklist' => ['condition' => null, 'name' => '非代理客户端黑名单兜底'],
        'empty_user_agent' => ['condition' => null, 'name' => '空User-Agent请求订阅']
    ];

    public function fetch(Request $request)
    {
        $rules = SubscriptionRule::orderByRaw("
                    CASE
                        WHEN `enabled` = 1 THEN 0
                        ELSE 1
                    END ASC
                ")
            ->orderByRaw("
                    CASE
                        WHEN `action` IN ('no_nodes', 'block', 'empty_subscription', 'rate_limit') THEN 10
                        WHEN `action` = 'reset_subscribe' THEN 20
                        WHEN `action` = 'ai_review' THEN 30
                        WHEN `action` IN ('audit', 'record', 'notify_admin') THEN 40
                        ELSE 50
                    END ASC
                ")
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->get();
        $rules->each(function (SubscriptionRule $rule) {
            if ($rule->type === 'node_alive_ip_over_limit') {
                $rule->remark = self::NODE_ALIVE_REMARK;
            }
        });

        return response([
            'data' => $rules
        ]);
    }

    public function logs(Request $request)
    {
        $limit = (int)$request->input('limit', 30);
        $limit = max(1, min($limit, 100));

        return response([
            'data' => SubscriptionRuleLog::with(['rule:id,name,type', 'user:id,email'])
                ->orderBy('id', 'DESC')
                ->limit($limit)
                ->get()
        ]);
    }

    public function clearLogs(Request $request)
    {
        $count = SubscriptionRuleLog::count();
        SubscriptionRuleLog::query()->delete();

        return response([
            'data' => [
                'deleted' => $count,
                'cleared_at' => time()
            ]
        ]);
    }

    public function aiRejections(Request $request)
    {
        $limit = (int)$request->input('limit', 20);
        $limit = max(1, min($limit, 100));

        return response([
            'data' => SubscriptionRuleLog::with(['rule:id,name,type', 'user:id,email'])
                ->where('ai_decision', 'block')
                ->orderBy('id', 'DESC')
                ->limit($limit)
                ->get()
        ]);
    }

    public function aiConfig(Request $request)
    {
        return response([
            'data' => $this->safeAiConfig()
        ]);
    }

    public function saveAiConfig(Request $request)
    {
        $baseUrl = trim((string)$request->input('base_url', self::AI_BASE_URL));
        $model = trim((string)$request->input('model', self::AI_MODEL));
        $apiKey = trim((string)$request->input('api_key', ''));
        $logLimit = (int)$request->input('log_limit', 80);
        $blockScore = (int)$request->input('block_score', 80);

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            abort(500, 'API address is invalid');
        }
        if (!$model || strlen($model) > 80) {
            abort(500, 'Model is invalid');
        }

        $config = (array)config('v2board', []);
        $config['ai_risk_enable'] = (int)$request->input('enable', 0) ? 1 : 0;
        $config['ai_risk_provider'] = self::AI_PROVIDER;
        $config['ai_risk_base_url'] = rtrim($baseUrl, '/');
        $config['ai_risk_model'] = $model;
        $config['ai_risk_log_limit'] = max(10, min($logLimit, 200));
        $config['ai_risk_block_score'] = max(50, min($blockScore, 100));

        if ((int)$request->input('clear_api_key', 0)) {
            $config['ai_risk_api_key'] = '';
        } elseif ($apiKey !== '') {
            $config['ai_risk_api_key'] = $apiKey;
        } elseif (!isset($config['ai_risk_api_key'])) {
            $config['ai_risk_api_key'] = '';
        }

        if ($request->has('trusted_proxy_ips')) {
            $config['trusted_proxy_ips'] = $this->normalizeTrustedProxyIps($request->input('trusted_proxy_ips', ''));
        } elseif (!isset($config['trusted_proxy_ips'])) {
            $config['trusted_proxy_ips'] = [];
        }

        if ($request->has('trusted_proxy_cidrs')) {
            $config['trusted_proxy_cidrs'] = $this->normalizeTrustedProxyCidrs($request->input('trusted_proxy_cidrs', ''));
        } elseif (!isset($config['trusted_proxy_cidrs'])) {
            $config['trusted_proxy_cidrs'] = [];
        }

        $this->writeV2boardConfig($config);

        return response([
            'data' => $this->safeAiConfig($config)
        ]);
    }

    public function syncCloudflareIps(Request $request)
    {
        try {
            $config = $this->syncCloudflareIpsToConfig((array)config('v2board', []));
        } catch (Throwable $e) {
            abort(500, 'Cloudflare IP sync failed');
        }

        return response([
            'data' => $this->safeAiConfig($config)
        ]);
    }

    public function aiAnalyze(Request $request)
    {
        $config = $this->openAiConfig((array)config('v2board', []));
        if (empty($config['ai_risk_enable'])) {
            abort(500, 'AI risk analysis is disabled');
        }
        if (empty($config['ai_risk_api_key'])) {
            abort(500, 'Please save OpenAI API Key first');
        }

        $limit = max(10, min((int)($config['ai_risk_log_limit'] ?? 80), 200));
        $logs = SubscriptionRuleLog::with(['rule:id,name,type'])
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            return response([
                'data' => [
                    'summary' => '暂无规则命中日志，AI 暂时没有可分析的数据。',
                    'sample_count' => 0,
                    'generated_at' => time()
                ]
            ]);
        }

        try {
            $content = (new AiRiskService())->analyzeLogs($logs, $config);
        } catch (Throwable $e) {
            abort(500, 'AI analysis failed: ' . $e->getMessage());
        }

        return response([
            'data' => [
                'summary' => $content,
                'sample_count' => $logs->count(),
                'generated_at' => time()
            ]
        ]);
    }

    public function aiTest(Request $request)
    {
        $config = $this->openAiConfig((array)config('v2board', []));
        if (empty($config['ai_risk_enable'])) {
            abort(500, 'AI risk analysis is disabled');
        }
        if (empty($config['ai_risk_api_key'])) {
            abort(500, 'Please save OpenAI API Key first');
        }

        try {
            $result = (new AiRiskService())->testConnection($config);
        } catch (Throwable $e) {
            abort(500, 'AI test failed: ' . $e->getMessage());
        }

        return response([
            'data' => $result
        ]);
    }

    public function save(SubscriptionRuleSave $request)
    {
        $params = $request->validated();

        if ($request->input('id')) {
            $rule = SubscriptionRule::find($request->input('id'));
            if (!$rule) {
                abort(500, 'Rule does not exist');
            }
            $params = $this->normalizeRuleParams($params, $rule->type);
            unset($params['type']);
            if (!$rule->update($params)) {
                abort(500, 'Save failed');
            }
            return response([
                'data' => true
            ]);
        }

        $params = $this->normalizeRuleParams($params, $params['type']);
        if (!SubscriptionRule::create($params)) {
            abort(500, 'Create failed');
        }
        return response([
            'data' => true
        ]);
    }

    private function normalizeRuleParams(array $params, $type)
    {
        $type = (string)$type;
        $defaults = self::RULE_DEFAULTS[$type] ?? null;
        if (!$defaults) {
            abort(500, 'Rule type is invalid');
        }

        if ($defaults['condition'] === null) {
            $params['condition_value'] = null;
            $params['name'] = $defaults['name'];
        } elseif ($type === 'node_alive_ip_over_limit') {
            $params['condition_value'] = 8;
            $params['name'] = sprintf($defaults['name'], 8);
            $params['action'] = 'ai_review';
            $params['remark'] = self::NODE_ALIVE_REMARK;
        } else {
            $condition = $params['condition_value'];
            $condition = $condition === '' || $condition === null ? $defaults['condition'] : (int)$condition;
            $condition = max(1, $condition);
            $params['condition_value'] = $condition;
            $params['name'] = sprintf($defaults['name'], $condition);
        }

        $params['type'] = $type;
        $params['action'] = $this->normalizeAction($params['action']);
        $params['enabled'] = (int)($params['enabled'] ?? 0);
        $params['sort'] = (int)($params['sort'] ?? 0);

        return $params;
    }

    private function normalizeAction($action)
    {
        if (in_array($action, ['empty_subscription', 'block', 'rate_limit'], true)) {
            return 'no_nodes';
        }
        if (in_array($action, ['record', 'notify_admin'], true)) {
            return 'audit';
        }
        return $action;
    }

    private function safeAiConfig($config = null)
    {
        $config = $this->openAiConfig($config ?: (array)config('v2board', []));
        $config = $this->autoSyncCloudflareIps($config);
        $runtimeStatus = $this->aiRuntimeStatus($config);
        $nodeExitIps = (new NodeExitIpService())->snapshot();
        return [
            'enable' => (int)($config['ai_risk_enable'] ?? 0),
            'provider' => $config['ai_risk_provider'] ?? self::AI_PROVIDER,
            'base_url' => $config['ai_risk_base_url'] ?? self::AI_BASE_URL,
            'model' => $config['ai_risk_model'] ?? self::AI_MODEL,
            'log_limit' => (int)($config['ai_risk_log_limit'] ?? 80),
            'block_score' => (int)($config['ai_risk_block_score'] ?? 80),
            'has_api_key' => !empty($config['ai_risk_api_key']),
            'trusted_proxy_ips' => implode("\n", $this->splitConfigList($config['trusted_proxy_ips'] ?? [])),
            'trusted_proxy_cidrs' => implode("\n", $this->splitConfigList($config['trusted_proxy_cidrs'] ?? [])),
            'trusted_proxy_source' => $config['trusted_proxy_source'] ?? '',
            'trusted_proxy_synced_at' => (int)($config['trusted_proxy_synced_at'] ?? 0),
            'node_exit_ips' => $nodeExitIps,
            'runtime_status' => $runtimeStatus
        ];
    }

    private function autoSyncCloudflareIps(array $config)
    {
        $syncedAt = (int)($config['trusted_proxy_synced_at'] ?? 0);
        if ($syncedAt > time() - self::CLOUDFLARE_SYNC_INTERVAL) {
            return $config;
        }

        $lastFailureAt = (int)Cache::get(self::CLOUDFLARE_SYNC_FAILURE_CACHE_KEY, 0);
        if ($lastFailureAt > time() - self::CLOUDFLARE_SYNC_FAILURE_RETRY) {
            return $config;
        }

        try {
            return $this->syncCloudflareIpsToConfig($config);
        } catch (Throwable $e) {
            Cache::put(self::CLOUDFLARE_SYNC_FAILURE_CACHE_KEY, time(), self::CLOUDFLARE_SYNC_FAILURE_RETRY);
            return $config;
        }
    }

    private function syncCloudflareIpsToConfig(array $config)
    {
        $content = @file_get_contents(self::CLOUDFLARE_IPS_URL, false, stream_context_create([
            'http' => [
                'timeout' => 8
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]));

        if ($content === false || trim($content) === '') {
            throw new \RuntimeException('Cloudflare IP sync failed');
        }

        $cidrs = $this->normalizeTrustedProxyCidrs($content);
        if (count($cidrs) < 1) {
            throw new \RuntimeException('Cloudflare IP list is empty');
        }

        $config['trusted_proxy_cidrs'] = $cidrs;
        $config['trusted_proxy_source'] = 'cloudflare';
        $config['trusted_proxy_synced_at'] = time();
        if (!isset($config['trusted_proxy_ips'])) {
            $config['trusted_proxy_ips'] = [];
        }

        $this->writeV2boardConfig($config);
        Cache::forget(self::CLOUDFLARE_SYNC_FAILURE_CACHE_KEY);

        return $config;
    }

    private function aiRuntimeStatus(array $config)
    {
        if (empty($config['ai_risk_enable'])) {
            return $this->makeAiRuntimeStatus(false, 'disabled');
        }
        if (empty($config['ai_risk_api_key'])) {
            return $this->makeAiRuntimeStatus(false, 'missing_key');
        }

        $event = $this->latestAiRuntimeEvent();
        if (!$event) {
            return $this->makeAiRuntimeStatus(false, 'no_recent_run');
        }

        if ((int)$event['checked_at'] < time() - 86400) {
            return $this->makeAiRuntimeStatus(false, 'no_recent_run', $event);
        }

        return $this->makeAiRuntimeStatus($event['status'] === 'running', $event['reason'], $event);
    }

    private function latestAiRuntimeEvent()
    {
        $events = [];
        $cached = Cache::get(self::AI_RUNTIME_CACHE_KEY);
        if (is_array($cached) && !empty($cached['checked_at'])) {
            $events[] = [
                'status' => !empty($cached['running']) ? 'running' : 'not_running',
                'reason' => $cached['reason'] ?? '',
                'checked_at' => (int)$cached['checked_at'],
                'source' => $cached['source'] ?? 'runtime'
            ];
        }

        try {
            $latestLog = SubscriptionRuleLog::where('action', 'ai_review')
                ->whereNotNull('ai_decision')
                ->orderBy('id', 'DESC')
                ->first(['created_at', 'ai_decision', 'ai_score', 'ai_reason']);
            if ($latestLog) {
                $reason = (string)$latestLog->ai_reason;
                $events[] = [
                    'status' => $this->isAiRuntimeFailureReason($reason) ? 'not_running' : 'running',
                    'reason' => $this->isAiRuntimeFailureReason($reason) ? 'last_call_failed' : 'last_review_success',
                    'checked_at' => (int)$latestLog->created_at,
                    'source' => 'review_log'
                ];
            }
        } catch (Throwable $e) {
            $events[] = [
                'status' => 'not_running',
                'reason' => 'status_read_failed',
                'checked_at' => time(),
                'source' => 'runtime'
            ];
        }

        if (!$events) {
            return null;
        }

        usort($events, function ($a, $b) {
            return (int)$b['checked_at'] <=> (int)$a['checked_at'];
        });
        return $events[0];
    }

    private function makeAiRuntimeStatus($running, $reason, ?array $event = null)
    {
        return [
            'running' => (bool)$running,
            'status' => $running ? 'running' : 'not_running',
            'label' => $running ? '已运行' : '未运行',
            'reason' => (string)$reason,
            'last_checked_at' => $event ? (int)$event['checked_at'] : 0,
            'source' => $event['source'] ?? ''
        ];
    }

    private function isAiRuntimeFailureReason($reason)
    {
        $reason = strtolower((string)$reason);
        return $reason === ''
            || strpos($reason, 'ai is not enabled') !== false
            || strpos($reason, 'api key is missing') !== false
            || strpos($reason, 'ai failed') !== false
            || strpos($reason, 'invalid api key') !== false
            || strpos($reason, 'incorrect api key') !== false
            || strpos($reason, 'ai不可用') !== false
            || strpos($reason, 'key') !== false && strpos($reason, 'missing') !== false;
    }

    private function openAiConfig(array $config)
    {
        $provider = strtolower((string)($config['ai_risk_provider'] ?? self::AI_PROVIDER));
        $baseUrl = strtolower((string)($config['ai_risk_base_url'] ?? ''));
        $model = strtolower((string)($config['ai_risk_model'] ?? ''));
        $isLegacyGemini = $provider !== self::AI_PROVIDER
            || strpos($baseUrl, 'generativelanguage.googleapis.com') !== false
            || strpos($model, 'gemini') !== false;

        if ($isLegacyGemini) {
            $config['ai_risk_provider'] = self::AI_PROVIDER;
            $config['ai_risk_base_url'] = self::AI_BASE_URL;
            $config['ai_risk_model'] = self::AI_MODEL;
            $config['ai_risk_api_key'] = '';
        }

        return $config;
    }

    private function normalizeTrustedProxyIps($value)
    {
        $items = $this->splitConfigList($value);
        foreach ($items as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                abort(500, 'Trusted proxy IP is invalid: ' . $ip);
            }
        }
        return $items;
    }

    private function normalizeTrustedProxyCidrs($value)
    {
        $items = $this->splitConfigList($value);
        foreach ($items as $cidr) {
            if (!$this->isValidIpv4Cidr($cidr)) {
                abort(500, 'Trusted proxy CIDR is invalid: ' . $cidr);
            }
        }
        return $items;
    }

    private function splitConfigList($value)
    {
        if (is_array($value)) {
            $value = implode("\n", $value);
        }

        $parts = preg_split('/[\r\n,;\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $items = [];
        foreach ($parts ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $items, true)) {
                $items[] = $part;
            }
        }
        return $items;
    }

    private function isValidIpv4Cidr($cidr)
    {
        $parts = explode('/', (string)$cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $mask = $parts[1];
        if (!ctype_digit($mask)) {
            return false;
        }

        $mask = (int)$mask;
        return $mask >= 0
            && $mask <= 32
            && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    private function writeV2boardConfig(array $config)
    {
        $data = var_export($config, true);
        if (!File::put(base_path() . '/config/v2board.php', "<?php\n return $data ;")) {
            abort(500, 'Save failed');
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        Artisan::call('config:cache');
    }

    private function buildAiRiskPayload($logs)
    {
        $rules = [];
        $ips = [];
        $uas = [];
        $samples = [];

        foreach ($logs as $log) {
            $ruleType = $log->rule_type ?: ($log->rule ? $log->rule->type : 'unknown');
            $ruleName = $log->rule ? $log->rule->name : '';
            $ip = $this->maskIp($log->client_ip);
            $ua = $this->trimText((string)$log->user_agent, 140);

            $rules[$ruleType] = ($rules[$ruleType] ?? 0) + 1;
            if ($ip) {
                $ips[$ip] = ($ips[$ip] ?? 0) + 1;
            }
            if ($ua) {
                $uas[$ua] = ($uas[$ua] ?? 0) + 1;
            }
            if (count($samples) < 20) {
                $samples[] = [
                    'rule_type' => $ruleType,
                    'rule_name' => $ruleName,
                    'action' => $log->action,
                    'reason' => $log->reason,
                    'matched_value' => $this->trimText((string)$log->matched_value, 120),
                    'client_ip_range' => $ip,
                    'proxy_ip_range' => $this->maskIp($log->proxy_ip),
                    'user_agent' => $ua,
                    'flag' => $this->trimText((string)$log->flag, 60),
                    'created_at' => $log->created_at
                ];
            }
        }

        arsort($rules);
        arsort($ips);
        arsort($uas);

        return [
            'sample_count' => $logs->count(),
            'top_rules' => array_slice($rules, 0, 10, true),
            'top_client_ip_ranges' => array_slice($ips, 0, 10, true),
            'top_user_agents' => array_slice($uas, 0, 10, true),
            'samples' => $samples
        ];
    }

    private function callAiRiskModel(array $config, array $riskPayload)
    {
        $baseUrl = rtrim($config['ai_risk_base_url'] ?? self::AI_BASE_URL, '/');
        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false
        ]);

        $response = $client->post($baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['ai_risk_api_key'],
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'model' => $config['ai_risk_model'] ?? self::AI_MODEL,
                'max_completion_tokens' => 900,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => '你是订阅面板的风控分析助手。只能根据脱敏日志判断风险，不能要求封禁真实用户，不能输出完整 IP、邮箱、token 或节点信息。'
                    ],
                    [
                        'role' => 'user',
                        'content' => "请用中文分析以下订阅规则命中日志，输出：1）风险概览；2）最可疑的 3 个模式；3）建议启用或调整的规则；4）需要人工确认的点。不要编造日志中没有的信息。\n\n" . json_encode($riskPayload, JSON_UNESCAPED_UNICODE)
                    ]
                ]
            ]
        ]);

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if ($response->getStatusCode() >= 400) {
            $message = $data['error']['message'] ?? ('HTTP ' . $response->getStatusCode());
            throw new \RuntimeException($message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!$content) {
            throw new \RuntimeException('empty response');
        }
        return $content;
    }

    private function maskIp($ip)
    {
        $ip = trim((string)$ip);
        if (!$ip) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::/64';
        }
        return $this->trimText($ip, 45);
    }

    private function trimText($text, $length)
    {
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length);
        }
        return substr($text, 0, $length);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, 'Invalid parameter');
        }
        $rule = SubscriptionRule::find($request->input('id'));
        if (!$rule) {
            abort(500, 'Rule does not exist');
        }
        $rule->enabled = $rule->enabled ? 0 : 1;
        if (!$rule->save()) {
            abort(500, 'Save failed');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, 'Invalid parameter');
        }
        $rule = SubscriptionRule::find($request->input('id'));
        if (!$rule) {
            abort(500, 'Rule does not exist');
        }
        if (!$rule->delete()) {
            abort(500, 'Delete failed');
        }

        return response([
            'data' => true
        ]);
    }
}
