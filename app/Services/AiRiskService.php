<?php

namespace App\Services;

use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Models\Order;
use App\Models\Plan;
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

    public function generateTicketReplyDraft(array $context, array $config)
    {
        $context = $this->enrichTicketContext($context, $config);
        $selectedKnowledge = $this->selectTicketKnowledge($context);
        $content = $this->callTicketModel($config, [
            [
                'role' => 'system',
                'content' => 'You are an AI customer support assistant for a proxy subscription panel. Write concise, friendly Chinese customer-service replies with a gentle "亲亲" tone, cute but not oily. The customer must know the reply is from an AI assistant. Answer the latest user message specifically. Use the selected knowledge only when relevant. Use ticket_context.ops_context.recent_changes as current operational facts only when it is relevant; if it is empty, never invent backend, node, domain, or subscription changes. Use ticket_context.read_only_user_snapshot, recent_orders, and recent_subscription_hits only as read-only evidence; never say you changed data, never promise a reset, and never expose internal rule names, full IPs, emails, tokens, database fields, or node secrets. Do not mix unrelated categories: payment questions must never mention proxy clients, client versions, nodes, subscriptions, imports, URLs, or proxy settings; client/import questions must not mention payment or orders unless the user explicitly asks. In long conversations, first identify what the user already tried or already provided, then give only the next 1 to 3 useful checks. A staff suggestion is not a completed user action unless the user explicitly says they did it. If the latest user message asks whether something is normal, answer normal or abnormal first, then explain. If a message mixes normal household device count with nodes turning red or timing out, split the answer: the device count can be normal, but the repeated timeout is the part to troubleshoot. If all nodes are timeout/red across regions and the account/subscription looks normal or the user says subscription can update, start with local network exit IP troubleshooting. For home broadband, ask the user to power off the optical modem/ONT or reconnect PPPoE for 3-5 minutes to get a new ISP exit IP; do not say rebooting only the router will change the exit IP. For mobile data, say exactly that the user should turn airplane mode ON, wait 10-20 seconds, then turn airplane mode OFF to reconnect mobile data and get a fresh mobile network exit IP. Do not ask a mobile-data user to test with mobile hotspot as the first step. Do not ask for client version before this first local-IP step unless the user says only one client has the problem. If recent_subscription_hits show reset_subscribe, tell the user the old subscription may have been reset recently and they should copy the latest subscription from the panel. If previous staff replies already gave basic steps, do not repeat them unchanged. If the user already provided an order number, payment time, client version, screenshot, or error log, acknowledge it and do not ask for it again. Do not claim the user has provided account, order, or screenshot information unless it is visible in user messages. Do not mention internal rules, tokens, full IPs, database fields, or implementation details. Do not promise actions that were not confirmed. Do not start with generic greetings like "您好" and do not add signatures. Return only the reply text.'
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Generate a support ticket reply draft in Chinese.',
                    'tone' => '亲亲语气，清楚、耐心、可爱一点，但不要油腻；开头要让客户知道这是 AI 小助手回复',
                    'reply_requirements' => [
                        'Keep the reply short: normally 2 short paragraphs, 3 to 5 sentences, under 260 Chinese characters unless the user asks for a tutorial.',
                        'Do not repeat the same diagnosis, question, or instruction that already appears in previous staff replies.',
                        'If the user has provided the client name, version, screenshot, or log, acknowledge it and do not ask for that same detail again.',
                        'Ask at most one missing detail. Put the next action before the question.',
                        'Do not use generic endings such as "随时告诉我", "希望能帮到您", or repeated cute closing lines.',
                        'Focus on the newest user question or follow-up.',
                        'Clearly identify as an AI assistant near the beginning.',
                        'Use 亲亲 naturally when appropriate, but keep troubleshooting steps accurate.',
                        'Use different guidance for different clients such as Loon, Surge, Shadowrocket, Clash, Stash, sing-box, v2rayN, and soft routers.',
                        'When the user says they already tried a step, move to the next step instead of repeating the same sentence.',
                        'For the third or later round of the same issue, summarize confirmed facts in one short sentence and then give deeper checks or escalation.',
                        'Treat staff messages as suggestions, not as proof that the user completed the step.',
                        'When the user asks "is this normal", answer that directly before asking for more information.',
                        'When device count looks normal but nodes still time out, separate those two conclusions clearly.',
                        'If the user has already provided an order number, payment time, client version, screenshot, or log, do not ask for the same item again.',
                        'Never say the user has already provided account or order details unless those details are visible in user messages.',
                        'Ask at most two missing details, and only when needed.',
                        'Do not ask for the same missing detail twice in one reply.',
                        'Do not add unrelated tips just because they are generally safe.',
                        'If recent_changes says the subscription was changed, renewed, adjusted, or replaced, describe it as the site subscription entry/link being updated, not the user subscription being updated. If the user asks about subscription import, missing nodes, or old links, never say old subscription links can continue to be used. Tell the user to delete the old subscription and copy the latest subscription link from the panel.',
                        'If recent_changes mentions HY2/Hysteria2 blocking, say HY2 protocol recently has some blocking. Never say new protocol blocking or new protocol caused the blocking.',
                        'Do not mention kernels, cores, or Mihomo kernel to customers. Say client or client version instead.',
                        'Follow detected_policy strictly. Never mention forbidden_topics.',
                        'Do not sign the reply or include placeholder names.',
                        'Keep the reply practical and easy for a normal user to understand.'
                    ],
                    'panel_context' => [
                        'service_type' => 'subscription panel',
                        'safe_reply_rules' => [
                            'Ask the user to provide client name, version, and operation time when needed.',
                            'For subscription import issues only, suggest copying the full subscription link from the panel and importing it inside the proxy client.',
                            'For browser or chat app preview issues only, tell the user not to open the subscription link directly in browser or chat software.',
                            'For possible proxy-on import issues only, remind the user to turn off proxy before importing subscription.',
                            'For all-node timeout cases, first distinguish account/subscription problems from local network exit IP problems.',
                            'If recent operational changes are provided, explain them plainly only when the user issue matches.',
                            'If recent subscription hit summary shows a reset, tell the user to delete the old subscription and copy the newest one from the panel.',
                            'Keep the reply practical and easy to understand.'
                        ]
                    ],
                    'detected_policy' => $this->ticketReplyPolicy($context),
                    'selected_knowledge' => $selectedKnowledge,
                    'ticket_context' => $context
                ], JSON_UNESCAPED_UNICODE)
            ]
        ], 30, 1200);

        $content = $this->guardTicketReply($this->trimText($content, 1600), $context);

        $this->markRuntimeStatus(true, 'ticket_draft_success');
        return $this->trimText($content, 1600);
    }

    private function enrichTicketContext(array $context, array $config)
    {
        $recentChanges = trim((string)($config['ticket_ai_recent_context'] ?? ''));
        $context['ops_context'] = [
            'recent_changes' => $this->sanitizeTicketContextText($this->trimText($recentChanges, 1200)),
            'recent_changes_present' => $recentChanges !== ''
        ];

        $user = $this->ticketContextUser($context);
        if (!$user) {
            return $context;
        }

        $context['read_only_user_snapshot'] = $this->ticketUserSnapshot($user);
        $context['recent_orders'] = $this->ticketRecentOrders($user);
        $context['recent_subscription_hits'] = $this->ticketRecentSubscriptionHits($user);

        return $context;
    }

    private function ticketContextUser(array $context)
    {
        $email = trim((string)($context['ticket']['user_email'] ?? ''));
        if ($email === '') {
            return null;
        }

        return User::where('email', $email)->first();
    }

    private function ticketUserSnapshot(User $user)
    {
        $plan = $user->plan_id ? Plan::where('id', $user->plan_id)->first() : null;
        $transferEnable = (int)$user->transfer_enable;
        $usedTraffic = (int)$user->u + (int)$user->d;
        $usedPercent = $transferEnable > 0 ? round($usedTraffic / $transferEnable * 100, 1) : null;
        $expiredAt = (int)$user->expired_at;

        return [
            'has_active_plan' => (bool)$user->plan_id,
            'plan_name' => $plan ? $this->sanitizeTicketContextText((string)$plan->name) : '',
            'is_banned' => (int)$user->banned === 1,
            'is_expired' => $expiredAt > 0 && $expiredAt < time(),
            'expired_at' => $expiredAt > 0 ? $this->formatTicketTime($expiredAt) : '',
            'traffic_used_percent' => $usedPercent,
            'traffic_exhausted' => $transferEnable > 0 && $usedTraffic >= $transferEnable,
            'device_limit' => $user->device_limit !== null ? (int)$user->device_limit : null,
            'last_login_at' => $user->last_login_at ? $this->formatTicketTime((int)$user->last_login_at) : ''
        ];
    }

    private function ticketRecentOrders(User $user)
    {
        return Order::where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->limit(3)
            ->get()
            ->map(function ($order) {
                return [
                    'status' => $this->orderStatusText((int)$order->status),
                    'type' => $this->orderTypeText((int)$order->type),
                    'period' => $this->sanitizeTicketContextText((string)$order->period),
                    'created_at' => $order->created_at ? $this->formatTicketTime((int)$order->created_at) : '',
                    'paid_at' => $order->paid_at ? $this->formatTicketTime((int)$order->paid_at) : ''
                ];
            })
            ->values()
            ->all();
    }

    private function ticketRecentSubscriptionHits(User $user)
    {
        return SubscriptionRuleLog::with(['rule:id,name,type'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->map(function ($log) {
                return [
                    'created_at' => $log->created_at ? $this->formatTicketTime((int)$log->created_at) : '',
                    'rule_name' => $log->rule ? $this->sanitizeTicketContextText((string)$log->rule->name) : '',
                    'rule_type' => $this->sanitizeTicketContextText((string)$log->rule_type),
                    'action' => $this->subscriptionActionText((string)$log->action, (string)$log->ai_decision),
                    'ai_score' => $log->ai_score !== null ? (int)$log->ai_score : null,
                    'summary' => $this->sanitizeTicketContextText($this->trimText((string)($log->ai_reason ?: $log->reason), 120)),
                    'matched_summary' => $this->sanitizeTicketContextText($this->trimText((string)$log->matched_value, 120)),
                    'client' => $this->sanitizeTicketContextText($this->trimText((string)$log->flag ?: (string)$log->user_agent, 80))
                ];
            })
            ->values()
            ->all();
    }

    private function orderStatusText($status)
    {
        $map = [
            0 => '待支付',
            1 => '开通中',
            2 => '已取消',
            3 => '已完成',
            4 => '已折抵'
        ];
        return $map[$status] ?? '未知';
    }

    private function orderTypeText($type)
    {
        $map = [
            1 => '新购',
            2 => '续费',
            3 => '升级'
        ];
        return $map[$type] ?? '未知';
    }

    private function subscriptionActionText($action, $aiDecision)
    {
        if ($action === 'reset_subscribe') {
            return '已重置订阅';
        }
        if ($action === 'ai_review') {
            return $aiDecision === 'block' ? 'AI审查后拒绝下发' : 'AI审查后放行';
        }
        if (in_array($action, ['no_nodes', 'block', 'empty_subscription', 'rate_limit'], true)) {
            return '已拦截或未下发节点';
        }
        if ($action === 'audit') {
            return '已记录';
        }
        return $this->sanitizeTicketContextText($action);
    }

    private function sanitizeTicketContextText($text)
    {
        $text = (string)$text;
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text);
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', '[ip]', $text);
        $text = preg_replace('/([?&](token|access_token|uuid|password|passwd|key)=)[^&\s]+/iu', '$1[hidden]', $text);
        $text = preg_replace('/\b[A-Fa-f0-9]{24,}\b/u', '[hidden]', $text);
        return trim($text);
    }

    private function formatTicketTime($timestamp)
    {
        return date('Y-m-d H:i:s', (int)$timestamp);
    }

    private function guardTicketReply($reply, array $context)
    {
        $reply = trim((string)$reply);
        $reply = preg_replace('/^您好[！!，,。\\s]+/u', '', $reply);

        $userText = trim($this->ticketRoleText($context, 'user') . "\n" . (string)($context['question'] ?? ''));
        $recentChanges = (string)($context['ops_context']['recent_changes'] ?? '');
        if ($this->isTicketWebsiteAccessQuestion($userText)) {
            return $this->ensureTicketAiIdentity($this->ticketWebsiteAccessReply());
        }

        $subscriptionChanged = preg_match('/订阅.*(换新|更新|调整|变更|改动)|入口.*(调整|换新|更新)|旧订阅/u', $recentChanges);
        $subscriptionQuestion = preg_match('/导入|链接|不显示节点|节点.*不显示|旧链接|旧订阅|之前.*链接|重新添加|重新导入|Loon|Surge|订阅(入口|地址|链接|换新|更新失败|不显示|没有节点|为空|空白)/u', $userText);
        if ($subscriptionChanged && $subscriptionQuestion) {
            if (!preg_match('/hy2|Hysteria2/i', $userText)) {
                $reply = preg_replace('/.*(HY2|hy2|Hysteria2).*?[。！？!?][\\r\\n]*/u', '', $reply);
            }
            $reply = preg_replace('/(您|你)的订阅(已|已经)?(换新|更新|调整|变更|更换)/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/(您|你|我)的订阅[^。！？!?\n]*(确实|可能|应该)?被(换新|更新|调整|变更|更换)了?/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/是不是因为订阅(换新|更新)/u', '是不是和网站订阅入口$1有关', $reply);
            $reply = preg_replace('/因为订阅(换新|更新)/u', '因为网站订阅入口$1', $reply);
            $reply = preg_replace('/根据我们的记录，?网站订阅入口已换新/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/删除Loon里的旧订阅/u', '删除客户端里的旧订阅', $reply);
            $reply = preg_replace(
                '/[^。！？!?\\n]*(之前|旧).*?订阅链接[^。！？!?\\n]*(还能|可以|继续用|继续使用)[。！？!?]?/u',
                '旧订阅链接可能已经不稳定，建议删除旧订阅后，从面板重新复制最新订阅链接导入客户端。',
                $reply
            );
            if (!preg_match('/网站订阅入口|网站订阅地址|本站订阅入口|本站订阅地址/u', $reply)) {
                $reply = "这次更像是网站订阅入口/订阅地址换新，不是你的账号订阅被单独换新。\n" . $reply;
            }
            if (!preg_match('/删除旧的?订阅|删除客户端里的旧订阅|最新的?订阅|重新复制.*订阅|复制最新.*订阅|完整订阅链接重新添加/u', $reply)) {
                $reply .= "\n请先删除旧订阅，从面板重新复制最新订阅链接导入客户端。";
            }
            $reply = preg_replace('/。了哦/u', '。', $reply);
        }

        if (preg_match('/hy2|Hysteria2/i', $recentChanges) && preg_match('/阻断|不稳定|连不上|超时/u', $recentChanges)
            && preg_match('/hy2|Hysteria2/i', $userText)) {
            $reply = preg_replace(
                '/.*(订阅.*调整|新协议.*支持|增加了.*新协议|优化和研究|新协议.*阻断|阻断.*新协议).*?[。！？!?][\\r\\n]*/u',
                '',
                $reply
            );
            $reply = preg_replace('/(可能是)?由于新协议的?阻断(导致的?)?/u', '是 HY2 协议最近有些阻断', $reply);
            $reply = preg_replace('/新协议的?阻断/u', 'HY2 协议最近有些阻断', $reply);
            $reply = preg_replace('/(关闭|重启|断电重启)?路由器或光猫(电源)?（?ONT）?/u', '断电重启光猫/ONT，或重新拨号 PPPoE', $reply);
            $reply = preg_replace('/重启光猫或路由器/u', '断电重启光猫/ONT，或重新拨号 PPPoE', $reply);
            $reply = preg_replace('/光猫或路由器/u', '光猫/ONT', $reply);
            $reply = preg_replace('/路由器或光猫/u', '光猫/ONT', $reply);
            $reply = preg_replace('/重启路由器/u', '断电重启光猫/ONT或重新拨号 PPPoE', $reply);
            if (!preg_match('/HY2.*阻断|hy2.*阻断|Hysteria2.*阻断/u', $reply)) {
                $reply .= "\n最近 HY2 协议确实有些阻断，我们正在研究更稳的方案；可以先更新客户端后重新拉取订阅，如果仍不稳定，先临时切换其他可用节点。";
            }
        }

        if (preg_match('/付款|支付|订单|充值|购买|扣款|套餐没开通|未到账|余额/u', $userText)) {
            $reply = preg_replace('/.*(订阅|节点|客户端|代理|VPN|链接|HY2|Hysteria2).*?[。！？!?][\\r\\n]*/u', '', $reply);
            $userProvidedPaymentDetail = preg_match('/订单号|支付时间|付款时间|支付截图|付款截图|订单截图|流水号|交易号|[0-9]{6,}/u', $userText);
            if (!$userProvidedPaymentDetail) {
                $reply = preg_replace('/我们已经确认您的支付信息正在处理中[。！？!?]?/u', '付款状态可能还在同步中。', $reply);
                $reply = preg_replace('/我们会尽快为您处理好[。！？!?]?/u', '收到订单信息后客服会继续核对。', $reply);
                $reply = preg_replace('/我们也会尽快为您处理的?[。！？!?]?/u', '收到订单信息后客服会继续核对。', $reply);
                $reply = preg_replace('/我会及时通知您的?/u', '客服会继续跟进', $reply);
                $reply = preg_replace(
                    '/.*?(之前|已经|已).*?(订单号|支付时间|付款时间).*?(查看|收到|知道|核对).*?[。！？!?]\\s*/u',
                    '如果已经有订单号、支付时间或支付截图，可以一起发来，我会帮您继续核对。' . "\n",
                    $reply
                );
            }
        }
        $userConfirmedOriginalSubscription = preg_match('/已经.*原始订阅|原始订阅.*(试过|测试过|测过|用过)/u', $userText);
        $reply = $this->guardTicketClientName($reply, $userText);
        $reply = preg_replace('/提供一下Loon的版本/u', '提供一下客户端名称和版本', $reply);
        $reply = preg_replace('/Mihomo\s*内核/u', '支持 HY2 的客户端', $reply);
        $reply = preg_replace('/Mihomo/u', '支持 HY2 的客户端', $reply);
        $reply = preg_replace('/客户端核心/u', '客户端', $reply);
        $reply = preg_replace('/手动更新客户端后/u', '更新客户端后', $reply);
        $reply = preg_replace('/核心版本/u', '客户端版本', $reply);
        $reply = preg_replace('/客户端版本版本/u', '客户端版本', $reply);
        $reply = preg_replace('/OpenClash核心|Clash核心/u', '客户端', $reply);
        $reply = preg_replace('/内核版本/u', '客户端版本', $reply);
        $reply = preg_replace('/内核/u', '客户端', $reply);
        $reply = preg_replace('/核心/u', '客户端', $reply);
        $reply = preg_replace('/重启客户端客户端/u', '重启客户端', $reply);
        $reply = preg_replace('/支持支持\s*HY2\s*的客户端的新版本客户端/u', '支持 HY2 的客户端版本', $reply);
        $reply = preg_replace('/支持\s*HY2\s*的客户端的新版本客户端/u', '支持 HY2 的客户端版本', $reply);
        $reply = preg_replace('/支持支持\s*HY2\s*的客户端\/HY2的?新客户端/u', '支持 HY2 的客户端版本', $reply);
        $reply = preg_replace('/支持支持\s*HY2\s*的客户端/u', '支持 HY2 的客户端', $reply);
        $reply = preg_replace('/HY2的?新客户端/u', '支持 HY2 的客户端版本', $reply);
        $reply = preg_replace('/新版本客户端/u', '客户端新版本', $reply);
        if (preg_match('/hy2|Hysteria2/i', $recentChanges . "\n" . $userText)) {
            $reply = preg_replace('/正在研究新协议/u', '正在研究更稳的方案', $reply);
            $reply = preg_replace('/HY2\s*等\s*新协议/u', 'HY2 协议', $reply);
            $reply = preg_replace('/新协议/u', 'HY2 协议', $reply);
            $reply = preg_replace('/支持\s*HY2\s*的?新客户端或客户端版本/u', '支持 HY2 的客户端版本', $reply);
            $reply = preg_replace('/旧版Clash或旧客户端不支持HY2等HY2 协议导致的配置解析不完整/u', '旧版 Clash 客户端可能不支持 HY2，导致配置解析不完整', $reply);
        }
        if (!$userConfirmedOriginalSubscription && preg_match('/原始订阅/u', $reply) && preg_match('/已经|尝试过|确认您/u', $reply)) {
            $reply = preg_replace(
                '/.*?(已经|尝试过|确认您).*?原始订阅.*?[。！？!?]\\s*/u',
                '建议您先用面板里的原始订阅测试一下，确认问题是转换工具造成的，还是原订阅本身导入异常。',
                $reply,
                1
            );
        }

        $userProvidedAccountDetail = preg_match('/\[email\]|\[contact\]|源账号|目标账号|旧账号(是|为|:|：)|新账号(是|为|:|：)|账号(:|：)/u', $userText);
        if (!$userProvidedAccountDetail && preg_match('/提供了.*账号|账号信息/u', $reply)) {
            $reply = preg_replace(
                '/.*?(提供了.*账号信息|提供了.*账号|已.*账号信息|已经.*账号).*?[。！？!?]\s*/u',
                '已经收到您的转移需求。',
                $reply,
                1
            );
        }

        if (preg_match('/不支持\s*HY2|不支持\s*hy2|不支持\s*Hysteria2/u', $userText)
            && preg_match('/不是因为不支持\s*(HY2|hy2|Hysteria2)/u', $reply)) {
            $reply = preg_replace(
                '/不是因为不支持\s*(HY2|hy2|Hysteria2)[。！？!?]?/u',
                '大概率就是旧版 Clash 客户端不支持 HY2。',
                $reply
            );
        }
        if (preg_match('/不支持\s*HY2|不支持\s*hy2|不支持\s*Hysteria2|旧版Clash|旧Clash|Clash|proxy group|自动选择 not found|timeout/i', $userText)) {
            $reply = preg_replace('/如果用户问是不是不支持\s*(HY2|hy2|Hysteria2)，?直接回答[:：]?/u', '', $reply);
            $reply = preg_replace('/升级到最新版本的Clash/u', '更换为支持 HY2 的客户端版本', $reply);
            $reply = preg_replace('/最新版本的Clash/u', '支持 HY2 的客户端版本', $reply);
            $reply = preg_replace('/旧版Clash或旧客户端不支持HY2等\S*协议/u', '旧版 Clash 客户端可能不支持 HY2', $reply);
        }
        if (preg_match('/proxy group|自动选择 not found/i', $userText)) {
            $reply = preg_replace('/[^。！？!?\n]*(原始订阅|转换工具)[^。！？!?\n]*[。！？!?]?\s*/u', '', $reply);
            $reply = preg_replace('/亲亲，您之前有没有尝试过关闭代理后再手动更新订阅呢？如果没有的话，您可以先这样做再测试一下哦。?/u', '', $reply);
            if (!preg_match('/旧版\s*Clash|客户端版本.*旧|不支持\s*HY2/u', $reply)) {
                $reply .= "\n这个提示更像是 Clash 客户端版本偏旧，对 HY2 支持不完整。建议换成支持 HY2 的客户端版本后，再从面板复制最新订阅重新导入。";
            }
        }

        if (preg_match('/手机流量|移动数据|4G|5G|飞行模式/u', $userText)) {
            $reply = preg_replace(
                '/关闭手机?飞行模式，?等待\s*10\s*[-到至]\s*20\s*秒后再(开启|打开)飞行模式(重新连接移动数据)?/u',
                '打开手机飞行模式，等待10到20秒后再关闭飞行模式，让移动数据重新连接',
                $reply
            );
            $reply = preg_replace(
                '/关闭飞行模式，?等待\s*10\s*[-到至]\s*20\s*秒后再(开启|打开)飞行模式(重新连接移动数据)?/u',
                '打开飞行模式，等待10到20秒后再关闭飞行模式，让移动数据重新连接',
                $reply
            );
            $reply = preg_replace('/开启飞行模式重新连接移动数据/u', '关闭飞行模式让移动数据重新连接', $reply);
            $reply = preg_replace('/，重新连接移动数据/u', '', $reply);
            $reply = preg_replace('/如果还是不行，您可以再用手机热点测试一下同一客户端，看是否只有家里宽带有问题。.*?家庭宽带出口IP出现了问题。[\\r\\n]*/u', '', $reply);
            $reply = preg_replace('/如果问题仍然存在，?请尝试使用手机热点.*?家庭宽带出口IP的问题了。[\\r\\n]*/u', '', $reply);
            $reply = preg_replace('/.*手机热点.*家里宽带.*[。！？!?][\\r\\n]*/u', '', $reply);
            $reply = preg_replace('/如果还是不行的话，?您可以再试试重启一下光猫.*?运营商出口IP。[\\r\\n]*/u', '', $reply);
            $reply = preg_replace('/.*光猫.*重新拨号.*[。！？!?][\\r\\n]*/u', '', $reply);
            $reply = preg_replace('/.*光猫.*运营商出口IP.*[。！？!?][\\r\\n]*/u', '', $reply);
        }

        $reply = $this->normalizeTicketReplyStyle($reply, $userText);
        $reply = $this->ensureTicketAiIdentity($reply);

        return trim($reply);
    }

    private function ensureTicketAiIdentity($reply)
    {
        $reply = trim((string)$reply);
        $firstLine = mb_substr($reply, 0, 80);
        if (preg_match('/AI\s*小助手|智能小助手|机器人/u', $firstLine)) {
            return $reply;
        }

        $reply = preg_replace('/^亲亲[，,]\s*/u', '', $reply);
        return "亲亲，我是 AI 小助手。\n" . $reply;
    }

    private function normalizeTicketReplyStyle($reply, $userText)
    {
        $reply = trim((string)$reply);
        $reply = preg_replace('/\r\n|\r/u', "\n", $reply);
        if (preg_match('/如何.*截图|怎么.*截图|截图.*怎么|发送截图|发截图/u', (string)$userText)) {
            return '截图可以直接在工单回复里上传或粘贴；如果页面没有上传按钮，就把客户端测速结果、报错文字复制到工单里。';
        }

        $reply = preg_replace('/支持\s*HY2\s*的支持\s*HY2\s*的客户端\s*Party/iu', 'Mihomo Party', $reply);
        $reply = preg_replace('/支持\s*HY2\s*的客户端\s*Party/iu', 'Mihomo Party', $reply);
        $reply = preg_replace('/Mihomo\s*支持\s*HY2\s*的客户端/iu', 'Mihomo Party', $reply);
        $reply = preg_replace('/关闭代理设备（如路由器）/u', '关闭代理', $reply);
        $reply = preg_replace('/我是\s*AI\s*小助手(哦)?[，,。]?\s*(先帮你看一下[。.]?)?/u', '', $reply);
        $reply = preg_replace('/亲亲[，,]?\s*/u', '', $reply);

        if ($this->ticketUserProvidedClientInfo($userText)) {
            $reply = preg_replace('/[^。！？!?\n]*(请|麻烦|需要|可以|先)?[^。！？!?\n]*(确认|告知|告诉|提供|发来)[^。！？!?\n]*(客户端名称和版本|客户端.*版本|使用的.*客户端|软件.*版本|是什么客户端|哪个客户端)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
            $reply = preg_replace('/[^。！？!?\n]*(如果有|若有)?[^。！？!?\n]*(客户端版本|客户端名称)[^。！？!?\n]*(可以|请|麻烦)?[^。！？!?\n]*(提供|发来|告诉)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
        }

        if ($this->ticketUserProvidedDiagnosticDetail($userText)) {
            $reply = preg_replace('/[^。！？!?\n]*(最近一直|已经试过|是否|有没有|是不是|吗)[^。！？!?\n]*[？?]/u', '', $reply);
            $reply = preg_replace('/[^。！？!?\n]*根据[^。！？!?\n]*(日志|错误日志|提示信息)[^。！？!?\n]*[。！？!?]/u', '', $reply);
            $reply = preg_replace('/这提示[^。！？!?\n]*连接超时[。！？!?]/u', '这个报错更像是本地网络到目标站连接超时。', $reply);
            $reply = preg_replace('/[^。！？!?\n]*(请|麻烦|可以)?[^。！？!?\n]*(提供|发来|告诉)[^。！？!?\n]*(日志|错误日志|提示信息|截图)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
            $reply = preg_replace('/[^。！？!?\n]*(日志|错误日志|提示信息|截图)[^。！？!?\n]*(可以|请|麻烦)?[^。！？!?\n]*(提供|发来|告诉)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
        }

        $reply = preg_replace('/请检查一下本地网络设置和DNS配置是否正常。/u', '先关闭代理刷新订阅，再换个网络或重启光猫后测试。', $reply);
        if (preg_match('/全部.*超时|全.*红|所有.*节点.*(超时|不可用|红)|timeout/i', (string)$userText . "\n" . $reply)
            && !preg_match('/光猫|飞行模式|刷新订阅|重新导入|换个网络|网络出口/u', $reply)) {
            $reply .= "\n先关闭代理刷新订阅；如果仍然全部超时，家里宽带请断电重启光猫 3-5 分钟，手机流量请开关一次飞行模式。";
        }

        $reply = preg_replace('/[^。！？!?\n]*(我们来一起看看|接下来可以怎么排查|如果有任何疑问|如有任何疑问|需要更多帮助|随时告诉我|随时联系|希望.*帮到|这样应该能帮到|这样应该能帮助)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
        $reply = preg_replace('/\s+/u', ' ', $reply);
        $reply = trim($reply);

        return $this->compactTicketReply($reply);
    }

    private function ticketUserProvidedClientInfo($text)
    {
        return (bool)preg_match('/Mihomo\s*Party|Clash\s*Party|Clash\s*Meta|Shadowrocket|小火箭|Surge|Loon|Stash|sing-?box|v2rayN|OpenClash|PassWall|Karing|Hiddify|Quantumult|NekoBox|版本|v?\d+\.\d+|内核/u', (string)$text);
    }

    private function ticketUserProvidedDiagnosticDetail($text)
    {
        return (bool)preg_match('/日志|error|timeout|deadline exceeded|not found|截图|报错|提示|版本|v?\d+\.\d+/iu', (string)$text);
    }

    private function compactTicketReply($reply)
    {
        $sentences = preg_split('/(?<=[。！？!?])\s*/u', trim((string)$reply), -1, PREG_SPLIT_NO_EMPTY);
        if (!$sentences) {
            return trim((string)$reply);
        }

        $kept = [];
        $seen = [];
        $length = 0;
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $key = preg_replace('/[，,。！？!?\s\dA-Za-z]+/u', '', $sentence);
            $key = mb_substr($key, 0, 24);
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $sentence;
            $length += mb_strlen($sentence);
            if (count($kept) >= 4 || $length >= 320) {
                break;
            }
        }

        return trim(implode("\n", $kept));
    }

    private function isTicketWebsiteAccessQuestion($text)
    {
        $text = (string)$text;
        if (preg_match('/#\/dashboard|\/dashboard/u', $text)) {
            return true;
        }

        $mentionsSite = preg_match('/网站|官网|面板|网址|登录页|首页/u', $text);
        $accessProblem = preg_match('/打不开|进不去|进不来|无法访问|访问不了|打开不了|不连.*进不来|不挂.*进不来/u', $text);
        $subscriptionIssue = preg_match('/订阅|节点|导入|更新|客户端|代理客户端|超时|测速/u', $text);

        return $mentionsSite && $accessProblem && !$subscriptionIssue;
    }

    private function ticketWebsiteAccessReply()
    {
        return implode("\n", [
            '你发的这个更像是面板页面地址，不一定适合当作长期入口保存。',
            '如果网站打不开，请先打开发布页，发布页里有海外站和国内站入口：海外网络可以试海外站，国内网络优先试国内站。换入口后再登录面板即可。',
            '如果发布页里的入口也打不开，请把打不开的入口和报错截图发来，我再继续帮你看。'
        ]);
    }

    private function ticketRoleText(array $context, $role)
    {
        $messages = $context['ticket']['messages'] ?? [];
        $texts = [];
        foreach ((array)$messages as $message) {
            if (($message['from'] ?? '') === $role) {
                $texts[] = (string)($message['message'] ?? '');
            }
        }
        return implode("\n", $texts);
    }

    private function guardTicketClientName($reply, $userText)
    {
        $client = $this->primaryTicketClient($userText);

        $clientPatterns = [
            '/Mihomo\s*Party/iu',
            '/Clash\s*Party/iu',
            '/Clash\s*Meta/iu',
            '/Clash(?!\s*(Party|Meta))/iu',
            '/Shadowrocket|小火箭/u',
            '/Surge/iu',
            '/Loon/iu',
            '/Stash/iu',
            '/sing-?box/iu',
            '/v2rayN/iu',
            '/OpenClash/iu',
            '/PassWall/iu'
        ];

        if ($client === '') {
            foreach ($clientPatterns as $pattern) {
                $reply = preg_replace($pattern, '代理客户端', $reply);
            }
            $reply = preg_replace('/代理客户端代理/u', '代理客户端的代理', $reply);
            $reply = preg_replace('/比如(?:是)?代理客户端(?:、代理客户端|还是代理客户端|或代理客户端|和代理客户端|等|、|，|\s)+/u', '比如你使用的代理客户端名称和版本', $reply);
            $reply = preg_replace('/比如你使用的代理客户端名称和版本(还是|或|和)代理客户端等/u', '比如你使用的代理客户端名称和版本', $reply);
            $reply = preg_replace('/请确认一下(您|你)使用的代理客户端是哪个版本呢？比如你使用的代理客户端名称和版本/u', '请告诉我你使用的代理客户端名称和版本哦', $reply);
            $reply = preg_replace('/(您|你)使用的是哪个客户端呢？比如你使用的代理客户端名称和版本/u', '请告诉我你使用的代理客户端名称和版本哦', $reply);
            return $reply;
        }

        foreach ($clientPatterns as $pattern) {
            if (preg_match($pattern, $client)) {
                continue;
            }
            $reply = preg_replace($pattern, $client, $reply);
        }

        return $reply;
    }

    private function primaryTicketClient($text)
    {
        $clients = [
            'Mihomo Party' => '/Mihomo\s*Party/iu',
            'Clash Party' => '/Clash\s*Party/iu',
            'Clash Meta' => '/Clash\s*Meta|(?<!Party\s)Mihomo(?!\s*Party)/iu',
            'Shadowrocket' => '/Shadowrocket|小火箭/u',
            'Surge' => '/Surge/iu',
            'Loon' => '/Loon/iu',
            'Stash' => '/Stash/iu',
            'sing-box' => '/sing-?box/iu',
            'v2rayN' => '/v2rayN/iu',
            'OpenClash' => '/OpenClash/iu',
            'PassWall' => '/PassWall/iu'
        ];

        $matched = [];
        foreach ($clients as $name => $pattern) {
            if (preg_match($pattern, $text)) {
                $matched[] = $name;
            }
        }

        return count($matched) === 1 ? $matched[0] : '';
    }

    private function ticketReplyPolicy(array $context)
    {
        $text = mb_strtolower(json_encode($context, JSON_UNESCAPED_UNICODE));
        $hasPayment = preg_match('/付款|支付|订单|充值|购买|扣款|套餐没开通|未到账|余额/u', $text);
        $hasClient = preg_match('/loon|surge|shadowrocket|小火箭|clash|stash|sing-box|singbox|v2rayn|hiddify|openclash|passwall|订阅|导入|节点|客户端|测速|延迟/u', $text);
        $hasAccount = preg_match('/登录|密码|验证码|邮箱|账号|找回/u', $text);

        if ($hasPayment) {
            return [
                'issue_type' => 'payment_or_order',
                'must_do' => [
                    'Only discuss order status, payment status, recharge, package activation, and missing payment evidence.',
                    'If order number or payment time is already provided, acknowledge it and do not ask for it again.',
                    'If more information is needed, ask only for a payment screenshot or order screenshot.'
                ],
                'forbidden_topics' => ['proxy clients', 'client versions', 'nodes', 'subscription import', 'subscription URL', 'proxy or VPN settings', 'reset subscription']
            ];
        }

        if ($hasAccount && !$hasClient) {
            return [
                'issue_type' => 'account_or_login',
                'must_do' => [
                    'Only discuss login, email verification, account status, and safe account recovery.',
                    'Never ask for the user password.'
                ],
                'forbidden_topics' => ['payment unless mentioned by user', 'node troubleshooting unless mentioned by user']
            ];
        }

        if ($hasClient) {
            return [
                'issue_type' => 'client_subscription_or_node',
                'must_do' => [
                    'Give client-specific next steps based on the latest message.',
                    'Do not repeat a step the user already said they tried.',
                    'Ask for client version or logs only if they were not already provided.'
                ],
                'forbidden_topics' => ['payment', 'order number', 'recharge', 'refund unless mentioned by user']
            ];
        }

        return [
            'issue_type' => 'general_support',
            'must_do' => [
                'Ask for at most two missing details that are necessary to continue.',
                'Keep the reply short and practical.'
            ],
            'forbidden_topics' => ['unrelated payment or client instructions']
        ];
    }

    private function selectTicketKnowledge(array $context, $limit = 6)
    {
        $knowledge = $this->ticketKnowledgeBase();
        if (!$knowledge) {
            return [];
        }

        $text = mb_strtolower(json_encode($context, JSON_UNESCAPED_UNICODE));
        $scored = [];
        foreach ($knowledge as $item) {
            $score = 0;
            foreach ((array)($item['keywords'] ?? []) as $keyword) {
                $keyword = trim((string)$keyword);
                if ($keyword === '') {
                    continue;
                }
                if (mb_stripos($text, mb_strtolower($keyword)) !== false) {
                    $score += max(2, mb_strlen($keyword));
                }
            }
            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'item' => [
                        'id' => $item['id'] ?? '',
                        'title' => $item['title'] ?? '',
                        'answer_points' => array_slice((array)($item['answer_points'] ?? []), 0, 5)
                    ]
                ];
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_map(function ($row) {
            return $row['item'];
        }, array_slice($scored, 0, $limit));
    }

    private function ticketKnowledgeBase()
    {
        static $knowledge = null;
        if ($knowledge !== null) {
            return $knowledge;
        }

        $data = [];
        foreach (glob(resource_path('ai/ticket_knowledge*.json')) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $items = json_decode((string)file_get_contents($path), true);
            if (is_array($items)) {
                $data = array_merge($data, $items);
            }
        }

        return $knowledge = array_values(array_filter($data, function ($item) {
            return is_array($item) && !empty($item['keywords']) && !empty($item['answer_points']);
        }));
    }

    private function callTicketModel(array $config, array $messages)
    {
        $baseUrl = rtrim((string)($config['ticket_ai_base_url'] ?? 'http://152.53.36.230:11434'), '/');
        $model = trim((string)($config['ticket_ai_model'] ?? 'qwen2.5:7b-instruct'));
        if ($baseUrl === '') {
            throw new RuntimeException('ticket AI base URL is empty');
        }
        if ($model === '') {
            throw new RuntimeException('ticket AI model is empty');
        }

        $client = new Client([
            'timeout' => 90,
            'connect_timeout' => 8,
            'http_errors' => false
        ]);

        $response = $client->post($baseUrl . '/api/chat', [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'model' => $model,
                'stream' => false,
                'messages' => $messages,
                'options' => [
                    'temperature' => 0.15,
                    'num_predict' => 280
                ]
            ]
        ]);

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if ($response->getStatusCode() >= 400) {
            $message = $data['error'] ?? ('HTTP ' . $response->getStatusCode());
            throw new RuntimeException('ticket AI request failed: ' . $message);
        }

        $content = $data['message']['content'] ?? '';
        if (!$content) {
            throw new RuntimeException('ticket AI returned empty response');
        }

        return $content;
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
                    'content' => 'You are a realtime subscription risk engine. Return compact JSON only: {"decision":"allow|block","risk_score":0-100,"reason":"short Chinese reason"}. decision must be exactly allow or block. Use 0-100 scale. The query flag is user-controlled and can be forged; never treat flag=clash, flag=shadowrocket, or similar as proof of a normal client. Trust request_context.known_proxy_user_agent and the actual User-Agent more than flag. Known proxy clients include Shadowrocket, Clash, Mihomo, Sing-box, V2RayN, V2RayNG, Surge, Loon, Stash, Quantumult, FlowZ, Hiddify, and Karing. If current_hit.rule_type is pull_frequency and request_context.known_proxy_user_agent is true, this is usually a proxy app refresh/retry; allow unless recent_same_user_hits show clear credential sharing, many unrelated IP ranges, scanner/client mismatch, or repeated hard blocks. If actual User-Agent is browser Chrome/Safari/Firefox/Edge or social/webview and request_context.known_proxy_user_agent is false, block when current_hit.rule_type is ua_browser, ua_social, or header_browser_context, even if flag claims a proxy client. If the actual User-Agent is curl, wget, httpie, PowerShell, python-requests, Go-http-client, Postman, browser Chrome/Safari/Firefox/Edge, Telegram/Wechat/QQ webview, scanner Censys/Shodan/zgrab/nmap, or empty, block when current_hit.rule_type confirms that evidence. If current_hit.rule_type is ua_cli_fetch and User-Agent is curl/wget/httpie/PowerShell, score 90-100 and block. If rule_type is ua_api_fetch and User-Agent is python-requests/Go-http-client/Postman/axios, score 90-100 and block. If rule_type is ua_scanner, score 95-100 and block. If rule_type is node_alive_ip_over_limit, treat the over-limit count as a review signal, not automatic proof. Block only when matched_value and recent_same_user_hits indicate credential sharing, many unrelated network ranges, repeated over-limit hits, or clearly non-household use. Allow when the evidence can reasonably fit a small household, router, mobile network switch, or normal multi-device use. If rule_type is direct_ip_host or head_method_probe, trust the current_hit evidence and block when it indicates direct-IP access or probing. If evidence is weak or AI is unsure, allow with score below 80. For block decisions, the reason must describe why the subscription was refused; do not use suggestion or recommendation wording such as 建议. Never include full IPs, emails, tokens, or node data.'
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
        $ua = (string)$request->header('User-Agent', '');
        $flag = (string)$request->input('flag', '');
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
                'user_agent' => $this->trimText($ua, 180),
                'flag' => $this->trimText($flag, 80),
                'path' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'referer_present' => $request->header('referer') ? true : false,
                'accept' => $this->trimText((string)$request->header('accept', ''), 120)
            ],
            'request_context' => [
                'known_proxy_user_agent' => $this->hasProxyClientUa(strtolower($ua)),
                'flag_claims_proxy_client' => $this->flagClaimsProxyClient($flag),
                'has_browser_context_header' => $this->hasBrowserContextHeader($request),
                'browser_context_header' => $this->browserContextHeader($request),
                'flag_user_agent_mismatch' => $this->flagClaimsProxyClient($flag) && !$this->hasProxyClientUa(strtolower($ua)),
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
            'flowz',
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
