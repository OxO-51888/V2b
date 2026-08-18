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
    private $lastTicketToolUsage = [];

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
        $selectedKnowledge = [];
        if ($this->ticketAiToolEnabled($config, 'ticket_ai_tool_knowledge_enable', true)) {
            $selectedKnowledge = $this->selectTicketKnowledge($context, $config, 3);
            $this->recordTicketToolUsage($context, 'knowledge_search', '知识库检索', true, !empty($selectedKnowledge), [
                'items' => count($selectedKnowledge)
            ]);
        } else {
            $this->recordTicketToolUsage($context, 'knowledge_search', '知识库检索', false, false);
        }

        $selectedExamples = $this->selectTicketReplyExamples($context, 3);
        $this->recordTicketToolUsage($context, 'reply_examples', '历史工单范例', true, !empty($selectedExamples), [
            'items' => count($selectedExamples)
        ]);

        $messages = [
            [
                'role' => 'system',
                'content' => '你是代理订阅面板的 AI 客服小助手。只用中文回复，开头必须让用户知道是 AI 小助手在回复，例如“亲亲，我是 AI 小助手。”或“AI 小助手来啦”。禁止用“您好”开头。语气亲切、清楚、略可爱但不要油腻。先读完整工单上下文、用户最新消息、近期变动、只读查询结果和知识库，再判断最可能原因；不要只因为出现某个关键词就套固定模板。只根据 payload 回答，不编造后台、节点、域名、订阅变化；不要输出“帮你草拟回复”这种内部话。不要暴露邮箱、完整 IP、token、规则名、数据库字段或实现细节；不要承诺已经修改数据。付款问题只谈订单/支付，客户端或节点问题只谈客户端/订阅/网络。用户已经提供的信息不要重复索要；最多问一个必要问题。输出 2 段内，通常 3-5 句。不要签名。'
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => '生成一条工单回复草稿。',
                    'must_follow' => [
                        '知识库只是参考材料，不是关键词命令。必须结合完整上下文判断：同样出现“timeout、报错、导入失败、订阅”等词时，可能分别是网络、客户端版本、订阅入口、配置解析或用户操作问题。',
                        '如果证据不足，先问一个最关键的信息，例如“具体提示了什么文字”或“使用的客户端名称和版本”，不要凭关键词直接下结论。',
                        '如果用户给出了导入失败、证书校验、配置解析、proxy group、domain_resolver、x509、unknown field 等明确报错，优先解释这个报错本身，不要套用全部节点超时或本地网络出口排查。',
                        '如果 Meta、Clash Meta、Mihomo、Hiddify 这类客户端导入订阅时出现 x509、certificate signed by unknown authority 或 tls failed to verify certificate，优先按客户端 Meta 版本太低或组件太旧处理：让用户去教程页下载新版/推荐客户端后重新导入，不要说服务器证书危险。',
                        '如果 Shadowrocket/小火箭仍然提示证书无效、服务器 URL 遇到问题、伪装服务器或连接到不可信服务器，先让用户暂时换用教程页里的 iOS 推荐客户端，例如 Loon、Surge 或 Stash；不要推荐安卓或电脑端客户端，也不要继续让用户在小火箭里反复导入或确认提示。',
                        '如果是全部节点超时且订阅可更新，优先判断本地网络出口 IP 问题：宽带断电光猫/ONT 或重新拨号 PPPoE 3-5 分钟；手机流量开关飞行模式 10-20 秒。',
                        '如果用户说国内站打不开、国内入口打不开，必须明确让用户先关闭代理或 VPN，使用本地网络，并用 Google Chrome 浏览器直接访问国内站入口；不要只说打开发布页。',
                        '只有 recent_changes 明确写到“网站订阅入口、订阅地址、订阅链接、订阅域名、发布页入口”这类入口变更时，才允许说“网站订阅入口/订阅地址换新”；如果只是写“订阅已换新”，不要主动提订阅入口换新。',
                        '如果提到 HY2/Hysteria2，只能说 HY2 协议最近有些阻断；不要说新协议，不要说内核，客户只看客户端/客户端版本。',
                        '如果命中记录显示订阅被重置，提醒用户删除旧订阅，并从面板复制最新订阅重新导入。',
                        '如果用户已经说删除过旧订阅、重新导入过、关闭过代理或刷新成功，不要再要求重复同一步，直接给下一步检查。',
                        '如果用户没说客户端名称，不要假定 Shadowrocket、Loon、Clash 或其他具体客户端。',
                        '不要把“远程订阅、远程配置、普通节点、本地配置、策略组、解析”这类内部排查词直接发给客户；必须改成客户能照做的步骤。',
                        'Loon 不显示节点时，优先说：请在 Loon 里删除旧订阅，点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。',
                        '工单目前不能直接上传图片；除非用户明确说已经在售后群发图，否则不要主动要求截图或图片，优先让用户复制报错文字、客户端名称、版本和操作时间。',
                        '付款问题不要凭最近订单直接说“已完成”或“系统正在处理中”；只有用户提供订单号或付款时间并且只读查询能对应上时，才描述具体订单状态。否则只让用户查看订单页，并补充订单号和付款时间。',
                        'selected_examples 是正式服历史工单范例，只能学习客服排查顺序、表达习惯和信息取舍；当前工单事实、只读查询结果和 selected_knowledge 优先。不要复制范例中的账号、时间、链接、站点、旧公告或不适合当前场景的结论。'
                    ],
                    'detected_policy' => $this->ticketReplyPolicy($context),
                    'selected_knowledge' => $selectedKnowledge,
                    'selected_examples' => $selectedExamples,
                    'ticket_context' => $this->ticketModelContext($context)
                ], JSON_UNESCAPED_UNICODE)
            ]
        ];

        $content = $this->generateGuardedTicketReply($config, $messages, $context);
        $this->lastTicketToolUsage = array_values($context['ai_tool_usage'] ?? []);

        $this->markRuntimeStatus(true, 'ticket_draft_success');
        return $this->trimText($content, 1600);
    }

    private function generateGuardedTicketReply(array $config, array $messages, array $context)
    {
        $content = $this->guardTicketReply($this->trimText($this->callTicketModel($config, $messages), 1600), $context);
        $blockReason = $this->ticketAutoPublishBlockReason($content, $context);
        if (!$blockReason) {
            return $content;
        }

        $retryMessages = $messages;
        $retryMessages[] = [
            'role' => 'assistant',
            'content' => $content
        ];
        $retryMessages[] = [
            'role' => 'user',
            'content' => '上一条回复没有通过发布检查，原因：' . $blockReason . '。请重新生成一条可以直接发给用户的完整中文工单回复：必须说明你是 AI 小助手，必须结合用户问题给出具体下一步；不要只写开场白，不要输出内部规则、后台、数据库、token、完整 IP 或无关建议。'
        ];

        return $this->guardTicketReply($this->trimText($this->callTicketModel($config, $retryMessages), 1600), $context);
    }

    public function lastTicketToolUsage()
    {
        return $this->lastTicketToolUsage;
    }

    public function ticketAutoReplySkipReason(array $context)
    {
        if ($this->ticketPaymentOrderQuestion($context)) {
            return 'payment_order';
        }

        return '';
    }

    public function ticketAutoPublishBlockReason($reply, array $context)
    {
        $reply = trim((string)$reply);
        $userText = trim($this->ticketRoleText($context, 'user') . "\n" . (string)($context['question'] ?? ''));
        if ($reply === '' || mb_strlen($reply) < 24) {
            return 'empty_or_too_short';
        }
        if (!preg_match('/AI\s*小助手/u', $reply)) {
            return 'missing_ai_identity';
        }
        if ($this->ticketPaymentOrderQuestion($context)) {
            return 'payment_order';
        }
        if (preg_match('/正在.*核实|正在.*核对|正在处理中|第一时间(给您|给你)?回复|第一时间通知|已经收到.*订单|已收到.*订单|请您稍等|尽快为您处理/u', $reply)) {
            return 'unsafe_manual_commitment';
        }
        if (!preg_match('/付款|支付|订单|充值|未到账|没到账|扣款|余额|退款/u', $userText)
            && preg_match('/付款|支付|订单|充值|未到账|没到账|扣款|余额|退款/u', $reply)) {
            return 'unrelated_payment_topic';
        }
        $asksForFullSubscription = preg_match('/(发来|发给|提供|发送|贴出|提交)[^。！？\n]{0,24}完整订阅链接|完整订阅链接[^。！？\n]{0,24}(发来|发给|提供|发送|贴出|提交)/u', $reply)
            && !preg_match('/不要[^。！？\n]{0,24}(发来|发给|提供|发送|贴出|提交)[^。！？\n]{0,24}完整订阅链接|不要[^。！？\n]{0,24}完整订阅链接[^。！？\n]{0,24}(发来|发给|提供|发送|贴出|提交)/u', $reply);
        if (preg_match('/密码|token|数据库|规则名|后台服务器|完整\s*IP|内核|核心/u', $reply)
            || $asksForFullSubscription) {
            return 'sensitive_or_internal_term';
        }
        if (!preg_match('/截图|图片|发图|上传/u', $userText)
            && preg_match('/截图|图片|发图|上传/u', $reply)) {
            return 'asks_for_image';
        }
        if (preg_match('/随时联系|继续反馈|更多信息|如有.*问题|希望.*帮助|欢迎/u', $reply)
            && mb_strlen($reply) > 260) {
            return 'too_much_filler';
        }

        return '';
    }

    private function ticketModelContext(array $context)
    {
        $ticket = (array)($context['ticket'] ?? []);
        $messages = [];
        foreach (array_slice((array)($ticket['messages'] ?? []), -6) as $message) {
            $messages[] = [
                'from' => $message['from'] ?? '',
                'message' => $this->sanitizeTicketContextText($this->trimText((string)($message['message'] ?? ''), 260)),
                'created_at' => $message['created_at'] ?? ''
            ];
        }

        return [
            'question' => $this->sanitizeTicketContextText($this->trimText((string)($context['question'] ?? ''), 500)),
            'source' => $this->sanitizeTicketContextText((string)($context['source'] ?? '')),
            'ticket' => [
                'subject' => $this->sanitizeTicketContextText($this->trimText((string)($ticket['subject'] ?? ''), 160)),
                'messages' => $messages
            ],
            'read_only_summary' => $this->sanitizeTicketContextText($this->trimText((string)($context['read_only_context_summary'] ?? ''), 800)),
            'recent_changes' => $this->sanitizeTicketContextText($this->trimText((string)($context['ops_context']['recent_changes'] ?? ''), 500)),
            'capabilities' => $context['ai_capabilities'] ?? []
        ];
    }

    private function enrichTicketContext(array $context, array $config)
    {
        $context['ai_tool_usage'] = [];
        $context['ai_capabilities'] = $this->ticketAiCapabilities($config);

        if ($this->ticketAiToolEnabled($config, 'ticket_ai_tool_ops_context_enable', true)) {
            $recentChanges = trim((string)($config['ticket_ai_recent_context'] ?? ''));
            $context['ops_context'] = [
                'recent_changes' => $this->sanitizeTicketContextText($this->trimText($recentChanges, 1200)),
                'recent_changes_present' => $recentChanges !== ''
            ];
            $this->recordTicketToolUsage($context, 'ops_context', '近期变动说明', true, $recentChanges !== '');
        } else {
            $context['ops_context'] = [
                'recent_changes' => '',
                'recent_changes_present' => false
            ];
            $this->recordTicketToolUsage($context, 'ops_context', '近期变动说明', false, false);
        }

        $user = $this->ticketContextUser($context);
        if (!$user) {
            $this->recordTicketToolUsage($context, 'user_status', '用户状态查询', $this->ticketAiToolEnabled($config, 'ticket_ai_tool_user_status_enable', true), false, [
                'reason' => 'ticket_user_not_found'
            ]);
            $this->recordTicketToolUsage($context, 'recent_orders', '订单查询', $this->ticketAiToolEnabled($config, 'ticket_ai_tool_order_enable', true), false, [
                'reason' => 'ticket_user_not_found'
            ]);
            $this->recordTicketToolUsage($context, 'subscription_hits', '订阅命中查询', $this->ticketAiToolEnabled($config, 'ticket_ai_tool_subscription_hit_enable', true), false, [
                'reason' => 'ticket_user_not_found'
            ]);
            return $context;
        }

        if ($this->ticketAiToolEnabled($config, 'ticket_ai_tool_user_status_enable', true)) {
            $context['read_only_user_snapshot'] = $this->ticketUserSnapshot($user);
            $this->recordTicketToolUsage($context, 'user_status', '用户状态查询', true, true);
        } else {
            $this->recordTicketToolUsage($context, 'user_status', '用户状态查询', false, false);
        }

        if ($this->ticketAiToolEnabled($config, 'ticket_ai_tool_order_enable', true)) {
            $context['recent_orders'] = $this->ticketRecentOrders($user);
            $this->recordTicketToolUsage($context, 'recent_orders', '订单查询', true, true, [
                'items' => count($context['recent_orders'])
            ]);
        } else {
            $this->recordTicketToolUsage($context, 'recent_orders', '订单查询', false, false);
        }

        if ($this->ticketAiToolEnabled($config, 'ticket_ai_tool_subscription_hit_enable', true)) {
            $context['recent_subscription_hits'] = $this->ticketRecentSubscriptionHits($user);
            $this->recordTicketToolUsage($context, 'subscription_hits', '订阅命中查询', true, true, [
                'items' => count($context['recent_subscription_hits'])
            ]);
        } else {
            $this->recordTicketToolUsage($context, 'subscription_hits', '订阅命中查询', false, false);
        }

        $context['read_only_context_summary'] = $this->ticketReadOnlyContextSummary($context);

        return $context;
    }

    private function ticketAiCapabilities(array $config)
    {
        return [
            'user_status' => [
                'label' => '用户状态查询',
                'enabled' => $this->ticketAiToolEnabled($config, 'ticket_ai_tool_user_status_enable', true)
            ],
            'recent_orders' => [
                'label' => '订单查询',
                'enabled' => $this->ticketAiToolEnabled($config, 'ticket_ai_tool_order_enable', true)
            ],
            'subscription_hits' => [
                'label' => '订阅命中查询',
                'enabled' => $this->ticketAiToolEnabled($config, 'ticket_ai_tool_subscription_hit_enable', true)
            ],
            'ops_context' => [
                'label' => '近期变动说明',
                'enabled' => $this->ticketAiToolEnabled($config, 'ticket_ai_tool_ops_context_enable', true)
            ],
            'knowledge_search' => [
                'label' => '知识库检索',
                'enabled' => $this->ticketAiToolEnabled($config, 'ticket_ai_tool_knowledge_enable', true)
            ],
            'reply_examples' => [
                'label' => '历史工单范例',
                'enabled' => true
            ]
        ];
    }

    private function ticketAiToolEnabled(array $config, $key, $default = true)
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        return (int)$config[$key] === 1;
    }

    private function recordTicketToolUsage(array &$context, $key, $label, $enabled, $used, array $meta = [])
    {
        $context['ai_tool_usage'][$key] = array_merge([
            'key' => $key,
            'label' => $label,
            'enabled' => (bool)$enabled,
            'used' => (bool)$used
        ], $meta);
    }

    private function ticketReadOnlyContextSummary(array $context)
    {
        $lines = [];

        $snapshot = $context['read_only_user_snapshot'] ?? [];
        if ($snapshot) {
            $status = [];
            $status[] = !empty($snapshot['has_active_plan']) ? '有套餐' : '无套餐';
            if (!empty($snapshot['plan_name'])) {
                $status[] = '套餐：' . $snapshot['plan_name'];
            }
            $status[] = !empty($snapshot['is_banned']) ? '账号已封禁' : '账号未封禁';
            $status[] = !empty($snapshot['is_expired']) ? '套餐已过期' : '套餐未过期';
            if ($snapshot['traffic_used_percent'] !== null) {
                $status[] = '流量已用约 ' . $snapshot['traffic_used_percent'] . '%';
            }
            if (!empty($snapshot['traffic_exhausted'])) {
                $status[] = '流量已用尽';
            }
            if ($snapshot['device_limit'] !== null) {
                $status[] = '设备限制 ' . $snapshot['device_limit'];
            }
            $lines[] = '用户状态：' . implode('，', $status) . '。';
        }

        if (array_key_exists('recent_orders', $context)) {
            $orders = array_slice((array)$context['recent_orders'], 0, 3);
            if ($orders) {
                $parts = [];
                foreach ($orders as $order) {
                    $bits = array_filter([
                        $order['created_at'] ?? '',
                        $order['type'] ?? '',
                        $order['period'] ?? '',
                        $order['status'] ?? '',
                        !empty($order['paid_at']) ? '支付于 ' . $order['paid_at'] : ''
                    ]);
                    $parts[] = implode(' / ', $bits);
                }
                $lines[] = '最近订单：' . implode('；', $parts) . '。';
            } else {
                $lines[] = '最近订单：未查到近期订单。';
            }
        }

        if (array_key_exists('recent_subscription_hits', $context)) {
            $hits = array_slice((array)$context['recent_subscription_hits'], 0, 5);
            if ($hits) {
                $parts = [];
                foreach ($hits as $hit) {
                    $bits = array_filter([
                        $hit['created_at'] ?? '',
                        $hit['rule_name'] ?? '',
                        $hit['action'] ?? '',
                        isset($hit['ai_score']) ? 'AI ' . $hit['ai_score'] . '分' : '',
                        $hit['summary'] ?? '',
                        $hit['matched_summary'] ?? '',
                        $hit['client'] ?? ''
                    ], function ($value) {
                        return $value !== null && $value !== '';
                    });
                    $parts[] = implode(' / ', $bits);
                }
                $lines[] = '最近订阅安全记录：' . implode('；', $parts) . '。';
            } else {
                $lines[] = '最近订阅安全记录：未查到近期命中。';
            }
        }

        $recentChanges = trim((string)($context['ops_context']['recent_changes'] ?? ''));
        if ($recentChanges !== '') {
            $lines[] = '近期后台说明：' . $recentChanges . '。';
        }

        return $this->trimText(implode("\n", array_filter($lines)), 1200);
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
        $reply = preg_replace('/^您好[呀啊哈呢哦]*[！!，,。～~\\s]+/u', '', $reply);
        $reply = preg_replace('/(^|\\n)\\s*您好[呀啊哈呢哦]*[！!，,。～~\\s]+/u', '$1', $reply);
        $reply = preg_replace('/(^|\\n)\\s*[～~]\\s*/u', '$1', $reply);
        $reply = preg_replace('/帮你草拟回复[:：]?\\s*/u', '', $reply);
        $reply = preg_replace('/^关于(您|你)的?问题[，,]\\s*/u', '', $reply);

        $userText = trim($this->ticketRoleText($context, 'user') . "\n" . (string)($context['question'] ?? ''));
        $recentChanges = (string)($context['ops_context']['recent_changes'] ?? '');
        if (preg_match('/怎么.*(发图|发图片|截图|上传图片)|发图|发图片|上传图片|报错截图/u', $userText)) {
            return "亲亲，我是 AI 小助手。\n工单这里暂时只能发文字，不能直接上传图片哦。你可以把报错弹窗里的文字复制到工单里，再写上使用的客户端名称、版本和大概操作时间；如果一定要发截图，请先发到售后群，然后回到这个工单说一声已发图。";
        }
        $paymentQuestion = (bool)preg_match('/付款|支付|订单|充值|扣款|套餐没开通|未到账|没到账|未到帐|没到帐|余额/u', $userText);
        if (preg_match('/购买了?.*(订阅|套餐).*?(无法使用|不能用|没有节点|无节点|节点不可用|节点)|订阅后无法使用/u', $userText)) {
            $paymentQuestion = false;
        }
        $userProvidedPaymentDetail = (bool)preg_match('/订单号|支付时间|付款时间|支付截图|付款截图|订单截图|流水号|交易号|[0-9]{6,}/u', $userText);
        $shadowrocketCertIssue = preg_match('/Shadowrocket|小火箭/u', $userText)
            && preg_match('/证书|URL|伪装|不可信|x509|certificate|unknown authority|verify certificate|服务器.*无效/i', $userText);
        if ($this->isTicketWebsiteAccessQuestion($userText)) {
            return $this->ensureTicketAiIdentity($this->ticketWebsiteAccessReply());
        }
        if (preg_match('/Shadowrocket|小火箭/u', $userText)
            && preg_match('/wifi|WiFi|WIFI|无线|流量|节点.*刷新|跳节点|加载不出来|节点.*加载/u', $userText)) {
            return "亲亲，我是 AI 小助手。\n小火箭在手机流量下相对稳定、切到 WiFi 后一直跳节点或加载不出来时，更像是当前宽带网络到节点不稳定。宽带可以先断电重启光猫/ONT 3-5 分钟再试；如果还是不稳，也可以去教程页换用 iOS 推荐客户端，比如 Loon、Surge 或 Stash 后重新导入订阅。";
        }
        if (preg_match('/流量|用了多少|多少\s*[gG]|还能不能用|剩余|用不了了/u', $userText)
            && !preg_match('/全部.*超时|所有.*超时|节点.*超时|导入失败|配置.*失败|报错|节点|小火箭|Shadowrocket|wifi|WiFi|WIFI/u', $userText)) {
            return "亲亲，我是 AI 小助手。\n流量以面板里的「流量明细」和仪表盘剩余量为准，客户端里显示的总量或已用量有时不完整。规则模式和全局模式都会按实际经过节点的流量统计；如果面板显示还有流量但仍不能用，请把页面提示文字发来，我再继续帮你看。";
        }
        if (preg_match('/节点.*(变少|少了|只有.*绿|两三个.*绿|不稳定)|绿色.*(少|只有)|刷新.*(变换|换成).*国家|更新订阅.*不能改善/u', $userText)
            && !preg_match('/流媒体|地区显示|归属地/u', $userText)) {
            return "亲亲，我是 AI 小助手。\n看到你说节点变少、只有少数是绿色，而且更新订阅也没有改善，这更像是当前网络到节点不稳定，不是套餐丢了。宽带可以断电重启光猫/ONT 3-5 分钟，手机流量可以开关一次飞行模式；如果换个网络后节点恢复，就基本是本地网络出口问题。";
        }
        if (preg_match('/Loon/i', $userText)) {
            $reply = preg_replace(
                '/[^。！？\n]*(远程订阅|远程配置)[^。！？\n]*(普通节点|本地配置)[^。！？\n]*[。！？]?/u',
                '请在 Loon 里删除旧订阅，然后点右上角 +，选择用链接或 URL 添加订阅，把面板里复制的完整订阅链接粘贴进去；添加后手动刷新一次，看节点是否出现。',
                $reply
            );
            $reply = preg_replace(
                '/[^。！？\n]*(远程订阅|远程配置)[^。！？\n]*[。！？]?/u',
                '如果还是没有节点，请把 Loon 添加订阅页面和报错提示截图发给我们，不要发送完整订阅链接。',
                $reply
            );
            $reply = preg_replace(
                '/[^。！？\n]*(客户端解析|订阅格式兼容)[^。！？\n]*[。！？]?/u',
                '如果刷新后还是没有节点，请把 Loon 添加订阅页面和报错提示截图发给我们，不要发送完整订阅链接。',
                $reply
            );
            if ($this->ticketUserAlreadyDeletedOldSubscription($userText)) {
                $reply = preg_replace(
                    '/建议先删除旧订阅，然后在 Loon 里/u',
                    '既然已经删过旧订阅，下一步请在 Loon 里',
                    $reply
                );
                $reply = preg_replace(
                    '/建议先删除旧订阅，然后在/u',
                    '既然已经删过旧订阅，下一步请在',
                    $reply
                );
                $reply = preg_replace(
                    '/建议先在 Loon 里删除旧订阅，然后/u',
                    '既然已经删过旧订阅，下一步请在 Loon 里',
                    $reply
                );
                $reply = preg_replace(
                    '/请在 Loon 里删除旧订阅，然后/u',
                    '既然已经删过旧订阅，下一步请在 Loon 里',
                    $reply
                );
                $reply = $this->removeTicketLinesWithAny($reply, ['如果已经尝试过删除旧订阅和刷新']);
            }
        }

        $subscriptionChanged = $this->ticketRecentChangesHasSubscriptionEntryChange($recentChanges);
        $subscriptionQuestion = preg_match('/导入|链接|不显示节点|节点.*不显示|旧链接|旧订阅|之前.*链接|重新添加|重新导入|Loon|Surge|订阅(入口|地址|链接|换新|更新失败|不显示|没有节点|为空|空白)/u', $userText);
        if ($subscriptionChanged && $subscriptionQuestion) {
            if (!preg_match('/hy2|Hysteria2/i', $userText)) {
                $reply = preg_replace('/.*(HY2|hy2|Hysteria2).*?[。！？!?][\\r\\n]*/u', '', $reply);
            }
            $reply = preg_replace('/(您|你)的订阅(已|已经)?(换新|更新|调整|变更|更换)/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/(您|你|我)的订阅[^。！？!?\n]*(确实|可能|应该)?被(换新|更新|调整|变更|更换)了?/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/最近订阅(已|已经)?(换新|更新|调整|变更|更换)/u', '网站订阅入口已换新', $reply);
            $reply = preg_replace('/订阅(已|已经)(换新|更新|调整|变更|更换)/u', '网站订阅入口已换新', $reply);
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
        if (!$subscriptionChanged) {
            $reply = $this->removeTicketSubscriptionEntryChangeClaims($reply);
            $reply = preg_replace('/[^。！？!?\\n]*(您|你|你的|您的)?订阅(已|已经)?(换新|更新|调整|变更|更换)[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
        }
        if (!$this->ticketQuestionNeedsHy2Context($userText)) {
            $reply = preg_replace('/[^。！？!?\\n]*(HY2|hy2|Hysteria2|新协议|协议最近|协议有些阻断|阻断)[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
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

        if ($paymentQuestion) {
            $reply = preg_replace('/.*(订阅|节点|客户端|代理|VPN|链接|HY2|Hysteria2).*?[。！？!?][\\r\\n]*/u', '', $reply);
            if (!$userProvidedPaymentDetail) {
                if (preg_match('/订单状态.*已完成|已完成.*处理中|支付.*已完成|付款.*已完成/u', $reply)) {
                    $reply = "亲亲，我是 AI 小助手。\n付款状态有时会有几分钟同步延迟，请先刷新订单页和仪表盘看看套餐是否生效；如果还是未到账，请把订单号和付款时间写到工单里，客服会继续核对。不要重复支付同一订单哦。";
                }
                $reply = preg_replace('/我们已经确认您的支付信息正在处理中[。！？!?]?/u', '付款状态可能还在同步中。', $reply);
                $reply = preg_replace('/我们会尽快为您处理好[。！？!?]?/u', '收到订单信息后客服会继续核对。', $reply);
                $reply = preg_replace('/我们也会尽快为您处理的?[。！？!?]?/u', '收到订单信息后客服会继续核对。', $reply);
                $reply = preg_replace('/我会及时通知您的?/u', '客服会继续跟进', $reply);
                $reply = preg_replace('/[^。！？!?\n]*(已经|已)(收到|看到|确认)[^。！？!?\n]*(付款信息|支付信息)[^。！？!?\n]*[。！？!?]?/u', '看到你说已经付款但套餐还没到账啦。', $reply);
                $reply = preg_replace('/请问一下您这次付款的具体订单号是多少呢[？?]?/u', '请把这次付款的订单号和付款时间写到工单里，客服会继续核对。', $reply);
                $reply = preg_replace(
                    '/.*?(之前|已经|已).*?(订单号|支付时间|付款时间).*?(查看|收到|知道|核对).*?[。！？!?]\\s*/u',
                    '如果已经有订单号或支付时间，可以一起写到工单里，我会帮您继续核对。' . "\n",
                    $reply
                );
            }
        }
        $userConfirmedOriginalSubscription = preg_match('/已经.*原始订阅|原始订阅.*(试过|测试过|测过|用过)/u', $userText);
        $reply = $this->guardTicketClientName($reply, $userText);
        if ($this->ticketUserAlreadyDeletedOldSubscription($userText)) {
            $reply = preg_replace('/[^。！？!?\\n]*(请|先|建议|需要|可以|麻烦)?[^。！？!?\\n]*(删除|删掉|移除).*?旧订阅[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
            $reply = preg_replace('/[^。！？!?\\n]*(确认|检查|看看).*?(是否)?(已经)?(删除|删掉|移除).*?旧订阅[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
        }
        if ($this->ticketUserAlreadyRefreshedSubscription($userText)) {
            $reply = preg_replace('/[^。！？!?\\n]*(是否|有没有|请|先|建议|可以|麻烦)?[^。！？!?\\n]*(尝试|进行|手动)?刷新(订阅)?[^。！？!?\\n]*(确认|查看|显示)?[^。！？!?\\n]*(更新时间|更新成功)?[^。！？!?\\n]*[？?。！？!]?/u', '', $reply);
            $reply = preg_replace('/[^。！？!?\\n]*(确认|检查|查看).*?更新时间[^。！？!?\\n]*[？?。！？!]?/u', '', $reply);
        }
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
        $reply = preg_replace('/客户不需要管客户端，只看客户端名称和版本即可/u', '不用管复杂参数，只看客户端名称和版本即可', $reply);
        $reply = preg_replace('/不需要管客户端，只看客户端名称和版本即可/u', '不用管复杂参数，只看客户端名称和版本即可', $reply);
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
        if (!$userConfirmedOriginalSubscription && !preg_match('/原始订阅|转换站|转换工具|第三方转换/u', $userText)) {
            $reply = preg_replace(
                '/[^。！？!?\\n]*(原始订阅|转换站|转换工具|第三方转换)[^。！？!?\\n]*[。！？!?]?/u',
                '如果重新导入后还是没有节点，请把客户端名称、版本和具体报错截图发来，我再帮你判断。',
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
        if ($shadowrocketCertIssue) {
            $reply = "小火箭这边证书或 URL 提示还不稳定，先别继续在小火箭里反复确认啦。\n请先去教程页换用 iOS 推荐客户端，比如 Loon、Surge 或 Stash，再从面板复制完整订阅重新导入。";
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
        if (preg_match('/家里宽带|家庭宽带|宽带/u', $userText) && !preg_match('/手机|移动数据|手机流量|4G|5G|飞行模式/u', $userText)) {
            $reply = preg_replace('/[，,；;]?\s*(也可以尝试)?切换手机流量[^。！？!?]*[。！？!?]?/u', '。', $reply);
            $reply = preg_replace('/手机流量[^。！？!?]*飞行模式[^。！？!?]*[。！？!?]?/u', '', $reply);
        }
        $reply = preg_replace('/宽带光猫\/ONT是否断电，?或者尝试重新拨号\s*PPPoE\s*3-5分钟/u', '断电重启光猫/ONT，或重新拨号 PPPoE 3-5 分钟', $reply);
        $reply = preg_replace('/光猫\/ONT是否断电/u', '断电重启光猫/ONT', $reply);

        $reply = $this->normalizeTicketReplyStyle($reply, $userText);
        $reply = $this->ensureTicketAiIdentity($reply);
        $reply = $this->guardTicketNoImageUpload($reply, $userText);
        if (!$subscriptionChanged) {
            $reply = $this->removeTicketLinesWithAnyEncoded($reply, [
                '6K6i6ZiF5bey5o2i5paw',
                '6K6i6ZiF5YWl5Y+jL+ivoumsgOWcsOWdgOaNouaWsA==',
                '6LSm5Y+36K6i6ZiF6KKr5Y2V54us5o2i5paw',
                '572R56uZ6K6i6ZiF5YWl5Y+j5bey5o2i5paw',
                '572R56uZ6K6i6ZiF5Zyw5Z2A5o2i5paw'
            ]);
        }
        if (!$this->ticketQuestionNeedsHy2Context($userText)) {
            $reply = $this->removeTicketLinesWithAny($reply, ['HY2', 'Hysteria2']);
            $reply = $this->removeTicketLinesWithAnyEncoded($reply, [
                '5paw5Y2P6K6u',
                '6Zi75pat'
            ]);
        }
        if ($this->ticketUserAlreadyRefreshedSubscription($userText)) {
            $reply = $this->removeTicketLinesWithAnyEncoded($reply, [
                '5omL5Yqo5Yi35paw',
                '5Yi35paw6K6i6ZiF',
                '5pu05paw5pe26Ze05ZKM6IqC54K55pWw6YeP',
                '5piv5ZCm5pi+56S65pu05paw5pe26Ze0'
            ]);
        }
        if (preg_match('/Loon/i', $userText)
            && $this->ticketUserAlreadyDeletedOldSubscription($userText)
            && $this->ticketUserAlreadyRefreshedSubscription($userText)
            && !preg_match('/远程订阅|远程配置|普通节点|本地配置|右上角|链接或 URL|完整订阅链接/u', $reply)) {
            $reply .= "\n下一步请在 Loon 里点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加完成后手动刷新一次。如果还是没有节点，请发 Loon 添加订阅页面和报错提示截图。";
        }
        if (preg_match('/Loon/i', $userText) && substr_count($reply, '右上角') > 1) {
            $lines = preg_split('/\r\n|\r|\n/', $reply);
            $kept = [];
            $seenLoonAddStep = false;
            foreach ($lines as $line) {
                $isLoonAddStep = strpos($line, '右上角') !== false
                    && (strpos($line, 'URL') !== false || strpos($line, '链接') !== false);
                if ($isLoonAddStep && $seenLoonAddStep) {
                    continue;
                }
                if ($isLoonAddStep) {
                    $seenLoonAddStep = true;
                }
                $kept[] = $line;
            }
            $reply = implode("\n", $kept);
        }
        $reply = $this->removeTicketLinesWithAnyEncoded($reply, [
            '5aaC5pyJ5YW25LuW6Zeu6aKY',
            '5qyi6L+O57un57ut5Y+N6aaI',
            '6ZqP5pe25ZGK6K+J5oiR',
            '6ZqP5pe26IGU57O7'
        ]);
        $reply = preg_replace('/(^|\\n)\\s*[～~]\\s*/u', '$1', $reply);
        $reply = preg_replace('/\n\s*[。！？!?]\s*/u', "\n", $reply);
        $reply = preg_replace('/AI\s*小助手。\s*[。！？!?]/u', 'AI 小助手。', $reply);
        if ($this->primaryTicketClient($userText) === '') {
            $reply = preg_replace('/代理客户端\s+不显示节点/u', '代理客户端不显示节点', $reply);
            $reply = preg_replace(
                '/点右上角\s*\+\s*，选择用链接或\s*URL\s*添加订阅/u',
                '在使用的代理客户端里选择“添加订阅”或“URL 导入”',
                $reply
            );
        }

        if ($paymentQuestion
            && !$userProvidedPaymentDetail
            && (!preg_match('/订单号.*付款时间|付款时间.*订单号/u', $reply)
                || preg_match('/订阅|节点|客户端|代理|VPN|链接/u', $reply))) {
            return "亲亲，我是 AI 小助手。\n看到你说已经付款但套餐还没到账啦。付款状态有时会有几分钟同步延迟，请先刷新订单页和仪表盘看看套餐是否生效；如果还是没有到账，请把这次付款的订单号和付款时间写到工单里，客服会继续核对。不要重复支付同一订单哦。";
        }
        if ($paymentQuestion
            && $userProvidedPaymentDetail
            && (preg_match('/已经收到.*订单信息|已收到.*订单信息|正在.*核实|正在核对中|尽快为您处理|第一时间(给您)?回复|第一时间通知|请您稍等|正在处理中|我们这边正在/u', $reply)
                || preg_match('/订阅|节点|客户端|代理|VPN|链接/u', $reply))) {
            return "亲亲，我是 AI 小助手。\n看到你已经提供了订单信息。请先刷新订单页和仪表盘看看套餐是否生效；如果还是未到账，客服会按你提供的订单信息继续核对。不要重复支付同一订单哦。";
        }

        if (preg_match('/Loon/i', $userText)
            && $this->ticketUserAlreadyDeletedOldSubscription($userText)
            && $this->ticketUserAlreadyRefreshedSubscription($userText)) {
            return "亲亲，我是 AI 小助手。既然你已经删过旧订阅并刷新成功了，先不用重复删除。\n下一步请在 Loon 里点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。如果还是没有节点，请把 Loon 添加订阅页面里的提示文字发来，不要发送完整订阅链接。";
        }

        if (preg_match('/Loon/i', $userText)
            && preg_match('/不显示节点|没有节点|节点为空|空的|导入.*空/u', $userText)
            && (!preg_match('/右上角/u', $reply)
                || !preg_match('/完整订阅/u', $reply)
                || !preg_match('/提示文字/u', $reply))) {
            if ($this->ticketUserAlreadyDeletedOldSubscription($userText)) {
                return "亲亲，我是 AI 小助手。既然你已经删过旧订阅，先不用重复删啦。\n请在 Loon 里点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。如果还是没有节点，请把 Loon 添加订阅页面里的提示文字发来，不要发送完整订阅链接。";
            }
            return "亲亲，我是 AI 小助手。\nLoon 不显示节点时，请先在 Loon 里删除旧订阅，点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。如果还是没有节点，请把 Loon 添加订阅页面里的提示文字发来，不要发送完整订阅链接。";
        }

        if (preg_match('/Loon/i', $userText)
            && (mb_strlen(trim($reply)) < 32 || !preg_match('/右上角|链接|URL|完整订阅|手动刷新|提示文字/u', $reply))) {
            if ($this->ticketUserAlreadyDeletedOldSubscription($userText)) {
                return "亲亲，我是 AI 小助手。既然你已经删过旧订阅，先不用重复删啦。\n请在 Loon 里点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。如果还是没有节点，请把 Loon 添加订阅页面里的提示文字发来，不要发送完整订阅链接。";
            }
            return "亲亲，我是 AI 小助手。\nLoon 不显示节点时，请先在 Loon 里删除旧订阅，点右上角 +，选择用链接或 URL 添加订阅，粘贴面板复制的完整订阅链接；添加后手动刷新一次。";
        }

        return trim($reply);
    }

    private function guardTicketNoImageUpload($reply, $userText)
    {
        if (preg_match('/截图.*(已发|发了)|已发.*截图|图片.*(已发|发了)|售后群.*(截图|图片)/u', (string)$userText)) {
            return $reply;
        }

        return strtr((string)$reply, [
            '支付截图' => '付款时间',
            '付款截图' => '付款时间',
            '订单截图' => '订单号和付款时间',
            '报错截图' => '报错文字',
            '提示截图' => '提示文字',
            '错误截图' => '错误文字',
            '截图发来' => '文字发来',
            '截图发给我们' => '文字发给我们',
            '截图' => '文字说明',
            '图片' => '文字说明'
        ]);
    }

    private function ticketRecentChangesHasSubscriptionEntryChange($recentChanges)
    {
        $recentChanges = (string)$recentChanges;
        if ($recentChanges === '') {
            return false;
        }

        return (bool)preg_match('/(网站|本站|面板|发布页)?\\s*(订阅)?\\s*(入口|地址|链接|域名|URL)\\s*(已|已经)?\\s*(换新|更新|调整|变更|更换|改动)|发布页.*(入口|地址|链接|域名).*(换新|更新|调整|变更|更换|改动)/u', $recentChanges);
    }

    private function removeTicketSubscriptionEntryChangeClaims($reply)
    {
        $reply = preg_replace('/[^。！？!?\\n]*(网站|本站|面板)?订阅(入口|地址|链接|域名)[^。！？!?\\n]*(换新|更新|调整|变更|更换|改动)[^。！？!?\\n]*[。！？!?]?/u', '', (string)$reply);
        $reply = preg_replace('/[^。！？!?\\n]*订阅入口\\/订阅地址换新[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
        $reply = preg_replace('/[^。！？!?\\n]*(不是你的账号订阅被单独换新|不是您的账号订阅被单独换新)[^。！？!?\\n]*[。！？!?]?/u', '', $reply);
        return trim($reply);
    }

    private function ticketUserAlreadyDeletedOldSubscription($text)
    {
        $text = (string)$text;
        foreach (['已经删除旧订阅', '已经删掉旧订阅', '已经删了旧订阅', '删除过旧订阅', '删过旧订阅', '删了旧订阅', '旧订阅删了', '旧订阅删除了'] as $needle) {
            if (strpos($text, $needle) !== false) {
                return true;
            }
        }
        return (bool)preg_match('/(已经|已|刚刚|刚才|之前|我).*?(删除|删掉|删了|移除).*?旧订阅|旧订阅.*?(已经|已|删掉|删了|删除|移除)/u', $text);
    }

    private function ticketUserAlreadyRefreshedSubscription($text)
    {
        $text = (string)$text;
        foreach ($this->decodeTicketNeedles([
            '5pu05paw5pi+56S65oiQ5Yqf',
            '5Yi35paw5oiQ5Yqf',
            '5pu05paw5oiQ5Yqf',
            '5bey57uP5Yi35paw',
            '5bey57uP5pu05paw',
            '5omL5Yqo5Yi35paw6L+H',
            '5omL5Yqo5Yi35paw5LqG',
            '6K6i6ZiF6K+m5oOF5pyJ5pu05paw5pe26Ze0'
        ]) as $needle) {
            if (strpos($text, $needle) !== false) {
                return true;
            }
        }
        return (bool)preg_match('/(已经|已|刚刚|刚才|之前|我).*?(刷新过|更新过|刷新了|更新了|手动刷新|重新刷新|重新更新)/u', $text);
    }

    private function ticketQuestionNeedsHy2Context($text)
    {
        return (bool)preg_match('/hy2|hysteria2|协议|阻断|全部.*超时|全.*超时|所有.*超时|全红|全部.*红|timeout|连不上|不可用/i', (string)$text);
    }

    private function removeTicketLinesWithAny($reply, array $needles)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string)$reply);
        $kept = [];
        foreach ($lines as $line) {
            $drop = false;
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($line, $needle) !== false) {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                $kept[] = $line;
            }
        }
        return trim(implode("\n", $kept));
    }

    private function removeTicketLinesWithAnyEncoded($reply, array $encodedNeedles)
    {
        return $this->removeTicketLinesWithAny($reply, $this->decodeTicketNeedles($encodedNeedles));
    }

    private function decodeTicketNeedles(array $encodedNeedles)
    {
        return array_values(array_filter(array_map(function ($needle) {
            return base64_decode((string)$needle, true) ?: '';
        }, $encodedNeedles), function ($needle) {
            return $needle !== '';
        }));
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
        if (preg_match('/如何.*截图|怎么.*截图|截图.*怎么|发送截图|发截图|不能发图片|发不了图片|图片上传不了/u', (string)$userText)) {
            return '亲亲，工单这里暂时只能发文字，不能直接传图片哦。你可以把报错弹窗里的文字复制到工单里，再写上使用的客户端名称、版本和大概操作时间；如果只能截图，请先到售后群发图，然后回到这个工单说一声已发截图，我这边会按工单对应账号继续核对。';
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
            && !preg_match('/proxy group|自动选择 not found|domain_resolver|unknown field|x509|certificate|unmarshal|导入失败|配置.*失败|添加配置文件失败|not found/i', (string)$userText)
            && !preg_match('/光猫|飞行模式|刷新订阅|重新导入|换个网络|网络出口/u', $reply)) {
            $reply .= "\n先关闭代理刷新订阅；如果仍然全部超时，家里宽带请断电重启光猫 3-5 分钟，手机流量请开关一次飞行模式。";
        }

        $reply = preg_replace('/[^。！？!?\n]*(我们来一起看看|接下来可以怎么排查|如果有任何疑问|如果.*其他问题|如需进一步帮助|如有任何疑问|需要更多帮助|提供更多细节|随时告诉我|随时联系|欢迎.*随时|希望.*帮到|这样应该能帮到|这样应该能帮助)[^。！？!?\n]*[。！？!?]?/u', '', $reply);
        $reply = preg_replace('/(^|\n)\s*[。！？!?]\s*(?=\n|$)/u', '$1', $reply);
        $reply = preg_replace('/\s+/u', ' ', $reply);
        $reply = trim($reply);
        $reply = $this->ensureVagueSubscriptionErrorFollowup($reply, $userText);
        $reply = $this->ensureSubscriptionNoNodesAction($reply, $userText);

        return $this->compactTicketReply($reply);
    }

    private function ensureSubscriptionNoNodesAction($reply, $userText)
    {
        $text = (string)$userText;
        $isNoNodesAfterSubscription = (bool)preg_match('/订阅后[^。！？\n]*(无法使用|不能用|用不了)|购买[^。！？\n]*(订阅|套餐)[^。！？\n]*(没有节点|无节点|节点不可用)|没有节点可用|无节点/u', $text);
        if (!$isNoNodesAfterSubscription || $this->ticketPaymentOrderQuestion(['question' => $text])) {
            return $reply;
        }

        $hasClientStep = preg_match('/客户端|代理软件/u', (string)$reply);
        $hasImportStep = preg_match('/重新(添加|导入)|添加订阅|导入订阅|完整订阅链接|订阅链接|URL/u', (string)$reply);
        if (!$hasClientStep || !$hasImportStep) {
            $reply = $this->ensureTicketAiIdentity($reply);
            $reply .= "\n请先删除客户端里的旧订阅，再回到面板复制最新的完整订阅链接，到代理客户端里选择添加订阅或 URL 导入后重新添加一次。";
        }

        return $reply;
    }

    private function ensureVagueSubscriptionErrorFollowup($reply, $userText)
    {
        $text = (string)$userText;
        $isVagueSubscriptionError = (bool)preg_match('/订阅(?:链接|地址)?[^。！？\n]*(报错|错误|失败)|(?:新|新的)[^。！？\n]*订阅[^。！？\n]*(报错|错误|失败)/u', $text);
        if (!$isVagueSubscriptionError) {
            return $reply;
        }
        if ($this->ticketUserProvidedClientInfo($text)) {
            return $reply;
        }
        if (preg_match('/x509|certificate|domain_resolver|unknown field|proxy group|timeout|not found|Hiddify|Loon|Clash|Meta|Mihomo|Shadowrocket|Surge|Stash/i', $text)) {
            return $reply;
        }

        $needsClient = !preg_match('/客户端|代理软件/u', (string)$reply);
        $needsError = !preg_match('/报错文字|具体提示|提示文字|文字提示|弹出|什么内容/u', (string)$reply);
        if ($needsClient || $needsError) {
            $reply = $this->ensureTicketAiIdentity($reply);
            $reply .= "\n请把代理客户端名称、版本和弹出的具体报错文字发给我哦，这样我能更快判断是客户端版本、订阅导入还是网络问题。";
        }

        return $reply;
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
            '如果发布页里的入口也打不开，请把打不开的是国内站、海外站还是发布页本身，以及页面上的具体报错文字发来，我再继续帮你看。'
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
        $text = mb_strtolower(trim((string)($context['question'] ?? '') . "\n" . $this->ticketRoleText($context, 'user') . "\n" . (string)($context['ticket']['subject'] ?? '')));
        $hasPayment = $this->ticketPaymentOrderQuestion($context);
        if (preg_match('/购买了?.*(订阅|套餐).*?(无法使用|不能用|没有节点|无节点|节点不可用|节点)|订阅后无法使用/u', $text)) {
            $hasPayment = false;
        }
        $hasClient = preg_match('/loon|surge|shadowrocket|小火箭|clash|stash|sing-box|singbox|v2rayn|hiddify|openclash|passwall|订阅|导入|节点|客户端|测速|延迟/u', $text);
        $hasAccount = preg_match('/登录|密码|验证码|邮箱|账号|找回/u', $text);

        if ($hasPayment) {
            return [
                'issue_type' => 'payment_or_order',
                'must_do' => [
                    'Only discuss order status, payment status, recharge, package activation, and missing payment evidence.',
                    'If order number or payment time is already provided, acknowledge it and do not ask for it again.',
                    'If more information is needed, ask only for order number and payment time. Do not ask for screenshots because tickets are text-only.'
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

    private function ticketPaymentOrderQuestion(array $context)
    {
        $text = mb_strtolower(trim((string)($context['question'] ?? '') . "\n" . $this->ticketRoleText($context, 'user') . "\n" . (string)($context['ticket']['subject'] ?? '')));
        $isPayment = (bool)preg_match('/付款|支付|订单|充值|扣款|套餐没开通|未到账|没到账|未到帐|没到帐|余额|退款|退费|退钱|退订/u', $text);
        if (preg_match('/购买了?.*(订阅|套餐).*?(无法使用|不能用|没有节点|无节点|节点不可用|节点)|订阅后无法使用/u', $text)) {
            $isPayment = false;
        }
        return $isPayment;
    }

    private function selectTicketKnowledge(array $context, array $config = [], $limit = 6)
    {
        $text = $this->ticketKnowledgeSearchText($context);
        $remote = $this->selectRemoteTicketKnowledge($text, $config, $limit);
        if (!empty($remote)) {
            return $this->prioritizeTicketExactErrorKnowledgeItems($remote);
        }

        $knowledge = $this->ticketKnowledgeBase($config);
        if (!$knowledge) {
            return [];
        }

        $text = mb_strtolower($text);
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
                        'answer_points' => array_map(function ($point) {
                            return $this->trimText((string)$point, 120);
                        }, array_slice((array)($item['answer_points'] ?? []), 0, 3))
                    ]
                ];
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $scored = $this->prioritizeTicketExactErrorKnowledgeRows($scored);

        return array_map(function ($row) {
            return $row['item'];
        }, array_slice($scored, 0, $limit));
    }

    private function selectTicketReplyExamples(array $context, $limit = 3)
    {
        $examples = $this->ticketReplyExampleBase();
        if (!$examples) {
            return [];
        }

        $text = mb_strtolower($this->ticketKnowledgeSearchText($context));
        $scored = [];
        $genericKeywords = [
            '节点', '订阅', '更新', '报错', '导入', '支付', '登录', '购买', '充值',
            '流量', '超时', '小火箭', 'Clash', 'Shadowrocket', 'Loon'
        ];
        foreach ($examples as $item) {
            $score = 0;
            $specificHits = 0;
            foreach ((array)($item['keywords'] ?? []) as $keyword) {
                $keyword = trim((string)$keyword);
                if ($keyword === '') {
                    continue;
                }
                if (in_array($keyword, $genericKeywords, true) || mb_strlen($keyword) < 3) {
                    continue;
                }
                if (mb_stripos($text, mb_strtolower($keyword)) !== false) {
                    $score += max(2, mb_strlen($keyword));
                    $specificHits++;
                }
            }
            if ($score > 0 && $specificHits > 0) {
                $scored[] = [
                    'score' => $score,
                    'item' => $item
                ];
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $items = [];
        foreach (array_slice($scored, 0, max(1, (int)$limit)) as $row) {
            $item = $row['item'];
            $items[] = [
                'title' => (string)($item['title'] ?? ''),
                'tags' => array_slice((array)($item['tags'] ?? []), 0, 6),
                'user_message' => $this->sanitizeTicketContextText($this->trimText((string)($item['user_message'] ?? ''), 220)),
                'staff_reply' => $this->sanitizeTicketContextText($this->trimText((string)($item['staff_reply'] ?? ''), 420)),
                'usage_note' => (string)($item['usage_note'] ?? '')
            ];
        }

        return $items;
    }

    private function prioritizeTicketExactErrorKnowledgeRows(array $scored)
    {
        $blocked = $this->ticketKnowledgeBlockedIdsForTop($scored[0]['item']['id'] ?? '');
        if (!$blocked) {
            return $scored;
        }

        return array_values(array_filter($scored, function ($row) use ($blocked) {
            return !in_array($row['item']['id'] ?? '', $blocked, true);
        }));
    }

    private function prioritizeTicketExactErrorKnowledgeItems(array $items)
    {
        $blocked = $this->ticketKnowledgeBlockedIdsForTop($items[0]['id'] ?? '');
        if (!$blocked) {
            return $items;
        }

        return array_values(array_filter($items, function ($item) use ($blocked) {
            return !in_array($item['id'] ?? '', $blocked, true);
        }));
    }

    private function ticketKnowledgeBlockedIdsForTop($id)
    {
        switch ($id) {
            case 'ticket_exact_error_first':
                return [
                    'subscription_import_general_order',
                    'subscription_import_empty_generic',
                    'router_import_proxy_on'
                ];
            case 'proxy_ip_certificate_warning':
                return [
                    'cert_domain_mismatch_user',
                    'openclash_core_mismatch',
                    'router_import_proxy_on',
                    'subscription_import_general_order',
                    'subscription_import_empty_generic',
                    'model_category_guard_client'
                ];
            case 'all_nodes_timeout_local_network':
            case 'node_all_timeout_after_minutes':
                return [
                    'clash_update_after_minutes_timeout',
                    'subscription_import_general_order',
                    'subscription_import_empty_generic',
                    'model_category_guard_client'
                ];
            case 'hiddify_singbox_parser_domain_resolver':
                return [
                    'hiddify_empty_profile',
                    'subscription_proxy_connection_reset',
                    'proxy_ip_certificate_warning'
                ];
            case 'clash_party_proxy_group_not_found':
                return [
                    'clash_update_after_minutes_timeout',
                    'v2rayng_import_zero_config',
                    'model_category_guard_client'
                ];
            default:
                return [];
        }
    }

    private function selectRemoteTicketKnowledge($text, array $config, $limit)
    {
        $baseUrl = rtrim((string)($config['ticket_ai_knowledge_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return [];
        }

        try {
            $headers = [
                'Content-Type' => 'application/json'
            ];
            $apiKey = trim((string)($config['ticket_ai_knowledge_api_key'] ?? ''));
            if ($apiKey !== '') {
                $headers['X-Knowledge-Key'] = $apiKey;
            }

            $client = new Client([
                'timeout' => 4,
                'connect_timeout' => 2,
                'http_errors' => false
            ]);
            $response = $client->post($baseUrl . '/api/search', [
                'headers' => $headers,
                'json' => [
                    'query' => $this->trimText((string)$text, 5000),
                    'limit' => max(1, min(10, (int)$limit))
                ]
            ]);

            if ($response->getStatusCode() >= 400) {
                return [];
            }

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
                return [];
            }

            $items = [];
            foreach ($data['items'] as $item) {
                if (!is_array($item) || empty($item['answer_points'])) {
                    continue;
                }
                $items[] = [
                    'id' => (string)($item['id'] ?? ''),
                    'title' => (string)($item['title'] ?? ''),
                    'answer_points' => array_map(function ($point) {
                        return $this->trimText((string)$point, 120);
                    }, array_slice((array)$item['answer_points'], 0, 3))
                ];
                if (count($items) >= $limit) {
                    break;
                }
            }

            return $items;
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function ticketKnowledgeSearchText(array $context)
    {
        $ticket = (array)($context['ticket'] ?? []);
        $parts = [
            (string)($context['question'] ?? ''),
            (string)($ticket['subject'] ?? ''),
            (string)($context['read_only_context_summary'] ?? ''),
            (string)($context['ops_context']['recent_changes'] ?? '')
        ];

        foreach ((array)($ticket['messages'] ?? []) as $message) {
            $parts[] = (string)($message['message'] ?? '');
        }

        return $this->sanitizeTicketContextText($this->trimText(implode("\n", array_filter($parts)), 5000));
    }

    private function ticketKnowledgeBase(array $config = [])
    {
        static $cache = [];

        $paths = $this->ticketKnowledgePaths($config);
        $cacheKey = implode('|', $paths);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $data = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $items = json_decode((string)file_get_contents($path), true);
            if (is_array($items)) {
                $data = array_merge($data, $items);
            }
        }

        return $cache[$cacheKey] = array_values(array_filter($data, function ($item) {
            return is_array($item) && !empty($item['keywords']) && !empty($item['answer_points']);
        }));
    }

    private function ticketKnowledgePaths(array $config = [])
    {
        $configured = trim((string)($config['ticket_ai_knowledge_path'] ?? ''));
        $paths = [];

        foreach (preg_split('/[\r\n,;]+/', $configured) ?: [] as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (is_dir($path)) {
                foreach (glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'ticket_knowledge*.json') ?: [] as $item) {
                    $paths[] = $item;
                }
                continue;
            }
            $paths[] = $path;
        }

        if ($paths) {
            return array_values(array_unique($paths));
        }

        return glob(resource_path('ai/ticket_knowledge*.json')) ?: [];
    }

    private function ticketReplyExampleBase()
    {
        static $examples = null;
        if ($examples !== null) {
            return $examples;
        }

        $data = [];
        foreach (glob(resource_path('ai/ticket_reply_examples*.json')) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $items = json_decode((string)file_get_contents($path), true);
            if (is_array($items)) {
                $data = array_merge($data, $items);
            }
        }

        return $examples = array_values(array_filter($data, function ($item) {
            return is_array($item)
                && !empty($item['keywords'])
                && !empty($item['user_message'])
                && !empty($item['staff_reply']);
        }));
    }

    private function callTicketModel(array $config, array $messages)
    {
        $baseUrl = rtrim((string)($config['ticket_ai_base_url'] ?? 'http://152.53.36.230:11434'), '/');
        $model = trim((string)($config['ticket_ai_model'] ?? 'qwen3:14b'));
        if ($baseUrl === '') {
            throw new RuntimeException('ticket AI base URL is empty');
        }
        if ($model === '') {
            throw new RuntimeException('ticket AI model is empty');
        }

        $client = new Client([
            'timeout' => 240,
            'connect_timeout' => 8,
            'http_errors' => false
        ]);

        if ($this->ticketModelUsesOpenAi($baseUrl, $model, $config)) {
            $apiKey = trim((string)($config['ticket_ai_api_key'] ?? ''));
            if ($apiKey === '' || $apiKey === '********') {
                $apiKey = trim((string)($config['ai_risk_api_key'] ?? ''));
            }
            if ($apiKey === '') {
                throw new RuntimeException('ticket OpenAI API key is empty');
            }

            $json = [
                'model' => $model,
                'messages' => $messages,
                'max_completion_tokens' => 420
            ];

            $response = $client->post($baseUrl . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => $json
            ]);

            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            if ($response->getStatusCode() >= 400 && $this->shouldRetryWithLegacyMaxTokens($data)) {
                unset($json['max_completion_tokens']);
                $json['max_tokens'] = 420;
                $response = $client->post($baseUrl . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $json
                ]);
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
            }
            if ($response->getStatusCode() >= 400) {
                $message = $data['error']['message'] ?? ('HTTP ' . $response->getStatusCode());
                throw new RuntimeException('ticket OpenAI request failed: ' . $message);
            }

            $content = $data['choices'][0]['message']['content'] ?? '';
            if (!$content) {
                throw new RuntimeException('ticket OpenAI returned empty response');
            }

            return $content;
        }

        $response = $client->post($baseUrl . '/api/chat', [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'model' => $model,
                'stream' => false,
                'think' => false,
                'keep_alive' => '0',
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

    private function ticketModelUsesOpenAi($baseUrl, $model, array $config)
    {
        $provider = strtolower((string)($config['ticket_ai_provider'] ?? ''));
        $base = strtolower((string)$baseUrl);
        $modelName = strtolower((string)$model);

        return $provider === 'openai'
            || strpos($base, 'api.openai.com') !== false
            || strpos($modelName, 'gpt-') === 0
            || strpos($modelName, 'o3') === 0
            || strpos($modelName, 'o4') === 0;
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
                    'content' => 'You are a realtime subscription risk engine. Return compact JSON only: {"decision":"allow|block","risk_score":0-100,"reason":"short Chinese reason"}. decision must be exactly allow or block. Use 0-100 scale. The query flag is user-controlled and can be forged; never treat flag=clash, flag=shadowrocket, or similar as proof of a normal client. Trust request_context.known_proxy_user_agent and the actual User-Agent more than flag. Known proxy clients include Shadowrocket, Clash, Mihomo, Sing-box, V2RayN, V2RayNG, Surge, Loon, Stash, Quantumult, FlowZ, Hiddify, and Karing. If current_hit.rule_type is pull_frequency and request_context.known_proxy_user_agent is true, this is usually a proxy app refresh/retry; allow unless recent_same_user_hits show clear credential sharing, many unrelated IP ranges, scanner/client mismatch, or repeated hard blocks. If actual User-Agent is browser Chrome/Safari/Firefox/Edge or social/webview and request_context.known_proxy_user_agent is false, block when current_hit.rule_type is ua_browser, ua_social, or header_browser_context, even if flag claims a proxy client. If the actual User-Agent is curl, wget, httpie, PowerShell, python-requests, Go-http-client, Postman, browser Chrome/Safari/Firefox/Edge, Telegram/Wechat/QQ webview, scanner Censys/Shodan/zgrab/nmap, or empty, block when current_hit.rule_type confirms that evidence. If current_hit.rule_type is ua_cli_fetch and User-Agent is curl/wget/httpie/PowerShell, score 90-100 and block. If rule_type is ua_api_fetch and User-Agent is python-requests/Go-http-client/Postman/axios, score 90-100 and block. If rule_type is ua_scanner, score 95-100 and block. If rule_type is node_alive_ip_over_limit, this event comes from a trusted node backend report. request.reporter_user_agent and request.reporter_ip_range identify the reporting server, never the customer or customer app, and must not increase risk. The rule reaches AI only after three consecutive two-minute windows with at least three stable broad network groups. Block with score 90-100 only when the aggregate evidence and recent_same_user_hits strongly indicate credential sharing or clearly non-household use. Mobile carrier address churn inside one broad network group, a small household, router, network switching, and normal multi-device use must be allowed. If rule_type is direct_ip_host or head_method_probe, trust the current_hit evidence and block when it indicates direct-IP access or probing. If evidence is weak or AI is unsure, allow with score below 80. For block decisions, the reason must describe why the subscription was refused; do not use suggestion or recommendation wording such as 建议. Never include full IPs, emails, tokens, or node data.'
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ]
            ], 12, 2048);
            $this->markRuntimeStatus(true, 'review_success');
            $decision = $this->parseDecision($content, $this->blockScoreForRule($config, $rule));
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
                'proxy_ip_range' => $isNodeAliveReport ? '' : $this->maskIp((string)$request->ip()),
                'x_forwarded_for_ranges' => $isNodeAliveReport ? [] : $this->maskIpList((string)$request->header('X-Forwarded-For', '')),
                'user_agent' => $isNodeAliveReport ? '' : $this->trimText($ua, 180),
                'flag' => $isNodeAliveReport ? '' : $this->trimText($flag, 80),
                'reporter_ip_range' => $isNodeAliveReport ? $this->maskIp((string)$request->ip()) : '',
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
