<?php

namespace Tests\Unit;

use App\Services\AiRiskService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TicketAiReplyTest extends TestCase
{
    private function invoke($method, array $args)
    {
        $service = new AiRiskService();
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($service, $args);
    }

    public function test_formatting_does_not_replace_diagnoses_or_client_names(): void
    {
        $cases = [
            ['19号流量没重置', '重置日已过，需要人工核对周期执行情况。'],
            ['剩余27天的条目失效', '请先选择一个实际地区节点，这不是套餐过期的证明。'],
            ['shadowrocket 证书错误，不能换客户端', 'Shadowrocket 的证书错误需要核对订阅入口，不能绕过校验。'],
            ['FlClash 0.8.98 未知网络错误', 'FlClash 0.8.98 的提示尚不能说明具体原因。'],
            ['Clash Verge v2.5.2 TLS handshake timeout', 'Clash Verge 的握手超时属于订阅下载阶段，不代表所有节点超时。'],
            ['现在只剩TikTok打不开', '其他海外网站正常时，先确认 TikTok 是否走当前节点。']
        ];
        foreach ($cases as [$question, $answer]) {
            $reply = "亲亲，我是 AI 小助手。\n" . $answer;
            $context = ['question' => $question, 'ticket' => ['messages' => [['from' => 'user', 'message' => '怎么发图片？']]]];
            $this->assertSame($reply, $this->invoke('guardTicketReply', [$reply, $context]));
        }
    }

    public function test_identity_is_added_without_editing_urls_or_versions(): void
    {
        $reply = '请从 https://karing.app/download 查看安装条件，FlClash 0.8.98 的名称不能改写。';
        $this->assertSame("亲亲，我是 AI 小助手。\n" . $reply, $this->invoke('guardTicketReply', [$reply, []]));
    }

    public function test_explicit_handoff_stays_pending_across_ai_replies(): void
    {
        $service = new AiRiskService();
        $context = ['question' => '还没处理好吗？', 'ticket' => ['messages' => [
            ['from' => 'user', 'message' => '给我转人工！'],
            ['from' => 'staff', 'message' => '亲亲，我是 AI 小助手。请重新导入。']
        ]]];
        $this->assertSame('human_requested', $service->ticketAutoReplySkipReason($context));
        $context['ticket']['messages'][] = ['from' => 'staff', 'message' => '已经核对，请再试一次。', 'is_ai' => false];
        $this->assertSame('', $service->ticketAutoReplySkipReason($context));
        $context['question'] = '不用人工，继续让AI回答';
        $this->assertSame('', $service->ticketAutoReplySkipReason($context));
    }

    public function test_money_questions_skip_and_plan_change_is_not_a_payment_error(): void
    {
        $service = new AiRiskService();
        foreach (['提现100 USDT', '订单付款没到账', '申请退款', '支付成功但是节点不能用'] as $question) {
            $this->assertSame('payment_order', $service->ticketAutoReplySkipReason(['question' => $question]));
        }
        $this->assertSame('payment_order', $service->ticketAutoReplySkipReason(['question' => '多久处理？', 'ticket' => ['messages' => [
            ['from' => 'user', 'message' => '我申请提现100 USDT']
        ]]]));
        $reply = '亲亲，我是 AI 小助手。不同套餐会发生切换，月付结束不会恢复年付；购买前请先核对订单确认页面。';
        $this->assertSame('', $service->ticketAutoPublishBlockReason($reply, ['question' => '不同档位月付会覆盖年付套餐吗？']));
    }

    public function test_publish_guard_blocks_side_effect_claims_and_sensitive_requests(): void
    {
        $service = new AiRiskService();
        foreach (['已经转交工程师，等待通知就可以了。', '我会帮你检查服务器，修好后通知你。'] as $body) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $body, ['question' => '节点超时']));
        }
        foreach (['请提供邮箱和密码以便核对这个问题。', '请发送完整订阅链接以便核对问题。'] as $body) {
            $this->assertSame('sensitive_or_internal_term', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $body, ['question' => '导入失败']));
        }
        $this->assertSame('asks_for_image', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。请把报错截图发给我们，方便核对问题。', ['question' => '之前截图发过了']));
        $this->assertSame('', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。重新发送同一邀请、换邮箱或重装应用都不能增加测试名额。', ['question' => 'TestFlight满额']));
        $reply = '亲亲，我是 AI 小助手。请核对客户端的系统代理是否开启。';
        $this->assertSame('duplicate_reply', $service->ticketAutoPublishBlockReason($reply, ['question' => '已经开启', 'ticket' => ['messages' => [['from' => 'staff', 'message' => $reply]]]]));
    }

    public function test_retrieval_ignores_announcements_staff_and_other_device_snapshots(): void
    {
        $context = ['question' => 'TestFlight提示此beta版已满额', 'ops_context' => ['recent_changes' => 'HY2阻断 发布页有国内站 导入订阅不要挂梯子'],
            'read_only_context_summary' => 'Android Clash 历史命中',
            'ticket' => ['messages' => [['from' => 'staff', 'message' => 'Clash错误，应该重导订阅，打开国内站。']]]];
        $text = $this->invoke('ticketKnowledgeSearchText', [$context]);
        $this->assertStringNotContainsString('Clash', $text);
        $this->assertStringNotContainsString('国内站', $text);
        $knowledge = $this->invoke('selectTicketKnowledge', [$context, ['ticket_ai_knowledge_path' => dirname(__DIR__, 2) . '/resources/ai/ticket_knowledge_extra.json'], 4]);
        $this->assertSame('testflight_beta_full', $knowledge[0]['id']);
        $this->assertNotContains('publish_page_domestic_overseas_entry', array_column($knowledge, 'id'));
    }

    public function test_model_receives_early_user_facts_and_latest_stage(): void
    {
        $messages = [['from' => 'user', 'message' => 'macOS，Clash Party 2.0.2，已经重新导入但没解决。']];
        for ($i = 0; $i < 40; $i++) {
            $messages[] = ['from' => $i % 2 ? 'user' : 'staff', 'message' => '继续检查-' . $i];
        }
        $messages[] = ['from' => 'user', 'message' => '现在安装TestFlight，提示满额。'];
        $model = $this->invoke('ticketModelContext', [['question' => '现在怎么办？', 'ticket' => ['messages' => $messages]]]);
        $text = json_encode($model, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Clash Party 2.0.2', $text);
        $this->assertStringContainsString('提示满额', $text);
        $this->assertTrue($model['ticket']['history_truncated']);
    }

    public function test_semantic_review_requires_explicit_boolean_approval(): void
    {
        $reply = '亲亲，我是 AI 小助手。这个问题需要人工核对当前周期。';
        $this->assertSame($reply, $this->invoke('reviewedTicketReply', [json_encode(['safe_to_send' => true, 'reply' => $reply])]));
        foreach (['not json', '{}', '{"safe_to_send":"true","reply":"text"}', '{"safe_to_send":false,"reply":"text"}', '{"safe_to_send":true,"reply":[]}', '{"safe_to_send":true,"skip_reason":"payment_order","reply":"text"}'] as $content) {
            try {
                $this->invoke('reviewedTicketReply', [$content]);
                $this->fail('Unsafe review must not return a publishable reply');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('未自动发送', $exception->getMessage());
            }
        }
    }

    public function test_human_intent_variants_and_latest_cancellation(): void
    {
        $service = new AiRiskService();
        foreach (['人工回复', '麻烦安排真人接手处理，不要AI再答了。', '能帮我联系人工吗', '不要机器人回答', '不用AI，要人工处理'] as $question) {
            $this->assertSame('human_requested', $service->ticketAutoReplySkipReason(['question' => $question]));
        }
        $context = ['question' => '不需要人工了，继续让AI回答。', 'ticket' => ['messages' => [
            ['from' => 'user', 'message' => '人工回复'],
            ['from' => 'staff', 'message' => '请说明问题', 'is_ai' => true]
        ]]];
        $this->assertSame('', $service->ticketAutoReplySkipReason($context));
        $context['question'] = '现在只有YouTube不能打开';
        $context['ticket']['messages'][] = ['from' => 'staff', 'message' => '上个问题处理好了', 'is_ai' => false];
        $this->assertSame('', $service->ticketAutoReplySkipReason($context));
        $model = $this->invoke('ticketModelContext', [$context]);
        $this->assertSame('no_pending_request', $model['handoff_state']);
        $this->assertTrue($model['ticket']['messages'][1]['is_ai']);
        $this->assertFalse($model['ticket']['messages'][2]['is_ai']);
    }

    public function test_payment_followups_use_subject_and_history_regardless_of_length(): void
    {
        $service = new AiRiskService();
        foreach (['还是只有6个月啊，我充了两次6块钱了。', '按你说的我又等了一天了，怎么还是原来那样，一点没变化啊。', '怎么还不行'] as $question) {
            $this->assertSame('payment_order', $service->ticketAutoReplySkipReason([
                'question' => $question, 'ticket' => ['subject' => '付款后套餐没到账']
            ]));
            $this->assertSame('payment_order', $service->ticketAutoReplySkipReason([
                'question' => $question, 'ticket' => ['messages' => [['from' => 'user', 'message' => '付款后没到账']]]
            ]));
        }
        foreach (['不是付款或订单的问题，我只想问Clash节点全部超时怎么排查。', '并非订单问题，只是连接超时。'] as $question) {
            $this->assertSame('', $service->ticketAutoReplySkipReason([
                'question' => $question, 'ticket' => ['subject' => '订单', 'messages' => [['from' => 'user', 'message' => '以前付款问题']]]
            ]));
        }
        $this->assertSame('payment_order', $service->ticketAutoReplySkipReason(['question' => '不是付款失败，而是订单没到账。']));
    }

    public function test_negated_image_requests_are_allowed_but_positive_requests_still_block(): void
    {
        $service = new AiRiskService();
        foreach (['工单暂时不能上传图片，请把弹窗里的完整报错文字复制到工单里。', '不要上传图片或截图，只需复制弹窗的报错文字，保留当前订阅即可。'] as $text) {
            $this->assertSame('', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, ['question' => '工单传不了图']));
        }
        foreach (['不能在工单发图，但是请上传图片到其他网站。', '不要在这里上传图片，请到售后群发送截图。', '去售后群发图，我们就可以核对了。'] as $text) {
            $this->assertSame('asks_for_image', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, ['question' => '工单传不了图']));
        }
    }

    public function test_user_recovery_is_not_confused_with_support_actions(): void
    {
        $service = new AiRiskService();
        $reply = '亲亲，我是 AI 小助手。收到，更新后已经恢复正常，感谢你的反馈。';
        $this->assertSame('', $service->ticketAutoPublishBlockReason($reply, ['question' => '更新后已经好了，谢谢，不需要继续排查。']));
        foreach (['收到，订阅导入问题已恢复，感谢你的反馈。', '收到，问题已经恢复正常就好，感谢你的反馈。', '已经恢复了，感谢你的反馈，后续有问题再留言。', '太好了，听到订阅导入已经恢复，感谢你的反馈！', '既然问题已经恢复正常，就不用继续排查了。'] as $text) {
            $this->assertSame('', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, ['question' => '更新后已经好了，谢谢']));
        }
        foreach (['所有节点都超时了', '还没恢复正常'] as $question) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason($reply, ['question' => $question]));
        }
        foreach (['我已经为你修复并重置了流量。', '不能保证及时回复，但是已经转交人工。', '我已经恢复正常了，请重新导入订阅测试。', '已为你恢复正常，请重新导入订阅测试。', '已恢复订阅，请重新导入订阅测试。', '我已经帮你恢复正常，请重新导入订阅测试。', '后台已恢复正常，请重新导入订阅测试。', '已经替您恢复正常，请重新导入订阅测试。', '我帮你恢复了，请重新导入订阅测试。'] as $text) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, ['question' => '更新后已经好了，谢谢']));
        }
    }

    public function test_user_reset_acknowledgment_does_not_claim_support_action(): void
    {
        $service = new AiRiskService();
        $context = ['question' => '所有节点用不了，已经重置过还是不行'];
        $reply = '亲亲，我是 AI 小助手。你已重置过但仍是所有节点不可用，请提供连接时的具体报错原文。';
        $this->assertSame('', $service->ticketAutoPublishBlockReason($reply, $context));
        foreach (['节点用不了', '还没重置过', '没有重置过'] as $question) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason($reply, ['question' => $question]));
        }
        foreach (['我已经为你重置了订阅，现在重新导入即可。', '你已重置过，但我已经为你重置了订阅。', '已经重置了你的订阅，请重新导入。'] as $text) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, $context));
        }
    }

    public function test_explaining_a_method_is_not_a_promise_to_execute_it(): void
    {
        $service = new AiRiskService();
        $context = ['question' => '工单传不了截图，怎么办'];
        foreach (['请写出关键报错文字，我会据此判断处理方法。', '我会提供修复建议，请先描述具体报错文字。', '我会根据报错说明恢复步骤，请提供具体报错文字。'] as $text) {
            $this->assertSame('', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, $context));
        }
        foreach (['我会帮你处理这个问题，请等待回复。', '我会按照这个方法帮你修复，请等待回复。', '我会说明处理方法，然后已经为你重置流量。', '我会提出修复建议并修复服务器，请等待回复。'] as $text) {
            $this->assertSame('unsafe_manual_commitment', $service->ticketAutoPublishBlockReason('亲亲，我是 AI 小助手。' . $text, $context));
        }
    }

    public function test_image_and_certificate_knowledge_does_not_require_unavailable_capabilities(): void
    {
        $knowledge = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/ai/ticket_knowledge_extra.json'), true);
        $byId = array_column($knowledge, null, 'id');
        $this->assertCount(120, $byId);
        foreach (['admin_needs_log_screenshot', 'ticket_image_not_supported'] as $id) {
            $this->assertStringNotContainsString('先去售后群', implode(' ', $byId[$id]['answer_points']));
        }
        $this->assertStringContainsString('更新订阅还是连接节点', implode(' ', $byId['shadowrocket_vpn_cert_warning']['answer_points']));
    }
}
