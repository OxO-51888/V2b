<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\PanelModuleService;
use Illuminate\Http\Request;
use App\Services\ClientGateway\Gateway;

class PanelGatewayController extends Controller
{
    public function relay(Request $request, PanelModuleService $service, string $endpoint)
    {
        $service->assertEnabled();
        $method = $request->method();
        $allowed = in_array("$method $endpoint", [
            'GET health', 'GET api/public-key', 'POST api/client/verify',
            'POST api/client/heartbeat', 'POST api/client/deactivate',
        ], true) || (in_array($method, ['GET', 'POST'], true)
            && preg_match('#^api/gateway/panel/(passport/auth/login|user/[A-Za-z]+/[A-Za-z]+|user/[A-Za-z]+)$#D', $endpoint));
        abort_unless($allowed, 404);
        abort_if(strlen($request->getContent()) > 4 * 1024 * 1024, 413);
        try {
            [$status, $body] = app(Gateway::class)->handle($request, $endpoint);
        } catch (\Throwable $e) {
            report($e);
            $status = 503;
            $body = ['ok' => false, 'code' => 'GATEWAY_UNAVAILABLE', 'message' => '网关暂时不可用'];
        }
        return response()->json($body, $status)
            ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
