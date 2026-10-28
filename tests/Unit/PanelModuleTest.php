<?php

namespace Tests\Unit;

use App\Services\PanelModuleService;
use App\Http\Routes\V1\PanelModuleRoute;
use App\Http\Controllers\V1\Admin\PanelModuleController;
use App\Http\Controllers\V1\Guest\PanelGatewayController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PanelModuleTest extends TestCase
{
    private $app;
    private $service;
    private $history = [];
    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir() . '/panel-modules-fixture');
        $this->app->instance('config', new Repository([
            'app' => ['key' => 'fixture'], 'v2board' => ['secure_path' => 'fixture-admin'],
            'panel_modules' => ['enabled' => true, 'site' => 'pianyi',
                'gateway' => ['url' => 'http://127.0.0.1:18876', 'token' => str_repeat('g', 64), 'panel_id' => 'panel_06'],
                'telegram' => ['url' => 'https://bot.example.invalid/pianyi', 'token' => str_repeat('t', 64), 'chat_id' => '-123']],
        ]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        $this->service = new PanelModuleService();
    }
    private function fake(array $responses = []): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->service = new PanelModuleService(new Client(['handler' => $stack]));
    }
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
    }
    private function rejects(int $status, callable $fn): void
    {
        try { $fn(); $this->fail('Expected rejection'); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }
    public function testDisabledModulesHaveNoRoutes(): void
    {
        config(['panel_modules.enabled' => false]);
        $router = new Router(new Dispatcher($this->app), $this->app);
        (new PanelModuleRoute())->map($router);
        $this->assertCount(0, $router->getRoutes());
        $this->rejects(404, fn() => $this->service->request('gateway', 'GET', '/api/admin/panels'));
    }
    public function testAllManagementRoutesRequireAdmin(): void
    {
        $router = new Router(new Dispatcher($this->app), $this->app);
        (new PanelModuleRoute())->map($router);
        $routes = iterator_to_array($router->getRoutes());
        $this->assertCount(9, $routes);
        $this->assertNotContains('fixture-admin/modules/gateway/configure', array_map(fn($route) => $route->uri(), $routes));
        foreach ($routes as $route) {
            if (strpos($route->uri(), 'fixture-admin/') === 0) $this->assertContains('admin', $route->middleware());
        }
    }
    public function testSubscriptionEntryRoutesAreExplicitAndCanBeDisabled(): void
    {
        $router = new Router(new Dispatcher($this->app), $this->app);
        $this->app->instance('router', $router);
        $router->group(['middleware' => 'api'], function () {
            require __DIR__ . '/../../routes/client-gateway.php';
        });
        $routes = iterator_to_array($router->getRoutes());
        $this->assertCount(6, $routes);
        $this->assertSame(['health', 'api/public-key', 'api/client/verify', 'api/client/heartbeat',
            'api/client/deactivate', 'api/gateway/panel/{path}'], array_map(fn($route) => $route->uri(), $routes));
        foreach ($routes as $route) $this->assertContains('api', $route->middleware());
        $this->assertSame(['POST'], $routes[2]->methods());
        $this->assertSame('api/client/verify', $routes[2]->defaults['endpoint']);
        config(['panel_modules.enabled' => false]);
        $disabled = new Router(new Dispatcher($this->app), $this->app);
        $this->app->instance('router', $disabled);
        Facade::clearResolvedInstance('router');
        require __DIR__ . '/../../routes/client-gateway.php';
        $this->assertCount(0, $disabled->getRoutes());
    }
    public function testOwnTenantAndCredentialsArePinnedServerSide(): void
    {
        $this->fake([new Response(200, [], json_encode(['data' => ['site' => 'pianyi', 'group' => ['chat_id' => -123]]]))]);
        $data = $this->service->request('telegram', 'GET', '/v1/state');
        $this->assertSame('pianyi', $data['site']);
        $this->assertSame('https://bot.example.invalid/pianyi/v1/state', (string) $this->history[0]['request']->getUri());
        $this->assertSame('Bearer ' . str_repeat('t', 64), $this->history[0]['request']->getHeaderLine('Authorization'));
    }
    public function testCrossSiteResponseIsRejected(): void
    {
        $this->fake([new Response(200, [], json_encode(['data' => ['site' => 'ovo', 'group' => ['chat_id' => -123]]]))]);
        $this->rejects(503, fn() => $this->service->request('telegram', 'GET', '/v1/state'));
    }
    public function testGatewayManagementUsesNativeServiceWithoutHttp(): void
    {
        $this->fake();
        $native = new class extends \App\Services\ClientGateway\Gateway {
            public $calls = [];
            public function __construct() {}
            public function admin(string $method, string $path, array $data): array {
                $this->calls[] = [$method, $path, $data];
                return ['panels' => [['id' => 'panel_06']]];
            }
        };
        $this->app->instance(\App\Services\ClientGateway\Gateway::class, $native);
        $this->assertSame('panel_06', $this->service->request('gateway', 'GET', '/api/admin/panels')['panels'][0]['id']);
        $this->assertSame([['GET', '/api/admin/panels', []]], $native->calls);
        $this->assertCount(0, $this->history);
    }
    public function testNoArbitraryModulePathOrRemotePlaintextUrl(): void
    {
        $this->fake();
        $this->rejects(404, fn() => $this->service->request('telegram', 'POST', '/delete-account'));
        config(['panel_modules.telegram.url' => 'http://bot.example.invalid']);
        $this->rejects(503, fn() => $this->service->request('telegram', 'GET', '/v1/state'));
        $this->assertCount(0, $this->history);
    }
    public function testStaleAndUnavailableRepliesRemainFailures(): void
    {
        $this->fake([new Response(409), new Response(500)]);
        $this->rejects(409, fn() => $this->service->request('telegram', 'POST', '/v1/group'));
        $this->rejects(503, fn() => $this->service->request('telegram', 'GET', '/v1/state'));
    }
    public function testTenantAndUserMutationParametersAreRejected(): void
    {
        $controller = new PanelModuleController();
        foreach (['site', 'chat_id', 'token', 'telegram_id', 'transfer_enable'] as $key) {
            $request = Request::create('/', 'POST', [$key => 'fixture']);
            $this->rejects(422, fn() => $controller->telegramSave($request, $this->service));
            $this->rejects(422, fn() => $controller->gatewaySave($request, $this->service));
        }
    }
    public function testMiddlewareUserContextIsAcceptedWithoutWideningInputScope(): void
    {
        $method = new \ReflectionMethod(PanelModuleController::class, 'exact');
        $request = Request::create('/', 'GET', ['offset' => 0]);
        $request->merge(['user' => ['id' => 1, 'is_admin' => 1]]);
        $method->invoke(new PanelModuleController(), $request, ['offset']);
        $this->assertSame(0, $request->input('offset'));
        $request->merge(['site' => 'ovo']);
        $this->rejects(422, fn() => $method->invoke(new PanelModuleController(), $request, ['offset']));
    }
    public function testPublicRelayCannotExposeManagementOrOtherProxyPaths(): void
    {
        $this->fake(); $controller = new PanelGatewayController();
        foreach (['api/admin/panels', 'api/admin/devices', 'api/bot/verify-user', 'api/gateway/panel/../../admin', 'api/gateway/panel/pianyiyi/config/fetch'] as $path) {
            $this->rejects(404, fn() => $controller->relay(Request::create('/', 'GET'), $this->service, $path));
        }
        $this->assertCount(0, $this->history);
    }
}
