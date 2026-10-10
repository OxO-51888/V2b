<?php

namespace Tests\Unit;

use App\Services\ClientGateway\EntryRouter;
use App\Services\ClientGateway\GatewayException;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PanelEntryRouterTest extends TestCase
{
    public function testAllSitesRouteByClientAndSessionWithoutCrossingBoundaries(): void
    {
        $router = new EntryRouter(); $policy = ['sites' => []];
        foreach (range(1, 6) as $i) $policy['sites'][] = ['panel_id' => sprintf('panel_%02d', $i)];
        foreach (array_column($policy['sites'], 'panel_id') as $panel) {
            $request = Request::create('/', 'POST', [], [], [], [], json_encode(['clientId' => $panel]));
            $this->assertSame($panel, $router->target($request, 'api/client/verify', $policy));
            foreach (['api/client/heartbeat', 'api/client/deactivate', 'api/gateway/panel/api/v1/user/info'] as $endpoint) {
                foreach ([$panel . '.fixture', 'opaque-' . $panel] as $token) {
                    $routes = [hash('sha256', 'opaque-' . $panel) => $panel];
                    $request = Request::create('/', 'POST', [], [], [], [], json_encode(['sessionToken' => $token]));
                    $this->assertSame($panel, $router->target($request, $endpoint, $policy, $routes));
                    $request = Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
                    $this->assertSame($panel, $router->target($request, $endpoint, $policy, $routes));
                }
            }
        }
    }

    public function testInvalidInputsAndUnregisteredTokensFailClosed(): void
    {
        $policy = ['sites' => [['panel_id' => 'panel_01']]]; $router = new EntryRouter();
        foreach ([['{', 400], ['null', 400], ['[1]', 400], ['{"clientId":[]}', 403],
            ['{"clientId":"panel_99"}', 403], ['{"clientId":"../../etc/passwd"}', 403]] as [$body, $status]) {
            try {
                $router->target(Request::create('/', 'POST', [], [], [], [], $body), 'api/client/verify', $policy);
                $this->fail('must reject');
            } catch (GatewayException $e) { $this->assertSame($status, $e->status); }
        }
        foreach (['', 'forged', 'panel_99.token', 'panel_01.a/../../bad', str_repeat('x', 201)] as $token) {
            try {
                $request = Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
                $router->target($request, 'api/client/heartbeat', $policy);
                $this->fail('must reject');
            } catch (GatewayException $e) { $this->assertSame(401, $e->status); }
        }
    }
}
