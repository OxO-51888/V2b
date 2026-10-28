<?php

namespace App\Modules\Security;

use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

trait SubscriptionAiActions
{

    public function analyzeLogs($logs, array $config)
    {
        $config = $this->openAiConfig($config);
        try {
            $content = $this->callModel($config, [
                [
                    'role' => 'system',
                    'content' => 'You are a subscription panel risk-analysis assistant. Analyze only the sanitized logs. Do not output full IPs, emails, tokens, or node data.'
                ],
                [
                    'role' => 'user',
                    'content' => "Please answer in Chinese. Analyze these subscription rule hit logs and output: 1) risk overview; 2) top 3 suspicious patterns; 3) rules worth enabling or adjusting; 4) points requiring manual confirmation. Do not invent facts.\n\n" . json_encode($this->buildLogPayload($logs), JSON_UNESCAPED_UNICODE)
                ]
            ], 30, 2000);
            $this->markRuntimeStatus(true, 'analysis_success');
            return $content;
        } catch (\Throwable $exception) {
            $this->markRuntimeStatus(false, 'analysis_failed: ' . $exception->getMessage());
            throw $exception;
        }
    }

    public function testConnection(array $config)
    {
        $config = $this->openAiConfig($config);
        try {
            $content = $this->callModel($config, [
                [
                    'role' => 'system',
                    'content' => 'Return only compact JSON. Do not include secrets.'
                ],
                [
                    'role' => 'user',
                    'content' => 'Reply exactly with {"ok":true,"message":"AI test passed"}'
                ]
            ], 20, 64);
            $this->markRuntimeStatus(true, 'test_success');

            return [
                'ok' => true,
                'model' => $config['ai_risk_model'] ?? self::OPENAI_MODEL,
                'message' => $this->trimText($content, 160),
                'tested_at' => time()
            ];
        } catch (\Throwable $exception) {
            $this->markRuntimeStatus(false, 'test_failed: ' . $exception->getMessage());
            throw $exception;
        }
    }

    public function reviewSubscriptionRequest(Request $request, User $user, SubscriptionRule $rule, $reason, $matchedValue = '')
    {
        $floor = $this->enforceRuleFloor($this->decision('allow', 0, '', false), $request, $rule);
        if ($floor['block']) {
            $floor['source'] = 'local_rule';
            $floor['reason'] = '本地规则：' . $floor['reason'];
            return $floor;
        }
        $config = $this->openAiConfig((array)config('v2board', []));
        if (empty($config['ai_risk_enable']) || empty($config['ai_risk_api_key'])) {
            $this->markRuntimeStatus(false, 'not_configured');
            $decision = $this->decision('allow', 0, 'AI is not enabled or API key is missing', false);
            return $this->enforceRuleFloor($decision, $request, $rule);
        }

        $cacheKey = 'AI_SUB_REVIEW_' . hash('sha256', (string)($config['ai_risk_model'] ?? self::OPENAI_MODEL)) . '_' . md5(implode('|', [
            $user->id,
            $user->token,
            $rule->id,
            $reason,
            $matchedValue,
            $this->clientIp($request),
            (string)$request->header('User-Agent', ''),
            (string)$request->input('flag', '')
        ]));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }

        $lockKey = $cacheKey . '_INFLIGHT';
        $lockOwner = hash('sha256', uniqid('', true) . mt_rand());
        if (!Cache::add($lockKey, $lockOwner, 20)) {
            $cached = $this->waitForCachedReviewDecision($cacheKey, 100);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }

