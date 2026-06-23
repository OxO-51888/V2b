<?php

namespace App\Services;

use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SubscriptionRuleService
{
    private const NODE_ALIVE_AI_REVIEW_LIMIT = 8;
    private const NODE_ALIVE_RESET_LIMIT = 15;

    private const GOOD_UA = [
        'clash',
        'clash meta',
        'clashmeta',
        'clash-verge',
        'clashverge',
        'clash verge',
        'clash for windows',
        'clashforwindows',
        'clashforandroid',
        'cfw',
        'mihomo',
        'metacubex',
        'sing-box',
        'singbox',
        'sfa',
        'sfi',
        'v2ray',
        'v2rayn',
        'v2rayng',
        'v2raytun',
        'xray',
        'shadowrocket',
        'quantumult',
        'quantumultx',
        'quantumult x',
        'quanx',
        'loon',
        'karing',
        'loonlite',
        'stash',
        'surge',
        'surfboard',
        'flowz',
        'nekobox',
        'nekoray',
        'hiddify',
        'hiddifynext',
        'anxray',
        'kitsunebi',
        'flclash',
        'clashnyanpasu',
        'clash-nyanpasu',
        'v2box',
        'qv2ray',
        'openclash',
        'passwall',
        'passwall2',
        'homeproxy',
        'trojan',
        'trojan-go',
        'naive',
        'tuic',
        'hysteria',
        'hysteria2',
        'hy2',
    ];

    private const SCANNER_UA = [
        'censys',
        'censysinspect',
        'shodan',
        'zgrab',
        'masscan',
        'nmap',
        'nikto',
        'sqlmap',
        'nuclei',
        'httpx',
        'wafw00f',
        'dirsearch',
        'gobuster',
        'feroxbuster',
        'acunetix',
        'nessus',
        'openvas',
        'netsparker',
        'arachni',
    ];

    private const SOCIAL_UA = [
        'wechat',
        'weixin',
        'qq',
        'qqbrowser',
        'tim',
        'tencent',
        'telegram',
        'discord',
        'facebook',
        'instagram',
        'whatsapp',
        'messenger',
        'line',
        'twitter',
        'x.com',
        'reddit',
        'douyin',
        'aweme',
        'toutiao',
        'kuaishou',
        'xiaohongshu',
        'xhs',
        'weibo',
        'bilibili',
    ];

    private const BROWSER_UA = [
        'mozilla',
        'chrome',
        'crios',
        'safari',
        'firefox',
        'edge',
        'edg',
        'opera',
        'opr',
        'brave',
        'vivaldi',
        'duckduckgo',
        'ucbrowser',
        'sogoumobilebrowser',
        '360browser',
        'baidubrowser',
        'maxthon',
    ];

    private const CLI_FETCH_UA = [
        'curl',
        'wget',
        'httpie',
        'aria2',
        'powershell',
    ];

    private const API_FETCH_UA = [
        'bang2013',
        'python-requests',
        'python-urllib',
        'aiohttp',
        'java/',
        'java-http-client',
        'apache-httpclient',
        'okhttp',
        'go-http-client',
        'postman',
        'insomnia',
        'node-fetch',
        'axios',
        'undici',
        'reqwest',
    ];

    private const CONVERTER_UA = [
        'subconverter',
        'sub-store',
        'substore',
        'subweb',
        'subscription-userinfo',
    ];

    private const VENDOR_UA = [
        'miui',
        'xiaomi',
        'huawei',
        'honor',
        'harmonyos',
        'oppo',
        'vivo',
        'oneplus',
        'smartisan',
        'alipay',
        'taobao',
        'tmall',
        'jd',
        'jingdong',
        'pinduoduo',
        'pdd',
        'unionpay',
        'baidu',
        'baiduboxapp',
        'meituan',
        'dianping',
        'eleme',
    ];

    private const CONVERTER_QUERY_KEYS = [
        'target',
        'url',
        'config',
        'upload',
        'upload_path',
        'emoji',
        'append_type',
        'include',
        'exclude',
        'rename',
        'filter_script',
        'sort_script',
        'ruleset',
        'groups',
    ];

    public function guardSubscribe(Request $request, User $user)
    {
        $frequencyResponse = $this->guardPullFrequency($request, $user);
        if ($frequencyResponse) {
            return $frequencyResponse;
        }

        $ipResponse = $this->guardIpSpread($request, $user);
        if ($ipResponse) {
            return $ipResponse;
        }

        $ipMultiUserResponse = $this->guardIpMultiUser($request, $user);
        if ($ipMultiUserResponse) {
            return $ipMultiUserResponse;
        }

        $contextResponse = $this->guardRequestContext($request, $user);
        if ($contextResponse) {
            return $contextResponse;
        }

        $shapeResponse = $this->guardRequestShape($request, $user);
        if ($shapeResponse) {
            return $shapeResponse;
        }

        return $this->guardUserAgent($request, $user);
    }

    public function guardNodeAliveIp(Request $request, $userId, array $aliveData, $aliveIpCount, $nodeType, $nodeId)
    {
        $rule = $this->firstEnabledRule(['node_alive_ip_over_limit']);
        if (!$rule) {
            return null;
        }

        $aliveIpCount = (int)$aliveIpCount;
        if ($aliveIpCount <= self::NODE_ALIVE_AI_REVIEW_LIMIT) {
            return null;
        }

        $user = User::find((int)$userId);
        if (!$user) {
            return null;
        }

        $action = $aliveIpCount > self::NODE_ALIVE_RESET_LIMIT ? 'reset_subscribe' : 'ai_review';
        $dedupeKey = 'SUB_RULE_NODE_ALIVE_' . $rule->id . '_' . $user->id . '_' . $action;
        if (!Cache::add($dedupeKey, time(), 600)) {
            return null;
        }

        $matchedValue = $this->summarizeAliveIps(
            $aliveData,
            $aliveIpCount,
            (string)$nodeType,
            (string)$nodeId,
            self::NODE_ALIVE_AI_REVIEW_LIMIT,
            self::NODE_ALIVE_RESET_LIMIT
        );
        return $this->applyNodeAction($rule, $request, $user, 'node_alive_ip_over_limit', $matchedValue, $action);
    }

    private function guardPullFrequency(Request $request, User $user)
    {
        $rule = $this->firstEnabledRule(['pull_frequency']);
        if (!$rule) {
            return null;
        }

        $limit = (int)($rule->condition_value ?: 30);
        $key = 'SUB_RULE_PULL_COUNT_' . $user->id . '_' . $user->token;
        Cache::add($key, 0, 300);
        $count = Cache::increment($key);

        if ($count > $limit) {
            return $this->applyAction($rule, $request, $user, 'pull_frequency');
        }

        return null;
    }

    private function guardIpSpread(Request $request, User $user)
    {
        $rule = $this->firstEnabledRule(['ip_spread']);
        if (!$rule) {
            return null;
        }

        $limit = (int)($rule->condition_value ?: 8);
        $key = 'SUB_RULE_IP_SPREAD_' . $user->id . '_' . $user->token;
        $ips = Cache::get($key, []);
        if (!is_array($ips)) {
            $ips = [];
        }

        $ip = $this->clientIp($request);
        if ($ip) {
            $ips[$ip] = time();
        }
        Cache::put($key, $ips, 600);

        if (count($ips) > $limit) {
            return $this->applyAction($rule, $request, $user, 'ip_spread');
        }

        return null;
    }

    private function guardIpMultiUser(Request $request, User $user)
    {
        $rule = $this->firstEnabledRule(['ip_multi_user']);
        if (!$rule) {
            return null;
        }

        $ip = $this->clientIp($request);
        if (!$ip) {
            return null;
        }

        if ((new NodeExitIpService())->isNodeExitIp($ip)) {
            $this->logNodeExitBypass($rule, $request, $user, $ip);
            return null;
        }

        $limit = (int)($rule->condition_value ?: 6);
        $key = 'SUB_RULE_IP_USERS_' . md5($ip);
        $users = Cache::get($key, []);
        if (!is_array($users)) {
            $users = [];
        }

        $now = time();
        foreach ($users as $userId => $lastSeen) {
            if ($lastSeen < $now - 600) {
                unset($users[$userId]);
            }
        }
        $users[$user->id] = $now;
        Cache::put($key, $users, 600);

        if (count($users) > $limit) {
            return $this->applyAction($rule, $request, $user, 'ip_multi_user', $ip);
        }

        return null;
    }

    private function logNodeExitBypass(SubscriptionRule $rule, Request $request, User $user, $ip)
    {
        $key = 'SUB_RULE_NODE_EXIT_BYPASS_' . $rule->id . '_' . $user->id . '_' . md5((string)$ip);
        if (!Cache::add($key, time(), 120)) {
            return;
        }

        $log = $this->logHit($rule, $request, $user, 'node_exit_ip_bypass', $ip);
        if ($log) {
            $log->action = 'audit';
            $log->ai_decision = 'allow';
            $log->ai_score = 0;
            $log->ai_reason = '节点出口IP放行，跳过同IP多用户规则';
            $log->save();
        }
    }

    private function guardRequestContext(Request $request, User $user)
    {
        if ($this->hasForwardedHeader($request) && !$this->canTrustForwardedHeaders($request)) {
            $response = $this->applyFirstEnabledRule(['untrusted_proxy_header'], $request, $user, 'untrusted_proxy_header', $this->forwardedFor($request));
            if ($response) {
                return $response;
            }
        }

        $converterKey = $this->firstPresentQueryKey($request, self::CONVERTER_QUERY_KEYS);
        if ($converterKey) {
            $response = $this->applyFirstEnabledRule(['converter_query'], $request, $user, 'converter_query', $converterKey);
            if ($response) {
                return $response;
            }
        }

        if ($this->hasBrowserContextHeader($request) && !$this->isKnownProxyClient($request)) {
            $response = $this->applyFirstEnabledRule(['header_browser_context'], $request, $user, 'header_browser_context', $this->browserContextHeader($request));
            if ($response) {
                return $response;
            }
        }

        $mismatch = $this->flagUserAgentMismatch($request);
        if ($mismatch) {
            return $this->applyFirstEnabledRule(['flag_ua_mismatch'], $request, $user, 'flag_ua_mismatch', $mismatch);
        }

        return null;
    }

    private function guardRequestShape(Request $request, User $user)
    {
        $hostIssue = $this->directIpHost($request);
        if ($hostIssue) {
            $response = $this->applyFirstEnabledRule(['direct_ip_host'], $request, $user, 'direct_ip_host', $hostIssue);
            if ($response) {
                return $response;
            }
        }

        if (in_array(strtoupper((string)$request->method()), ['HEAD', 'OPTIONS'], true) && !$this->isKnownProxyClient($request)) {
            $response = $this->applyFirstEnabledRule(['head_method_probe'], $request, $user, 'head_method_probe', $request->method());
            if ($response) {
                return $response;
            }
        }

        return null;
    }

    private function guardUserAgent(Request $request, User $user)
    {
        $ua = strtolower((string)$request->header('User-Agent', ''));

        if (trim($ua) === '') {
            return $this->applyFirstEnabledRule(['empty_user_agent'], $request, $user, 'empty_user_agent');
        }

        $scanner = $this->firstNeedle($ua, self::SCANNER_UA);
        if ($scanner) {
            return $this->applyFirstEnabledRule(['ua_scanner', 'ua_api_fetch', 'ua_blacklist'], $request, $user, 'ua_scanner', $scanner);
        }

        if ($this->containsAny($ua, self::GOOD_UA)) {
            return null;
        }

        $social = $this->firstNeedle($ua, self::SOCIAL_UA);
        if ($social) {
            return $this->applyFirstEnabledRule(['ua_social', 'ua_blacklist'], $request, $user, 'ua_social', $social);
        }

        $converter = $this->firstNeedle($ua, self::CONVERTER_UA);
        if ($converter) {
            return $this->applyFirstEnabledRule(['ua_converter', 'ua_blacklist'], $request, $user, 'ua_converter', $converter);
        }

        $cli = $this->firstNeedle($ua, self::CLI_FETCH_UA);
        if ($cli) {
            return $this->applyFirstEnabledRule(['ua_cli_fetch', 'ua_blacklist'], $request, $user, 'ua_cli_fetch', $cli);
        }

        $api = $this->firstNeedle($ua, self::API_FETCH_UA);
        if ($api) {
            if ($api === 'go-http-client') {
                return null;
            }
            return $this->applyFirstEnabledRule(['ua_api_fetch', 'ua_blacklist'], $request, $user, 'ua_api_fetch', $api);
        }

        $browser = $this->firstNeedle($ua, self::BROWSER_UA);
        if ($browser) {
            return $this->applyFirstEnabledRule(['ua_browser', 'ua_blacklist'], $request, $user, 'ua_browser', $browser);
        }

        $vendor = $this->firstNeedle($ua, self::VENDOR_UA);
        if ($vendor) {
            return $this->applyFirstEnabledRule(['ua_vendor', 'ua_blacklist'], $request, $user, 'ua_vendor', $vendor);
        }

        return null;
    }

    private function isKnownProxyClient(Request $request)
    {
        $ua = strtolower((string)$request->header('User-Agent', ''));
        return trim($ua) !== '' && ($this->containsAny($ua, self::GOOD_UA) || strpos($ua, 'go-http-client') !== false);
    }

    private function containsAny($value, array $needles)
    {
        foreach ($needles as $needle) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function firstNeedle($value, array $needles)
    {
        foreach ($needles as $needle) {
            if (strpos($value, $needle) !== false) {
                return $needle;
            }
        }
        return null;
    }

    private function applyFirstEnabledRule(array $types, Request $request, User $user, $reason, $matchedValue = '')
    {
        $rule = $this->firstEnabledRule($types);
        if (!$rule) {
            return null;
        }

        return $this->applyAction($rule, $request, $user, $reason, $matchedValue);
    }

    private function firstEnabledRule(array $types)
    {
        return SubscriptionRule::where('enabled', 1)
            ->whereIn('type', $types)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->first();
    }

    private function applyAction(SubscriptionRule $rule, Request $request, User $user, $reason, $matchedValue = '')
    {
        $log = $this->logHit($rule, $request, $user, $reason, $matchedValue);
        $action = $rule->action;
        $action = $this->normalizeAction($action);

        if ($action === 'ai_review' || $this->shouldAiReviewAmbiguousRequest($rule, $request)) {
            return $this->applyAiReviewAction($log, $rule, $request, $user, $reason, $matchedValue);
        }

        switch ($action) {
            case 'reset_subscribe':
                $this->resetUserSecret($user);
                return response('Access Denied', 403, ['Content-Type' => 'text/plain']);

            case 'no_nodes':
                return response('', 200, ['Content-Type' => 'text/plain']);

            case 'notify_admin':
                $this->notifyAdmin($rule, $request, $user, $reason, $matchedValue);
                return null;

            case 'audit':
            case 'record':
            default:
                return null;
        }
    }

    private function applyNodeAction(SubscriptionRule $rule, Request $request, User $user, $reason, $matchedValue = '', $forcedAction = null)
    {
        $action = $this->normalizeAction($forcedAction ?: $rule->action);
        $log = $this->logHit($rule, $request, $user, $reason, $matchedValue, $action);

        if ($action === 'ai_review') {
            $decision = (new AiRiskService())->reviewSubscriptionRequest($request, $user, $rule, $reason, $matchedValue);
            $this->updateAiDecisionLog($log, $decision);
            if (!empty($decision['block']) && $reason !== 'node_alive_ip_over_limit') {
                $this->resetUserSecret($user);
            }
            return null;
        }

        if (in_array($action, ['reset_subscribe', 'no_nodes'], true)) {
            $this->resetUserSecret($user);
        }

        return null;
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

    private function shouldAiReviewAmbiguousRequest(SubscriptionRule $rule, Request $request)
    {
        $type = (string)$rule->type;
        $flagClient = $this->detectClient((string)$request->input('flag', ''));

        if ($type === 'pull_frequency' && $this->isKnownProxyClient($request)) {
            return true;
        }

        if (in_array($type, ['header_browser_context', 'ua_browser', 'ua_social'], true) && $flagClient) {
            return true;
        }

        if ($type === 'flag_ua_mismatch') {
            return true;
        }

        return false;
    }

    private function applyAiReviewAction($log, SubscriptionRule $rule, Request $request, User $user, $reason, $matchedValue = '')
    {
        if ($log) {
            try {
                $log->action = 'ai_review';
                $log->save();
            } catch (Throwable $exception) {
                // Keep the request path alive even if the audit record cannot be adjusted.
            }
        }

        $decision = (new AiRiskService())->reviewSubscriptionRequest($request, $user, $rule, $reason, $matchedValue);
        $this->updateAiDecisionLog($log, $decision);
        if (!empty($decision['block'])) {
            return response('', 200, ['Content-Type' => 'text/plain']);
        }
        return null;
    }

    private function resetUserSecret(User $user)
    {
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->save();
    }

    private function summarizeAliveIps(array $aliveData, $aliveIpCount, $nodeType, $nodeId, $aiLimit, $resetLimit)
    {
        $ips = [];
        foreach ($aliveData as $nodeData) {
            if (!is_array($nodeData) || empty($nodeData['aliveips']) || !is_array($nodeData['aliveips'])) {
                continue;
            }
            foreach ($nodeData['aliveips'] as $ipNode) {
                $ip = explode('_', (string)$ipNode)[0];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[$ip] = true;
                }
            }
        }

        $samples = array_slice(array_keys($ips), 0, 8);
        return substr(sprintf(
            'alive_ip=%d ai_limit=%d reset_limit=%d node=%s%s sample=%s',
            (int)$aliveIpCount,
            (int)$aiLimit,
            (int)$resetLimit,
            $nodeType,
            $nodeId,
            implode(',', $samples)
        ), 0, 255);
    }

    private function notifyAdmin(SubscriptionRule $rule, Request $request, User $user, $reason, $matchedValue = '')
    {
        try {
            (new TelegramService())->sendMessageWithAdmin(
                "[Subscription rule hit]\n"
                . "Rule: {$rule->name} ({$rule->type})\n"
                . "Action: notify_admin\n"
                . "User: {$user->email} / ID {$user->id}\n"
                . "Reason: {$reason}\n"
                . "Matched: " . ((string)$matchedValue ?: '-') . "\n"
                . "IP: " . ((string)$this->clientIp($request) ?: '-') . "\n"
                . "UA: " . substr((string)$request->header('User-Agent', ''), 0, 180),
                true
            );
        } catch (Throwable $exception) {
            return;
        }
    }

    private function logHit(SubscriptionRule $rule, Request $request, User $user, $reason, $matchedValue = '', $action = null)
    {
        try {
            return SubscriptionRuleLog::create([
                'rule_id' => $rule->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', (string)$user->token),
                'rule_type' => $rule->type,
                'action' => $action ?: $rule->action,
                'reason' => $reason,
                'matched_value' => substr((string)$matchedValue, 0, 255),
                'client_ip' => substr((string)$this->clientIp($request), 0, 45),
                'proxy_ip' => substr((string)$this->proxyIp($request), 0, 45),
                'x_forwarded_for' => substr((string)$this->forwardedFor($request), 0, 255),
                'user_agent' => substr((string)$request->header('User-Agent', ''), 0, 512),
                'path' => substr('/' . ltrim($request->path(), '/'), 0, 255),
                'method' => substr((string)$request->method(), 0, 16),
                'flag' => substr((string)$request->input('flag', ''), 0, 64),
                'referer' => substr((string)$request->header('referer', ''), 0, 512),
                'accept' => substr((string)$request->header('accept', ''), 0, 255),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function updateAiDecisionLog($log, array $decision)
    {
        if (!$log) {
            return;
        }

        try {
            $log->ai_decision = $decision['decision'] ?? null;
            $log->ai_score = $decision['risk_score'] ?? null;
            $log->ai_reason = isset($decision['reason']) ? substr((string)$decision['reason'], 0, 255) : null;
            $log->save();
        } catch (Throwable $exception) {
            return;
        }
    }

    private function clientIp(Request $request)
    {
        if ($this->isTrustedForwardChain($request)) {
            $ip = $this->firstHeaderIp($this->forwardedFor($request));
            if ($ip) {
                return $ip;
            }
        }

        if ($this->isTrustedProxy($this->proxyIp($request))) {
            foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
                $ip = $this->firstHeaderIp((string)$request->header($header, ''));
                if ($ip) {
                    return $ip;
                }
            }
        }

        return $this->proxyIp($request);
    }

    private function proxyIp(Request $request)
    {
        return (string)$request->ip();
    }

    private function forwardedFor(Request $request)
    {
        return (string)$request->header('X-Forwarded-For', '');
    }

    private function firstHeaderIp($value)
    {
        foreach (explode(',', $value) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return null;
    }

    private function isTrustedProxy($ip)
    {
        if (!$ip) {
            return false;
        }

        $trustedIps = array_merge(['127.0.0.1', '::1'], (array)config('v2board.trusted_proxy_ips', []));
        if (in_array($ip, $trustedIps, true)) {
            return true;
        }

        foreach ((array)config('v2board.trusted_proxy_cidrs', []) as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr($ip, $cidr)
    {
        if (!is_string($cidr) || strpos($cidr, '/') === false) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int)$mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
    }

    private function hasForwardedHeader(Request $request)
    {
        return (bool)($request->header('X-Forwarded-For') || $request->header('X-Real-IP') || $request->header('CF-Connecting-IP'));
    }

    private function canTrustForwardedHeaders(Request $request)
    {
        return $this->isTrustedProxy($this->proxyIp($request)) || $this->isTrustedForwardChain($request);
    }

    private function isTrustedForwardChain(Request $request)
    {
        foreach ($this->headerIps($this->forwardedFor($request)) as $ip) {
            if ($this->isTrustedProxy($ip)) {
                return true;
            }
        }
        return false;
    }

    private function firstPresentQueryKey(Request $request, array $keys)
    {
        foreach ($keys as $key) {
            if ($request->query->has($key)) {
                return $key;
            }
        }
        return null;
    }

    private function headerIps($value)
    {
        $ips = [];
        foreach (explode(',', $value) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        return $ips;
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

    private function flagUserAgentMismatch(Request $request)
    {
        $flag = $this->detectClient((string)$request->input('flag', ''));
        $ua = $this->detectClient((string)$request->header('User-Agent', ''));
        if (!$flag || !$ua || $flag === $ua) {
            return null;
        }

        return $flag . '!=' . $ua;
    }

    private function directIpHost(Request $request)
    {
        $host = strtolower(trim((string)$request->getHost()));
        if (!$host) {
            return 'empty_host';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $host;
        }
        return null;
    }

    private function detectClient($value)
    {
        $value = strtolower($value);
        $map = [
            'clash' => ['clash', 'mihomo', 'metacubex'],
            'singbox' => ['sing-box', 'singbox', 'hiddify', 'sfa', 'sfi'],
            'shadowrocket' => ['shadowrocket'],
            'quantumult' => ['quantumult', 'quanx'],
            'surge' => ['surge'],
            'loon' => ['loon'],
            'stash' => ['stash'],
            'flowz' => ['flowz'],
            'v2ray' => ['v2ray', 'v2rayn', 'v2rayng', 'qv2ray'],
            'surfboard' => ['surfboard'],
        ];
        foreach ($map as $client => $needles) {
            if ($this->containsAny($value, $needles)) {
                return $client;
            }
        }
        return null;
    }
}
