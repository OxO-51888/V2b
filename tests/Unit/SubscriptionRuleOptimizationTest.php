<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\SubscriptionRuleController;
use App\Models\SubscriptionRule;
use App\Models\User;
use App\Modules\Core\NodeExitIpService;
use App\Modules\Security\AiRiskService;
use App\Modules\Security\SubscriptionClientIp;
use App\Modules\Security\SubscriptionRuleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class SubscriptionRuleOptimizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'v2board.trusted_proxy_ips' => ['192.0.2.10'],
            'v2board.trusted_proxy_cidrs' => ['2001:db8:100::/48'], 'v2board.ai_risk_enable' => false]);
        DB::purge('sqlite');
        Schema::create('v2_subscription_rule', function (Blueprint $t) {
            $t->increments('id');
            foreach (['type', 'name', 'action', 'remark', 'condition_value'] as $c) $t->string($c)->nullable();
            foreach (['enabled', 'sort', 'created_at', 'updated_at'] as $c) $t->integer($c)->default(0);
        });
        Schema::create('v2_subscription_rule_log', function (Blueprint $t) {
            $t->increments('id');
            foreach (['rule_id', 'user_id', 'created_at', 'updated_at', 'ai_score'] as $c) $t->integer($c)->nullable();
            foreach (['token_hash', 'rule_type', 'action', 'reason', 'matched_value', 'client_ip', 'proxy_ip',
                'x_forwarded_for', 'user_agent', 'path', 'method', 'flag', 'referer', 'accept', 'ai_decision', 'ai_reason'] as $c) $t->text($c)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function invoke($object, string $method, array $args = [])
    {
        return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
    }

    private function request(string $peer = '198.51.100.9', string $ua = 'Clash/1.0', array $headers = []): Request
    {
        $r = Request::create('https://fixture.example/api/v1/client/subscribe', 'GET', [], [], [], ['REMOTE_ADDR' => $peer]);
        $r->headers->set('User-Agent', $ua);
        foreach ($headers as $key => $value) $r->headers->set($key, $value);
        return $r;
    }

    private function user(): User
    {
        $u = new User();
        $u->forceFill(['id' => 77, 'token' => 'fixture-not-a-customer-token', 'uuid' => 'fixture-uuid',
            'u' => 0, 'd' => 0, 'transfer_enable' => 100]);
        return $u;
    }

    private function rule(string $type, string $action = 'no_nodes', int $limit = 10, int $sort = 0): SubscriptionRule
    {
        return SubscriptionRule::create(['type' => $type, 'name' => $type, 'action' => $action,
            'condition_value' => $limit, 'enabled' => 1, 'sort' => $sort]);
    }

    public function test_only_connected_trusted_proxies_can_supply_client_addresses(): void
    {
        $cases = [
            ['198.51.100.9', ['X-Forwarded-For' => '203.0.113.7, 192.0.2.10'], '198.51.100.9'],
            ['198.51.100.9', ['CF-Connecting-IP' => '203.0.113.7', 'X-Real-IP' => '203.0.113.8'], '198.51.100.9'],
            ['192.0.2.10', ['X-Forwarded-For' => '203.0.113.7, 198.51.100.9'], '198.51.100.9'],
            ['192.0.2.10', ['X-Forwarded-For' => '203.0.113.7, 192.0.2.10'], '203.0.113.7'],
            ['192.0.2.10', ['CF-Connecting-IP' => '203.0.113.7'], '203.0.113.7'],
            ['192.0.2.10', ['X-Real-IP' => '203.0.113.7'], '203.0.113.7'],
            ['192.0.2.10', ['X-Forwarded-For' => '203.0.113.7, garbage'], '192.0.2.10'],
            ['192.0.2.10', ['X-Forwarded-For' => str_repeat('1,', 33)], '192.0.2.10'],
            ['192.0.2.10', ['X-Forwarded-For' => str_repeat('x', 4097)], '192.0.2.10'],
            ['2001:db8:100::1', ['X-Forwarded-For' => '2001:db8:200:0::3'], '2001:db8:200::3'],
            ['2001:db8:200::1', ['X-Real-IP' => '203.0.113.7'], '2001:db8:200::1'],
        ];
        foreach ($cases as [$peer, $headers, $expected]) {
            $r = $this->request($peer, 'Clash', $headers);
            $this->assertSame($expected, SubscriptionClientIp::resolve($r));
            $this->assertSame($expected, $this->invoke(new SubscriptionRuleService(), 'clientIp', [$r]));
            $this->assertSame($expected, $this->invoke(new AiRiskService(), 'clientIp', [$r]));
        }
    }

    public function test_raw_socket_peer_is_not_replaced_by_framework_forwarded_ip(): void
    {
        Request::setTrustedProxies(['198.51.100.9'], Request::HEADER_X_FORWARDED_FOR);
        try {
            $r = $this->request('198.51.100.9', 'Clash', ['X-Forwarded-For' => '192.0.2.10']);
            $this->assertSame('192.0.2.10', $r->ip());
            $this->assertSame('198.51.100.9', SubscriptionClientIp::resolve($r));
        } finally { Request::setTrustedProxies([], 0); }
    }

    public function test_ipv4_ipv6_validation_and_node_observations(): void
    {
        foreach (['192.0.2.0/24', '0.0.0.0/0', '2001:db8::/32', '::1/128'] as $cidr) $this->assertTrue(SubscriptionClientIp::validCidr($cidr));
        foreach (['192.0.2.0/33', '::/129', '::/-1', '::/abc', 'junk/24'] as $cidr) $this->assertFalse(SubscriptionClientIp::validCidr($cidr));
        $controller = new SubscriptionRuleController();
        $this->assertSame(['192.0.2.10', '2001:db8::1'], $this->invoke($controller, 'normalizeTrustedProxyIps', ["192.0.2.10\n2001:db8::1"]));
        $this->assertSame(['2001:db8::/32'], $this->invoke($controller, 'normalizeTrustedProxyCidrs', ['2001:db8::/32']));
        $service = new NodeExitIpService();
        $r = $this->request('2001:db8:100::1', 'node', ['X-Forwarded-For' => '2001:db8:200::9']);
        $record = $service->observe($r, 'v2ray', 1, 'fixture');
        $this->assertSame('2001:db8:200::9', $record['ip']);
        $this->assertTrue($service->isNodeExitIp($record['ip']));
        $untrusted = $this->request('198.51.100.9', 'node', ['X-Real-IP' => '203.0.113.9']);
        $this->assertSame('198.51.100.9', $service->observe($untrusted, 'v2ray', 2)['ip']);
        $this->assertNull($service->observe($this->request('192.0.2.10'), 'v2ray', 3));
    }

    public function test_expired_ip_spread_entries_are_pruned_without_losing_recent_ones(): void
    {
        $this->rule('ip_spread', 'no_nodes', 2);
        $u = $this->user();
        $key = 'SUB_RULE_IP_SPREAD_' . $u->id . '_' . $u->token;
        Cache::put($key, ['203.0.113.1' => time()-601, '203.0.113.2' => time()-600,
            '203.0.113.3' => time()-100, '203.0.113.4' => time()+30, 'broken' => 'bad'], 600);
        $service = new SubscriptionRuleService();
        $this->assertNull($this->invoke($service, 'guardIpSpread', [$this->request(), $u]));
        $this->assertCount(2, Cache::get($key));
        $response = $this->invoke($service, 'guardIpSpread', [$this->request('198.51.100.10'), $u]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('fixture-not-a-customer-token', $u->token);
    }

    public function test_rule_priority_is_preserved_with_one_query_per_instance(): void
    {
        $this->rule('ua_cli_fetch', 'no_nodes', 10, 5);
        $preferred = $this->rule('ua_blacklist', 'no_nodes', 10, 1);
        $newest = $this->rule('ua_cli_fetch', 'no_nodes', 10, 1);
        $service = new SubscriptionRuleService();
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame($newest->id, $this->invoke($service, 'firstEnabledRule', [['ua_cli_fetch', 'ua_blacklist']])->id);
        $this->assertSame($preferred->id, $this->invoke($service, 'firstEnabledRule', [['ua_blacklist']])->id);
        $this->assertNull($this->invoke($service, 'firstEnabledRule', [['ip_spread']]));
        $this->assertCount(1, DB::getQueryLog()); DB::disableQueryLog();
        $newest->enabled = 0; $newest->save();
        $this->assertSame($preferred->id, $this->invoke(new SubscriptionRuleService(), 'firstEnabledRule', [['ua_cli_fetch', 'ua_blacklist']])->id);
    }

    public function test_known_clients_still_work_and_blocked_requests_are_not_empty_successes(): void
    {
        foreach (['ua_scanner', 'ua_cli_fetch', 'ua_api_fetch', 'ua_browser', 'ua_social', 'ua_vendor', 'empty_user_agent'] as $type) $this->rule($type);
        $u = $this->user(); $before = $u->getAttributes();
        foreach (['Clash/1', 'mihomo/1', 'Shadowrocket/1', 'sing-box/1', 'Surge/1', 'Loon/1', 'Stash/1',
            'Quantumult X/1', 'V2rayN/1', 'V2rayNG/1', 'Hiddify/1', 'Karing/1', 'Go-http-client/1.1'] as $ua) {
            $this->assertNull((new SubscriptionRuleService())->guardSubscribe($this->request('198.51.100.9', $ua), $u), $ua);
        }
        foreach (['curl/8', 'wget/1', 'python-requests/2', 'Postman/1', 'axios/1', 'Censys', 'Shodan',
            'Mozilla/5.0 Chrome/125', 'Telegram', ''] as $ua) {
            $response = (new SubscriptionRuleService())->guardSubscribe($this->request('198.51.100.9', $ua), $u);
            $this->assertSame(403, $response->getStatusCode(), $ua);
            $this->assertNotSame('', $response->getContent());
        }
        $this->assertSame($before, $u->getAttributes());
    }

    public function test_node_exit_bypass_is_limited_to_same_ip_multi_user_rule(): void
    {
        $this->rule('ip_multi_user', 'no_nodes', 1);
        $this->rule('ua_cli_fetch');
        $r = $this->request(); $u = $this->user();
        (new NodeExitIpService())->observe($r, 'v2ray', 1);
        Cache::put('SUB_RULE_IP_USERS_' . md5('198.51.100.9'), [1 => time(), 2 => time()], 600);
        $this->assertNull((new SubscriptionRuleService())->guardSubscribe($r, $u));
        $this->assertSame(403, (new SubscriptionRuleService())->guardSubscribe($this->request('198.51.100.9', 'curl/8'), $u)->getStatusCode());
    }

    public function test_all_historical_client_exceptions_are_shared_with_ai(): void
    {
        $all = (new \ReflectionClass(SubscriptionRuleService::class))->getConstant('GOOD_UA');
        $all[] = 'Go-http-client';
        $ai = new AiRiskService();
        $rule = $this->rule('untrusted_proxy_header', 'ai_review');
        foreach ($all as $ua) {
            $this->assertTrue(SubscriptionRuleService::knownProxyUserAgent($ua . '/1.0'), $ua);
            $this->assertTrue($this->invoke($ai, 'hasProxyClientUa', [$ua . '/1.0']), $ua);
            $result = $ai->reviewSubscriptionRequest($this->request('198.51.100.9', $ua . '/1.0'), $this->user(), $rule, 'fixture');
            $this->assertFalse($result['block'], $ua);
        }
        foreach (['curl/8', 'python-requests/2', 'Chrome/100', ''] as $ua) {
            $this->assertFalse(SubscriptionRuleService::knownProxyUserAgent($ua), $ua);
        }
    }

    public function test_mobile_churn_and_small_household_do_not_reach_user_mutations(): void
    {
        $this->rule('node_alive_ip_over_limit', 'ai_review', 8);
        $service = new SubscriptionRuleService();
        $sets = [range(1, 30), range(1, 30)];
        foreach ($sets as $index => $addresses) {
            $alive = array_map(fn ($n) => ($index === 0 || $n < 16 ? '223.104.' : '183.12.') . $n . '.1_1', $addresses);
            $this->assertNull($service->guardNodeAliveIp($this->request('198.51.100.9', 'Go-http-client/1.1'), 77,
                ['fixture' => ['aliveips' => $alive]], count($alive), 'v2ray', 1));
        }
        // No user table exists: reaching a user lookup or secret reset would fail this fixture.
        $this->assertSame(0, DB::table('v2_subscription_rule_log')->count());
    }

    public function test_deterministic_rules_skip_ai_and_do_not_claim_a_model_call(): void
    {
        $service = new StubSubscriptionAi();
        config(['v2board.ai_risk_enable' => true, 'v2board.ai_risk_api_key' => 'fixture-only']);
        $result = $service->reviewSubscriptionRequest($this->request('198.51.100.9', 'curl/8'), $this->user(), $this->rule('ua_cli_fetch', 'ai_review'), 'fixture');
        $this->assertTrue($result['block']); $this->assertSame('local_rule', $result['source']);
        $this->assertSame(0, $service->calls);
        $this->assertNull(Cache::get('SUBSCRIPTION_RULE_AI_RUNTIME_STATUS'));
    }

    public function test_ai_budget_cache_and_global_slots_preserve_fallback(): void
    {
        config(['v2board.ai_risk_enable' => true, 'v2board.ai_risk_api_key' => 'fixture-only']);
        $service = new StubSubscriptionAi(); $r = $this->request(); $u = $this->user(); $rule = $this->rule('ip_spread', 'ai_review');
        $prefix = 'AI_SUB_REVIEW_SLOT_' . hash('sha256', base_path()) . '_';
        $a = Cache::lock($prefix . '0', 15); $b = Cache::lock($prefix . '1', 15);
        $this->assertTrue($a->get()); $this->assertTrue($b->get());
        $busy = $service->reviewSubscriptionRequest($r, $u, $rule, 'fixture');
        $this->assertFalse($busy['block']); $this->assertSame(0, $service->calls);
        $a->release(); $b->release();
        $result = $service->reviewSubscriptionRequest($r, $u, $rule, 'fixture');
        $this->assertTrue($result['block']); $this->assertSame(1, $service->calls); $this->assertSame(5, $service->budget);
        $cached = $service->reviewSubscriptionRequest($r, $u, $rule, 'fixture');
        $this->assertTrue($cached['cached']); $this->assertSame(1, $service->calls);
        $this->assertTrue($a->get()); $this->assertTrue($b->get()); $a->release(); $b->release();
    }

    public function test_ai_failure_releases_slots_and_does_not_expose_upstream_errors(): void
    {
        config(['v2board.ai_risk_enable' => true, 'v2board.ai_risk_api_key' => 'fixture-only']);
        $s = new StubSubscriptionAi(); $s->fail = true;
        $result = $s->reviewSubscriptionRequest($this->request(), $this->user(), $this->rule('ip_spread', 'ai_review'), 'fixture');
        $this->assertFalse($result['block']); $this->assertStringNotContainsString('sensitive', $result['reason']);
        $a = Cache::lock('AI_SUB_REVIEW_SLOT_' . hash('sha256', base_path()) . '_0', 15);
        $this->assertTrue($a->get()); $a->release();
    }

    public function test_duplicate_inflight_review_does_not_wait_one_and_a_half_seconds(): void
    {
        config(['v2board.ai_risk_enable' => true, 'v2board.ai_risk_api_key' => 'fixture-only']);
        $r = $this->request(); $u = $this->user(); $rule = $this->rule('ip_spread', 'ai_review');
        $key = 'AI_SUB_REVIEW_' . hash('sha256', 'gpt-5-nano') . '_' . md5(implode('|', [$u->id, $u->token, $rule->id, 'fixture', '', '198.51.100.9', 'Clash/1.0', '']));
        Cache::put($key . '_INFLIGHT', 'another-owner', 20);
        $start = microtime(true); $s = new StubSubscriptionAi();
        $result = $s->reviewSubscriptionRequest($r, $u, $rule, 'fixture');
        $this->assertFalse($result['block']); $this->assertLessThan(.5, microtime(true)-$start);
        $this->assertSame('another-owner', Cache::get($key . '_INFLIGHT'));
    }
}

class StubSubscriptionAi extends AiRiskService
{
    public int $calls = 0;
    public int $budget = 0;
    public bool $fail = false;

    protected function callModel(array $config, array $messages, $timeout, $maxTokens)
    {
        $this->calls++; $this->budget = $timeout;
        if ($this->fail) throw new \RuntimeException('sensitive upstream error');
        return '{"decision":"block","risk_score":95,"reason":"fixture"}';
    }
}
