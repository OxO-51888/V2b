<?php

namespace Tests\Unit;

use App\Services\ClientGateway\SubscriptionEntry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SubscriptionGatewayEntryTest extends TestCase
{
    protected function setUp(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'v2board' => ['subscribe_url' => 'https://sub.example.com', 'app_url' => 'https://panel.example.com'],
            'app' => ['url' => 'https://fallback.example.com'],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testConfiguredSubscriptionHostIsTheUpstream(): void
    {
        $this->assertSame(['https://sub.example.com'], SubscriptionEntry::upstreams());
        $this->assertTrue(SubscriptionEntry::accepts(Request::create('https://sub.example.com/health')));
        $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://panel.example.com/health')));
    }

    public function testSeveralSubscriptionDomainsAreStableAndDeduplicated(): void
    {
        config(['v2board.subscribe_url' => ' https://SUB.example.com:443/,https://second.example.com:51818,https://sub.example.com ']);
        $expected = ['https://sub.example.com', 'https://second.example.com:51818'];
        $this->assertSame($expected, SubscriptionEntry::upstreams());
        $this->assertSame($expected, SubscriptionEntry::upstreams());
        $this->assertTrue(SubscriptionEntry::accepts(Request::create('https://second.example.com:51818/health')));
    }

    public function testSubscriptionConfigurationChangesAreReflectedWithoutSavingGateway(): void
    {
        $this->assertSame('https://sub.example.com', SubscriptionEntry::upstreams()[0]);
        config(['v2board.subscribe_url' => 'https://new.example.com']);
        $this->assertSame(['https://new.example.com'], SubscriptionEntry::upstreams());
        $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://sub.example.com/health')));
    }

    public function testEmptySubscriptionUsesConfiguredPanelUrlNotRequestHost(): void
    {
        config(['v2board.subscribe_url' => '']);
        $this->assertSame(['https://panel.example.com'], SubscriptionEntry::upstreams());
        config(['v2board.app_url' => null]);
        $this->assertSame(['https://fallback.example.com'], SubscriptionEntry::upstreams());
        $request = Request::create('https://attacker.example.com/health', 'GET', ['subscribe_url' => 'https://attacker.example.com']);
        $this->assertFalse(SubscriptionEntry::accepts($request));
    }

    public function testMalformedOrSensitiveUrlsCannotBecomeUpstreams(): void
    {
        foreach (['http://sub.example.com', 'https://user:secret@sub.example.com', 'https://user@sub.example.com',
            'https://sub.example.com/?token=secret', 'https://sub.example.com/#secret', 'https://sub.example.com/private',
            'https://sub.example.com:99999', 'https://sub.example.com:0', 'https://sub.example.com\\@attacker.com',
            "https://sub.example.com\nX: injected", 'https://sub.example.com.evil/secret', 'javascript:alert(1)',
            'https://127.0.0.1', 'https://localhost', 'https://[::1]', 'https://*.example.com'] as $value) {
            config(['v2board.subscribe_url' => $value]);
            $this->assertSame([], SubscriptionEntry::upstreams(), $value);
        }
    }

    public function testNoDomainsMeansNoRootGatewayHost(): void
    {
        config(['v2board.subscribe_url' => '', 'v2board.app_url' => '', 'app.url' => '']);
        $this->assertSame([], SubscriptionEntry::upstreams());
        $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://arbitrary.example.com/health')));
    }

    public function testPublicIpSubscriptionEntranceUsesConfiguredPanelOrigin(): void
    {
        foreach (['https://47.242.178.159', 'https://47.242.178.159:51818/',
            'https://47.242.178.159, https://8.8.8.8:443/'] as $value) {
            config(['v2board.subscribe_url' => $value]);
            $this->assertSame(['https://panel.example.com'], SubscriptionEntry::upstreams());
            $this->assertTrue(SubscriptionEntry::accepts(Request::create('https://panel.example.com/health')));
            $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://47.242.178.159/health')));
        }
    }

    public function testARealSubscriptionDomainTakesPriorityOverIpEntrances(): void
    {
        config(['v2board.subscribe_url' => 'https://47.242.178.159,https://SUB.example.com,https://sub.example.com']);
        $this->assertSame(['https://sub.example.com'], SubscriptionEntry::upstreams());
        $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://panel.example.com/health')));
    }

    public function testInvalidIpEntrancesCannotEnableTheFallback(): void
    {
        foreach (['http://47.242.178.159', 'https://47.242.178.159/?token=secret',
            'https://user:secret@47.242.178.159', 'https://47.242.178.159/private',
            'https://47.242.178.159:0', 'https://10.0.0.1', 'https://169.254.169.254',
            'https://47.242.178.159,https://localhost', 'https://47.242.178.159,not-a-url', ', ,'] as $value) {
            config(['v2board.subscribe_url' => $value]);
            $this->assertSame([], SubscriptionEntry::upstreams(), $value);
        }
    }

    public function testFallbackCannotUseIpCredentialsOrRequestSuppliedOrigins(): void
    {
        config(['v2board.subscribe_url' => 'https://47.242.178.159']);
        foreach (['https://8.8.8.8', 'http://panel.example.com', 'https://user:secret@panel.example.com',
            'https://panel.example.com/path', 'https://localhost'] as $value) {
            config(['v2board.app_url' => $value]);
            $this->assertSame([], SubscriptionEntry::upstreams(), $value);
        }
        config(['v2board.app_url' => null]);
        $this->assertSame(['https://fallback.example.com'], SubscriptionEntry::upstreams());
        config(['app.url' => null]);
        $request = Request::create('https://attacker.example.com/health', 'GET', ['app_url' => 'https://attacker.example.com']);
        $request->headers->set('X-Forwarded-Host', 'panel.example.com');
        $this->assertSame([], SubscriptionEntry::upstreams());
        $this->assertFalse(SubscriptionEntry::accepts($request));
    }

    public function testNullSubscriptionAndPanelOriginChangesAreReflected(): void
    {
        config(['v2board.subscribe_url' => null]);
        $this->assertSame(['https://panel.example.com'], SubscriptionEntry::upstreams());
        config(['v2board.subscribe_url' => 'https://47.242.178.159', 'v2board.app_url' => 'https://NEW.example.com:51818/']);
        $this->assertSame(['https://new.example.com:51818'], SubscriptionEntry::upstreams());
        $this->assertFalse(SubscriptionEntry::accepts(Request::create('https://panel.example.com/health')));
    }
}
