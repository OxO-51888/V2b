<?php

namespace Tests\Unit;

use App\Http\Middleware\Client;
use App\Models\SubscriptionRule;
use App\Services\AiRiskService;
use App\Services\SubscriptionRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class SubscriptionPullFrequencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_frequency_threshold_blocks_the_eleventh_request(): void
    {
        $service = new SubscriptionRuleService();

        $this->assertFalse($this->invoke($service, 'pullFrequencyThresholdExceeded', [10, 10]));
        $this->assertTrue($this->invoke($service, 'pullFrequencyThresholdExceeded', [11, 10]));
    }

    public function test_frequency_rule_never_routes_a_known_proxy_client_to_ai(): void
    {
        $rule = new SubscriptionRule();
        $rule->type = 'pull_frequency';
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['flag' => 'meta']);
        $request->headers->set('User-Agent', 'mihomo.party/v1.8.7 (clash.meta)');

        $review = $this->invoke(
            new SubscriptionRuleService(),
            'shouldAiReviewAmbiguousRequest',
            [$rule, $request]
        );

        $this->assertFalse($review);
    }

    public function test_block_response_is_429_with_retry_after_and_not_an_empty_success(): void
    {
        $token = 'frequency-test-' . uniqid('', true);
        $this->invoke(new SubscriptionRuleService(), 'startPullFrequencyBlock', [$token]);

        $response = SubscriptionRuleService::blockedPullResponseForToken($token);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('Too Many Requests', $response->getContent());
        $this->assertGreaterThanOrEqual(1, (int)$response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(300, (int)$response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_middleware_blocks_a_token_before_loading_the_user(): void
    {
        $token = 'missing-user-' . uniqid('', true);
        $this->invoke(new SubscriptionRuleService(), 'startPullFrequencyBlock', [$token]);
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $token]);
        $nextCalled = false;

        $response = (new Client())->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;
            return response('next', 200);
        });

        $this->assertFalse($nextCalled);
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_more_than_sixty_blocked_attempts_extend_the_block_once_per_minute(): void
    {
        $token = 'escalation-test-' . uniqid('', true);
        $service = new SubscriptionRuleService();
        $this->invoke($service, 'startPullFrequencyBlock', [$token]);

        for ($i = 0; $i < 60; $i++) {
            SubscriptionRuleService::blockedPullResponseForToken($token);
        }
        $beforeEscalation = $this->invoke($service, 'pullFrequencyBlockState', [$token]);
        $this->assertSame(1, $beforeEscalation['strike']);

        SubscriptionRuleService::blockedPullResponseForToken($token);
        $afterEscalation = $this->invoke($service, 'pullFrequencyBlockState', [$token]);
        $this->assertSame(2, $afterEscalation['strike']);
        $this->assertSame(600, $afterEscalation['block_seconds']);

        for ($i = 0; $i < 200; $i++) {
            SubscriptionRuleService::blockedPullResponseForToken($token);
        }
        $sameMinute = $this->invoke($service, 'pullFrequencyBlockState', [$token]);
        $this->assertSame(2, $sameMinute['strike']);
    }

    public function test_block_time_grows_exponentially_and_is_capped(): void
    {
        $service = new SubscriptionRuleService();

        $this->assertSame(300, $this->invoke($service, 'pullFrequencyBlockSeconds', [1]));
        $this->assertSame(600, $this->invoke($service, 'pullFrequencyBlockSeconds', [2]));
        $this->assertSame(1200, $this->invoke($service, 'pullFrequencyBlockSeconds', [3]));
        $this->assertSame(2400, $this->invoke($service, 'pullFrequencyBlockSeconds', [4]));
        $this->assertSame(86400, $this->invoke($service, 'pullFrequencyBlockSeconds', [20]));
    }

    public function test_frequency_cache_identity_never_contains_the_raw_token(): void
    {
        $token = 'plain-secret-token';
        $hash = $this->invoke(new SubscriptionRuleService(), 'pullFrequencyTokenHash', [$token]);

        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString($token, $hash);
        $this->assertSame(hash('sha256', $token), $hash);
    }

    public function test_ai_review_lock_can_only_be_released_by_its_owner(): void
    {
        $service = new AiRiskService();
        Cache::put('AI_TEST_LOCK', 'owner-a', 20);

        $this->invoke($service, 'releaseReviewLock', ['AI_TEST_LOCK', 'owner-b']);
        $this->assertSame('owner-a', Cache::get('AI_TEST_LOCK'));

        $this->invoke($service, 'releaseReviewLock', ['AI_TEST_LOCK', 'owner-a']);
        $this->assertNull(Cache::get('AI_TEST_LOCK'));
    }

    private function invoke($object, $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($reflection->isStatic() ? null : $object, $arguments);
    }
}
