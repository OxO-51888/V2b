<?php

namespace Tests\Unit;

use App\Models\SubscriptionRule;
use App\Services\AiRiskService;
use App\Services\SubscriptionRuleService;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class NodeAliveRiskTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_mobile_carrier_addresses_share_one_network_group(): void
    {
        $evidence = $this->invoke(
            new SubscriptionRuleService(),
            'buildNodeAliveEvidence',
            [[
                'node-a' => [
                    'aliveips' => [
                        '223.104.68.1_1',
                        '223.104.77.2_2',
                        '223.104.79.3_3',
                        '223.104.86.4_4',
                        '223.104.68.5_5',
                        '223.104.77.6_6',
                        '223.104.79.7_7',
                        '223.104.86.8_8',
                        '223.104.68.9_9',
                    ],
                ],
            ]]
        );

        $this->assertSame(9, $evidence['ip_count']);
        $this->assertSame(1, $evidence['network_group_count']);
        $this->assertSame(['223.104.0.0/16'], $evidence['network_groups']);
    }

    public function test_ipv6_addresses_are_grouped_by_64_bit_prefix(): void
    {
        $evidence = $this->invoke(
            new SubscriptionRuleService(),
            'buildNodeAliveEvidence',
            [[
                'node-a' => [
                    'aliveips' => [
                        '2001:db8:1234:5678::1_1',
                        '2001:db8:1234:5678::2_2',
                        '2001:db8:1234:5679::1_3',
                    ],
                ],
            ]]
        );

        $this->assertSame(3, $evidence['ip_count']);
        $this->assertSame(2, $evidence['network_group_count']);
    }

    public function test_three_consecutive_windows_with_stable_groups_qualify(): void
    {
        $service = new SubscriptionRuleService();
        $groups = ['10.1.0.0/16', '20.1.0.0/16', '30.1.0.0/16'];
        $ruleId = 71001;
        $userId = 81001;

        $first = $this->invoke($service, 'advanceNodeAliveState', [$ruleId, $userId, $groups, 1200]);
        $second = $this->invoke($service, 'advanceNodeAliveState', [$ruleId, $userId, $groups, 1320]);
        $third = $this->invoke($service, 'advanceNodeAliveState', [$ruleId, $userId, $groups, 1440]);

        $this->assertSame(1, $first['consecutive_windows']);
        $this->assertSame(2, $second['consecutive_windows']);
        $this->assertSame(3, $third['consecutive_windows']);
        $this->assertSame(3, $third['stable_network_group_count']);
    }

    public function test_a_missing_window_breaks_the_streak(): void
    {
        $service = new SubscriptionRuleService();
        $groups = ['10.1.0.0/16', '20.1.0.0/16', '30.1.0.0/16'];

        $this->invoke($service, 'advanceNodeAliveState', [71002, 81002, $groups, 1200]);
        $afterGap = $this->invoke($service, 'advanceNodeAliveState', [71002, 81002, $groups, 1440]);

        $this->assertSame(1, $afterGap['consecutive_windows']);
    }

    public function test_network_groups_must_remain_stable_across_windows(): void
    {
        $service = new SubscriptionRuleService();

        $this->invoke($service, 'advanceNodeAliveState', [
            71003,
            81003,
            ['10.1.0.0/16', '20.1.0.0/16', '30.1.0.0/16'],
            1200,
        ]);
        $this->invoke($service, 'advanceNodeAliveState', [
            71003,
            81003,
            ['10.1.0.0/16', '20.1.0.0/16', '40.1.0.0/16'],
            1320,
        ]);
        $third = $this->invoke($service, 'advanceNodeAliveState', [
            71003,
            81003,
            ['10.1.0.0/16', '20.1.0.0/16', '50.1.0.0/16'],
            1440,
        ]);

        $this->assertSame(3, $third['consecutive_windows']);
        $this->assertSame(2, $third['stable_network_group_count']);
    }

    public function test_node_alive_ai_threshold_is_never_lower_than_90(): void
    {
        $rule = new SubscriptionRule();
        $rule->type = 'node_alive_ip_over_limit';

        $score = $this->invoke(
            new AiRiskService(),
            'blockScoreForRule',
            [['ai_risk_block_score' => 80], $rule]
        );

        $this->assertSame(90, $score);
    }

    public function test_ai_block_below_threshold_is_allowed(): void
    {
        $decision = $this->invoke(
            new AiRiskService(),
            'parseDecision',
            ['{"decision":"block","risk_score":85,"reason":"风险偏高"}', 90]
        );

        $this->assertFalse($decision['block']);
        $this->assertSame('allow', $decision['decision']);
        $this->assertSame(85, $decision['risk_score']);
    }

    public function test_historical_72_score_block_is_allowed(): void
    {
        $decision = $this->invoke(
            new AiRiskService(),
            'parseDecision',
            ['{"decision":"block","risk_score":72,"reason":"网络地址变化"}', 90]
        );

        $this->assertFalse($decision['block']);
        $this->assertSame('allow', $decision['decision']);
        $this->assertSame(72, $decision['risk_score']);
    }

    public function test_ai_block_at_threshold_is_enforced(): void
    {
        $decision = $this->invoke(
            new AiRiskService(),
            'parseDecision',
            ['{"decision":"block","risk_score":90,"reason":"多网络持续异常"}', 90]
        );

        $this->assertTrue($decision['block']);
        $this->assertSame('block', $decision['decision']);
    }

    public function test_reset_cooldown_can_only_be_acquired_once(): void
    {
        $service = new SubscriptionRuleService();

        $this->assertTrue($this->invoke($service, 'acquireNodeAliveResetCooldown', [81004]));
        $this->assertFalse($this->invoke($service, 'acquireNodeAliveResetCooldown', [81004]));
        $this->assertTrue($this->invoke($service, 'isNodeAliveResetCoolingDown', [81004]));
    }

    private function invoke($object, $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }
}
