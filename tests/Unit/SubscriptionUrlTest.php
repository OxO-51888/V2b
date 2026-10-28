<?php

namespace Tests\Unit;

use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('cache.default'));
        url()->forceRootUrl('https://panel.fixture.invalid');
        url()->forceScheme('https');
        config(['v2board.subscribe_path' => '/api/v1/client/subscribe', 'v2board.show_subscribe_expire' => 5]);
        Schema::create('v2_user', function ($table) {
            $table->integer('id')->primary();
            $table->string('token');
        });
        DB::table('v2_user')->insert(['id' => 7, 'token' => 'fixture-token']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testNullAndEmptyOriginsPreserveAllThreeSubscriptionFormatsWithoutWarnings(): void
    {
        foreach ([null, ''] as $origin) {
            foreach ([0, 1, 2] as $method) {
                config(['v2board.subscribe_url' => $origin, 'v2board.show_subscribe_method' => $method]);
                $url = Helper::getSubscribeUrl('fixture-token');
                $this->assertStringStartsWith('https://panel.fixture.invalid/api/v1/client/subscribe?token=', $url);
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                if ($method === 0) {
                    $this->assertSame('fixture-token', $query['token']);
                } elseif ($method === 1) {
                    $this->assertNotSame('fixture-token', $query['token']);
                    $this->assertSame('fixture-token', Cache::get('otpn_' . $query['token']));
                    $this->assertSame($url, Helper::getSubscribeUrl('fixture-token'));
                } else {
                    $this->assertMatchesRegularExpression('/\A7:[a-f0-9]{40}\z/', Helper::base64DecodeUrlSafe($query['token']));
                }
            }
        }
    }

    public function testMissingOriginAndEmptyPathUseExistingDefaults(): void
    {
        config()->offsetUnset('v2board.subscribe_url');
        config(['v2board.show_subscribe_method' => 0, 'v2board.subscribe_path' => null]);
        $this->assertSame('https://panel.fixture.invalid/api/v1/client/subscribe?token=fixture-token', Helper::getSubscribeUrl('fixture-token'));
    }

    public function testConfiguredIpAndCustomSubscriptionPathAreNotRewritten(): void
    {
        config(['v2board.subscribe_url' => 'https://47.242.178.159:51818',
            'v2board.subscribe_path' => '/custom/subscribe', 'v2board.show_subscribe_method' => 0]);
        $this->assertSame('https://47.242.178.159:51818/custom/subscribe?token=fixture-token', Helper::getSubscribeUrl('fixture-token'));
    }

    public function testMultipleConfiguredSubscriptionDomainsRemainSelectable(): void
    {
        config(['v2board.subscribe_url' => 'https://one.example.com,https://two.example.com', 'v2board.show_subscribe_method' => 0]);
        for ($i = 0; $i < 20; $i++) {
            $this->assertContains(Helper::getSubscribeUrl('fixture-token'), [
                'https://one.example.com/api/v1/client/subscribe?token=fixture-token',
                'https://two.example.com/api/v1/client/subscribe?token=fixture-token',
            ]);
        }
    }
}
