<?php

namespace Tests\Unit;

use App\Services\CommunityConsoleService;
use App\Services\CommunityService;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommunityAccessTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->app->instance('config', new Repository([
            'app' => ['key' => str_repeat('test-only-key-', 4)],
            'community' => ['enabled' => false],
            'community_console' => [
                'enabled' => false,
                'site' => 'fixture-site',
                'secret' => str_repeat('fixture-secret-', 4),
                'admin_id' => 1,
            ],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        foreach (['db', 'cache'] as $service) {
            $this->app->bind($service, function () {
                throw new \LogicException('Rejected requests must not reach storage.');
            });
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
    }

    private function assertRejected(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Unauthenticated request was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function testUserEndpointIsDisabledByDefault(): void
    {
        $this->assertRejected(function () {
            (new CommunityService())->authenticate(Request::create('/api/v1/user/community/history', 'POST'));
        }, 404);
    }

    /** @dataProvider invalidAuthorizations */
    public function testEnabledUserEndpointRejectsMissingOrInvalidCredentials(?string $authorization): void
    {
        $this->app['config']->set('community.enabled', true);
        $request = Request::create('/api/v1/user/community/history', 'POST');
        if ($authorization !== null) {
            $request->headers->set('authorization', $authorization);
        }
        $this->assertRejected(function () use ($request) {
            (new CommunityService())->authenticate($request);
        }, 401);
    }

    public static function invalidAuthorizations(): array
    {
        return [[null], ['invalid-token'], [str_repeat('x', 4096)]];
    }

    public function testConsoleRequiresBothFeatureSwitches(): void
    {
        foreach ([[false, false], [true, false], [false, true]] as [$community, $console]) {
            $this->app['config']->set('community.enabled', $community);
            $this->app['config']->set('community_console.enabled', $console);
            $this->assertRejected(function () {
                (new CommunityConsoleService())->authenticate(Request::create('/api/v1/guest/community/console', 'POST'));
            }, 404);
        }
    }

    /** @dataProvider invalidConsoleHeaders */
    public function testConsoleRejectsInvalidSignaturesBeforeStorage(array $overrides): void
    {
        $this->app['config']->set('community.enabled', true);
        $this->app['config']->set('community_console.enabled', true);
        $request = Request::create('/api/v1/guest/community/console', 'POST', [], [], [], [], '{}');
        $headers = array_merge([
            'X-Console-Site' => 'fixture-site',
            'X-Console-Time' => (string)time(),
            'X-Console-Nonce' => str_repeat('a', 64),
            'X-Console-Signature' => str_repeat('0', 64),
        ], $overrides);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $this->assertRejected(function () use ($request) {
            (new CommunityConsoleService())->authenticate($request);
        }, 401);
    }

    public static function invalidConsoleHeaders(): array
    {
        return [
            [[]],
            [['X-Console-Site' => 'another-site']],
            [['X-Console-Time' => '1000000000']],
            [['X-Console-Nonce' => 'invalid']],
            [['X-Console-Signature' => 'invalid']],
        ];
    }
}
