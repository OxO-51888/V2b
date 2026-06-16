<?php

namespace App\Services;

use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AiRiskService
{
    private const OPENAI_BASE_URL = 'https://api.openai.com/v1';
    private const OPENAI_MODEL = 'gpt-5-nano';
    private const RUNTIME_CACHE_KEY = 'SUBSCRIPTION_RULE_AI_RUNTIME_STATUS';

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
        $config = $this->openAiConfig((array)config('v2board', []));
        if (empty($config['ai_risk_enable']) || empty($config['ai_risk_api_key'])) {
            $this->markRuntimeStatus(false, 'not_configured');
            $decision = $this->decision('allow', 0, 'AI is not enabled or API key is missing', false);
            return $this->enforceRuleFloor($decision, $request, $rule);
        }

        $cacheKey = 'AI_SUB_REVIEW_' . md5(implode('|', [
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

        $payload = $this->buildRealtimePayload($request, $user, $rule, $reason, $matchedValue);
        try {
            $content = $this->callModel($config, [
                [
                    'role' => 'system',
                    'content' => 'You are a realtime subscription risk engine. Return compact JSON only: {"decision":"allow|block","risk_score":0-100,"reason":"short Chinese reason"}. decision must be exactly allow or block. Use 0-100 scale. The query flag is user-controlled and can be forged; never treat flag=clash, flag=shadowrocket, or similar as proof of a normal client. Trust the actual User-Agent and the current_hit.rule_type more than the flag. Known proxy clients are allow only when the actual User-Agent itself contains Shadowrocket, Clash, Sing-box, V2RayN, V2RayNG, Surge, Loon, Stash, Quantumult, etc. If the actual User-Agent is curl, wget, httpie, PowerShell, python-requests, Go-http-client, Postman, browser Chrome/Safari/Firefox/Edge, Telegram/Wechat/QQ webview, scanner Censys/Shodan/zgrab/nmap, or empty, block when current_hit.rule_type confirms that evidence, even if flag claims a proxy client. If current_hit.rule_type is ua_cli_fetch and User-Agent is curl/wget/httpie/PowerShell, score 90-100 and block. If rule_type is ua_api_fetch and User-Agent is python-requests/Go-http-client/Postman/axios, score 90-100 and block. If rule_type is ua_scanner, score 95-100 and block. If rule_type is ua_browser or ua_social and User-Agent is browser/social webview, score 85-95 and block. If rule_type is node_alive_ip_over_limit, treat it as likely single-node credential sharing, score 92-100 and block unless the evidence clearly shows a normal small household. If rule_type is direct_ip_host or head_method_probe, trust the current_hit evidence and block when it indicates direct-IP access or probing. If evidence is weak or AI is unsure, allow with score below 80. For block decisions, the reason must describe why the subscription was refused; do not use suggestion or recommendation wording such as 建议. Never include full IPs, emails, tokens, or node data.'
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ]
            ], 12, 2048);
            $this->markRuntimeStatus(true, 'review_success');
            $decision = $this->parseDecision($content, (int)($config['ai_risk_block_score'] ?? 80));
            $decision = $this->enforceRuleFloor($decision, $request, $rule);
        } catch (\Throwable $exception) {
            $this->markRuntimeStatus(false, 'review_failed: ' . $exception->getMessage());
            $decision = $this->decision('allow', 0, 'AI failed: ' . $exception->getMessage(), false);
            $decision = $this->enforceRuleFloor($decision, $request, $rule);
            if (!empty($decision['block'])) {
                $decision['reason'] = 'AI不可用，按高危规则拦截';
            }
        }

        Cache::put($cacheKey, $decision, 300);
        return $decision;
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
        $history = SubscriptionRuleLog::where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->limit(12)
            ->get()
            ->map(function ($log) {
                return [
                    'rule_type' => $log->rule_type,
                    'action' => $log->action,
                    'reason' => $log->reason,
                    'matched_value' => $this->trimText((string)$log->matched_value, 90),
                    'client_ip_range' => $this->maskIp($log->client_ip),
                    'user_agent' => $this->trimText((string)$log->user_agent, 120),
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
                'client_ip_range' => $this->maskIp($this->clientIp($request)),
                'proxy_ip_range' => $this->maskIp((string)$request->ip()),
                'x_forwarded_for_ranges' => $this->maskIpList((string)$request->header('X-Forwarded-For', '')),
                'user_agent' => $this->trimText((string)$request->header('User-Agent', ''), 180),
                'flag' => $this->trimText((string)$request->input('flag', ''), 80),
                'path' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'referer_present' => $request->header('referer') ? true : false,
                'accept' => $this->trimText((string)$request->header('accept', ''), 120)
            ],
            'user_snapshot' => [
                'traffic_status' => $this->trafficStatus($user),
                'recent_rule_hits' => count($history)
            ],
            'recent_same_user_hits' => $history
        ];
    }

    private function callModel(array $config, array $messages, $timeout, $maxTokens)
    {
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

        if (strpos(strtolower((string)$json['model']), 'gpt-5') === 0) {
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
            unset($json['max_completion_tokens']);
            $json['max_tokens'] = $maxTokens;
            $response = $client->post($baseUrl . '/chat/completions', [
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
        if ($score >= $blockScore) {
            $decision = 'block';
        }

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

        if (in_array($type, ['node_alive_ip_over_limit'], true)) {
            $floor = 92;
        } elseif (in_array($type, ['ua_scanner'], true)) {
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
            $decision['reason'] = '命中高危规则，忽略可伪造flag';
            $decision['block'] = true;
        }

        return $decision;
    }

    private function hasProxyClientUa($ua)
    {
        foreach ([
            'shadowrocket',
            'clash',
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
            'sfa',
            'sfi',
            'hiddify',
        ] as $needle) {
            if (strpos($ua, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function shouldRetryWithLegacyMaxTokens($data)
    {
        $message = strtolower((string)($data['error']['message'] ?? ''));
        return strpos($message, 'max_completion_tokens') !== false
            && (strpos($message, 'unsupported') !== false || strpos($message, 'unrecognized') !== false);
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
        foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
            $ip = $this->firstHeaderIp((string)$request->header($header, ''));
            if ($ip) {
                return $ip;
            }
        }
        return (string)$request->ip();
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

    private function markRuntimeStatus($running, $reason)
    {
        Cache::put(self::RUNTIME_CACHE_KEY, [
            'running' => (bool)$running,
            'reason' => (string)$reason,
            'checked_at' => time(),
            'source' => 'openai'
        ], 86400);
    }
}