            $decision = $this->decision('allow', 0, 'AI review is already in progress', false);
            return $this->enforceRuleFloor($decision, $request, $rule);
        }

        $slot = null;
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }

            // Bound synchronous AI work per site without queuing subscription requests.
            for ($index = 0; $index < 2; $index++) {
                $candidate = Cache::lock('AI_SUB_REVIEW_SLOT_' . hash('sha256', base_path()) . '_' . $index, 15);
                if ($candidate->get()) {
                    $slot = $candidate;
                    break;
                }
            }
            if ($slot === null) {
                return $this->decision('allow', 0, 'AI review is busy', false);
            }

            $payload = $this->buildRealtimePayload($request, $user, $rule, $reason, $matchedValue);
            $content = $this->callModel($config, [
                [
                    'role' => 'system',
                    'content' => 'You are a realtime subscription risk engine. Return compact JSON only: {"decision":"allow|block","risk_score":0-100,"reason":"short Chinese reason"}. decision must be exactly allow or block. Use 0-100 scale. The query flag is user-controlled and can be forged; never treat flag=clash, flag=shadowrocket, or similar as proof of a normal client. Trust request_context.known_proxy_user_agent and the actual User-Agent more than flag. Known proxy clients include Shadowrocket, Clash, Mihomo, Sing-box, V2RayN, V2RayNG, Surge, Loon, Stash, Quantumult, FlowZ, Hiddify, and Karing. If current_hit.rule_type is pull_frequency and request_context.known_proxy_user_agent is true, this is usually a proxy app refresh/retry; allow unless recent_same_user_hits show clear credential sharing, many unrelated IP ranges, scanner/client mismatch, or repeated hard blocks. If actual User-Agent is browser Chrome/Safari/Firefox/Edge or social/webview and request_context.known_proxy_user_agent is false, block when current_hit.rule_type is ua_browser, ua_social, or header_browser_context, even if flag claims a proxy client. If the actual User-Agent is curl, wget, httpie, PowerShell, python-requests, Go-http-client, Postman, browser Chrome/Safari/Firefox/Edge, Telegram/Wechat/QQ webview, scanner Censys/Shodan/zgrab/nmap, or empty, block when current_hit.rule_type confirms that evidence. If current_hit.rule_type is ua_cli_fetch and User-Agent is curl/wget/httpie/PowerShell, score 90-100 and block. If rule_type is ua_api_fetch and User-Agent is python-requests/Go-http-client/Postman/axios, score 90-100 and block. If rule_type is ua_scanner, score 95-100 and block. If rule_type is node_alive_ip_over_limit, this event comes from a trusted node backend report. request.reporter_user_agent and request.reporter_ip_range identify the reporting server, never the customer or customer app, and must not increase risk. The rule reaches AI only after three consecutive two-minute windows with at least three stable broad network groups. Block with score 90-100 only when the aggregate evidence and recent_same_user_hits strongly indicate credential sharing or clearly non-household use. Mobile carrier address churn inside one broad network group, a small household, router, network switching, and normal multi-device use must be allowed. If rule_type is direct_ip_host or head_method_probe, trust the current_hit evidence and block when it indicates direct-IP access or probing. If evidence is weak or AI is unsure, allow with score below 80. For block decisions, the reason must describe why the subscription was refused; do not use suggestion or recommendation wording such as 建议. Never include full IPs, emails, tokens, or node data.'
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ]
            ], 5, 2048);
            $this->markRuntimeStatus(true, 'review_success');
            $decision = $this->parseDecision($content, $this->blockScoreForRule($config, $rule));
            $decision = $this->enforceRuleFloor($decision, $request, $rule);
        } catch (\Throwable $exception) {
            $this->markRuntimeStatus(false, 'review_failed');
            $decision = $this->decision('allow', 0, 'AI failed: review unavailable', false);
            $decision = $this->enforceRuleFloor($decision, $request, $rule);
            if (!empty($decision['block'])) {
                $decision['reason'] = 'AI不可用，按高危规则拦截';
            }
        } finally {
            if ($slot !== null) $slot->release();
            if (isset($decision) && is_array($decision)) {
                Cache::put($cacheKey, $decision, 300);
            }
            $this->releaseReviewLock($lockKey, $lockOwner);
        }

        return $decision;
    }

    private function waitForCachedReviewDecision($cacheKey, $waitMilliseconds)
    {
        $deadline = microtime(true) + (max(0, (int)$waitMilliseconds) / 1000);
        do {
            usleep(100000);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    private function releaseReviewLock($lockKey, $lockOwner)
    {
        $currentOwner = Cache::get($lockKey);
        if (is_string($currentOwner) && hash_equals($currentOwner, (string)$lockOwner)) {
            Cache::forget($lockKey);
        }
    }

    private function buildLogPayload($logs)
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

    private function buildRealtimePayload(Request $request, User $user, SubscriptionRule $rule, $reason, $matchedValue)
    {
        $ua = (string)$request->header('User-Agent', '');
        $flag = (string)$request->input('flag', '');
        $isNodeAliveReport = $rule->type === 'node_alive_ip_over_limit';
        $history = SubscriptionRuleLog::where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->limit(12)
            ->get()
            ->map(function ($log) {
                $isNodeAliveReport = $log->rule_type === 'node_alive_ip_over_limit';
                return [
                    'rule_type' => $log->rule_type,
                    'action' => $log->action,
                    'reason' => $log->reason,
                    'matched_value' => $this->trimText((string)$log->matched_value, 90),
                    'client_ip_range' => $isNodeAliveReport ? '' : $this->maskIp($log->client_ip),
                    'user_agent' => $isNodeAliveReport ? '' : $this->trimText((string)$log->user_agent, 120),
                    'event_source' => $isNodeAliveReport ? 'node_backend_report' : 'subscription_request',
                    'created_at' => $log->created_at
                ];
            })
            ->values()
            ->all();

        return [
            'current_hit' => [
                'rule_name' => $rule->name,
                'rule_type' => $rule->type,
                'reason' => $reason,
                'matched_value' => $this->trimText((string)$matchedValue, 120),
                'rule_threshold' => $rule->condition_value
            ],
            'request' => [
                'client_ip_range' => $isNodeAliveReport ? '' : $this->maskIp($this->clientIp($request)),
                'proxy_ip_range' => $isNodeAliveReport ? '' : $this->maskIp(SubscriptionClientIp::peer($request)),
                'x_forwarded_for_ranges' => $isNodeAliveReport || !SubscriptionClientIp::trusted(SubscriptionClientIp::peer($request))
                    ? [] : $this->maskIpList(substr((string)$request->header('X-Forwarded-For', ''), 0, 4096)),
                'user_agent' => $isNodeAliveReport ? '' : $this->trimText($ua, 180),
                'flag' => $isNodeAliveReport ? '' : $this->trimText($flag, 80),
                'reporter_ip_range' => $isNodeAliveReport ? $this->maskIp(SubscriptionClientIp::peer($request)) : '',
                'reporter_user_agent' => $isNodeAliveReport ? $this->trimText($ua, 180) : '',
                'path' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'referer_present' => $isNodeAliveReport ? false : ($request->header('referer') ? true : false),
                'accept' => $isNodeAliveReport ? '' : $this->trimText((string)$request->header('accept', ''), 120)
            ],
            'request_context' => [
                'event_source' => $isNodeAliveReport ? 'node_backend_report' : 'subscription_request',
                'known_proxy_user_agent' => $isNodeAliveReport ? false : $this->hasProxyClientUa(strtolower($ua)),
                'flag_claims_proxy_client' => $isNodeAliveReport ? false : $this->flagClaimsProxyClient($flag),
                'has_browser_context_header' => $isNodeAliveReport ? false : $this->hasBrowserContextHeader($request),
                'browser_context_header' => $isNodeAliveReport ? '' : $this->browserContextHeader($request),
                'flag_user_agent_mismatch' => $isNodeAliveReport
                    ? false
                    : ($this->flagClaimsProxyClient($flag) && !$this->hasProxyClientUa(strtolower($ua))),
            ],
            'user_snapshot' => [
                'traffic_status' => $this->trafficStatus($user),
                'recent_rule_hits' => count($history)
            ],
            'recent_same_user_hits' => $history
        ];
    }

    private function blockScoreForRule(array $config, SubscriptionRule $rule)
    {
        $blockScore = max(50, min((int)($config['ai_risk_block_score'] ?? 80), 100));
        if ($rule->type === 'node_alive_ip_over_limit') {
            return max(90, $blockScore);
        }
        return $blockScore;
    }

    protected function callModel(array $config, array $messages, $timeout, $maxTokens)
    {
        $deadline = microtime(true) + $timeout;
        $config = $this->openAiConfig($config);
        $baseUrl = rtrim($config['ai_risk_base_url'] ?? self::OPENAI_BASE_URL, '/');
        $client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'http_errors' => false
        ]);

        $json = [
            'model' => $config['ai_risk_model'] ?? self::OPENAI_MODEL,
            'max_completion_tokens' => $maxTokens,
            'messages' => $messages
        ];

        if (strtolower((string)$json['model']) === 'gpt-6-luna') {
            $json['reasoning_effort'] = 'low';
            $json['verbosity'] = 'low';
        } elseif (strpos(strtolower((string)$json['model']), 'gpt-5') === 0) {
            $json['reasoning_effort'] = 'minimal';
            $json['verbosity'] = 'low';
        }

        $response = $client->post($baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['ai_risk_api_key'],
                'Content-Type' => 'application/json'
            ],
            'json' => $json
        ]);

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if ($response->getStatusCode() >= 400 && $this->shouldRetryWithLegacyMaxTokens($data)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('AI request timed out');
            unset($json['max_completion_tokens']);
            $json['max_tokens'] = $maxTokens;
            $response = $client->post($baseUrl . '/chat/completions', [
                'timeout' => $remaining,
                'connect_timeout' => min(5, $remaining),
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['ai_risk_api_key'],
                    'Content-Type' => 'application/json'
                ],
                'json' => $json
            ]);
            $body = (string)$response->getBody();
            $data = json_decode($body, true);
        }
        if ($response->getStatusCode() >= 400) {
            $message = $data['error']['message'] ?? ('HTTP ' . $response->getStatusCode());
            throw new RuntimeException($message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!$content) {
            throw new RuntimeException('empty response');
        }
        return $content;
    }

    private function parseDecision($content, $blockScore)
    {
        $json = trim((string)$content);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim($matches[1]);
        } elseif (preg_match('/\{.*\}/s', $json, $matches)) {
            $json = $matches[0];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid AI decision JSON');
        }

        $score = max(0, min((int)($data['risk_score'] ?? 0), 100));
        $decision = strtolower((string)($data['decision'] ?? 'allow'));
        if (!in_array($decision, ['allow', 'block'], true)) {
            $decision = 'allow';
        }
        $decision = $score >= $blockScore ? 'block' : 'allow';

        $reason = $this->trimText((string)($data['reason'] ?? ''), 240);
        if ($decision === 'block') {
            $reason = $this->normalizeBlockReason($reason);
        }

        return $this->decision(
            $decision,
            $score,
            $reason,
            $decision === 'block'
        );
    }

    private function normalizeBlockReason($reason)
    {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return '已拒绝下发订阅';
        }

        $reason = str_replace(
            ['建议重置订阅凭证', '建议重置订阅', '建议重置凭证', '建议拒绝', '建议拦截'],
            ['已拒绝下发订阅，需重置订阅凭证', '已拒绝下发订阅，需重置订阅凭证', '已拒绝下发订阅，需重置凭证', '已拒绝下发订阅', '已拒绝下发订阅'],
            $reason
        );
        $reason = str_replace(['建议', '可考虑', '请考虑'], '', $reason);
        $reason = preg_replace('/\s+/u', ' ', $reason) ?: $reason;

        if (strpos($reason, '拒绝') === false && strpos($reason, '拦截') === false) {
            $reason = '已拒绝下发订阅：' . $reason;
        }

        return $this->trimText($reason, 240);
    }

    private function decision($decision, $score, $reason, $block)
    {
        return [
            'decision' => $decision,
            'risk_score' => (int)$score,
            'reason' => $reason,
            'block' => (bool)$block,
            'cached' => false
        ];
    }

    private function enforceRuleFloor(array $decision, Request $request, SubscriptionRule $rule)
    {
        $ua = strtolower((string)$request->header('User-Agent', ''));
        $type = $rule->type;
        $hasProxyClientUa = $this->hasProxyClientUa($ua);
        $floor = null;

        if (in_array($type, ['ua_scanner'], true)) {
            $floor = 98;
        } elseif (in_array($type, ['ua_cli_fetch', 'ua_api_fetch', 'empty_user_agent'], true) && !$hasProxyClientUa) {
            $floor = 95;
        } elseif (in_array($type, ['ua_browser', 'ua_social', 'header_browser_context'], true) && !$hasProxyClientUa) {
            $floor = 88;
        } elseif (in_array($type, ['direct_ip_host', 'head_method_probe'], true)) {
            $floor = 88;
        } elseif (in_array($type, ['converter_query', 'untrusted_proxy_header'], true) && !$hasProxyClientUa) {
            $floor = 85;
        }

        if ($floor !== null && (int)($decision['risk_score'] ?? 0) < $floor) {
            $decision['decision'] = 'block';
            $decision['risk_score'] = $floor;
            $decision['reason'] = $this->ruleFloorReason($type, $hasProxyClientUa);
            $decision['block'] = true;
        }

        return $decision;
    }

    private function ruleFloorReason($type, $hasProxyClientUa)
    {
        switch ($type) {
            case 'direct_ip_host':
                return '已拒绝下发订阅：请求使用服务器IP、本地Host或空Host访问订阅，不是正常域名入口';
            case 'head_method_probe':
                return '已拒绝下发订阅：请求使用HEAD/OPTIONS探测订阅接口';
            case 'ua_scanner':
                return '已拒绝下发订阅：真实User-Agent命中扫描器特征';
            case 'ua_cli_fetch':
                return '已拒绝下发订阅：真实User-Agent命中命令行抓取工具';
            case 'ua_api_fetch':
                return '已拒绝下发订阅：真实User-Agent命中接口抓取工具';
            case 'empty_user_agent':
                return '已拒绝下发订阅：请求缺少User-Agent';
            case 'ua_browser':
            case 'ua_social':
            case 'header_browser_context':
                if ($hasProxyClientUa) {
                    return '已拒绝下发订阅：代理客户端请求同时携带异常浏览器上下文';
                }
                return '已拒绝下发订阅：真实User-Agent或请求头表现为浏览器/内置浏览器，flag不可作为放行依据';
            case 'converter_query':
                return '已拒绝下发订阅：请求携带订阅转换器参数';
            case 'untrusted_proxy_header':
                return '已拒绝下发订阅：请求携带不可信转发头';
            default:
                return '已拒绝下发订阅：命中高危风控规则';
        }
    }

    private function hasProxyClientUa($ua)
    {
        return SubscriptionClientUserAgent::known((string)$ua);
    }

    private function flagClaimsProxyClient($flag)
    {
        $flag = strtolower((string)$flag);
        foreach ([
            'shadowrocket',
            'clash',
            'meta',
            'mihomo',
            'sing-box',
            'singbox',
            'v2ray',
            'v2rayn',
            'v2rayng',
            'surge',
            'loon',
            'stash',
            'quantumult',
            'quanx',
            'flowz',
            'hiddify',
            'karing',
        ] as $needle) {
            if (strpos($flag, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function hasBrowserContextHeader(Request $request)
    {
        return (bool)($request->header('sec-fetch-site')
            || $request->header('sec-fetch-mode')
            || $request->header('sec-fetch-dest')
            || $request->header('sec-fetch-user')
            || $request->header('referer'));
    }

    private function browserContextHeader(Request $request)
    {
        foreach (['sec-fetch-site', 'sec-fetch-mode', 'sec-fetch-dest', 'sec-fetch-user', 'referer'] as $header) {
            if ($request->header($header)) {
                return $header;
            }
        }
        return '';
    }

    private function openAiConfig(array $config)
    {
        $provider = strtolower((string)($config['ai_risk_provider'] ?? 'openai'));
        $baseUrl = strtolower((string)($config['ai_risk_base_url'] ?? ''));
        $model = strtolower((string)($config['ai_risk_model'] ?? ''));
        $isLegacyGemini = $provider !== 'openai'
            || strpos($baseUrl, 'generativelanguage.googleapis.com') !== false
            || strpos($model, 'gemini') !== false;

        if ($isLegacyGemini) {
            $config['ai_risk_provider'] = 'openai';
            $config['ai_risk_base_url'] = self::OPENAI_BASE_URL;
            $config['ai_risk_model'] = self::OPENAI_MODEL;
            $config['ai_risk_api_key'] = '';
        }

        return $config;
    }

    private function trafficStatus(User $user)
    {
        $total = (int)$user->transfer_enable;
        if ($total <= 0) {
            return 'unknown';
        }

        $ratio = (($user->u + $user->d) / $total) * 100;
        if ($ratio >= 100) {
            return 'over_limit';
        }
        if ($ratio >= 80) {
            return 'near_limit';
        }
        return 'normal';
    }

    private function clientIp(Request $request)
    {
        return SubscriptionClientIp::resolve($request);
    }

    private function maskIpList($value)
    {
        $items = [];
        foreach (explode(',', $value) as $part) {
            $masked = $this->maskIp(trim($part));
            if ($masked) {
                $items[] = $masked;
            }
        }
        return implode(',', array_unique($items));
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
            return inet_ntop(substr(inet_pton($ip), 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $this->trimText($ip, 45);
    }
}
