<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\ClientGateway\Entry;
use App\Services\ClientGateway\Gateway;
use App\Services\ClientGateway\EntryRouter;
use App\Services\ClientGateway\GatewayException;
use Illuminate\Http\Request;

class PanelGatewayEntryController extends Controller
{
    public function relay(Request $request, string $endpoint, Entry $entry, Gateway $gateway)
    {
        abort_unless(config('panel_modules.gateway.entry_enabled'), 404);
        if ($endpoint === 'probe' && $request->isMethod('GET')) {
            return response()->json($gateway->entryProbe((string) $request->query('challenge', '')))->header('Cache-Control', 'no-store');
        }
        abort_unless(in_array($endpoint, ['health', 'api/public-key', 'api/client/verify', 'api/client/heartbeat', 'api/client/deactivate'], true)
            || preg_match('#\Aapi/gateway/panel/[a-zA-Z0-9/_-]+\z#', $endpoint), 404);
        abort_if(strlen($request->getContent()) > 4 * 1024 * 1024, 413);
        try {
            $target = (new EntryRouter())->dispatch($request, $endpoint);
            if ($target !== null) {
                $query = http_build_query($request->query(), '', '&', PHP_QUERY_RFC3986);
                return response('', 200)->header('X-Accel-Redirect', '/__panel_gateway_dispatch/' . $target . '/' . $endpoint . ($query === '' ? '' : '?' . $query));
            }
        } catch (GatewayException $e) {
            if ($endpoint === 'api/client/deactivate' && $e->status === 401) {
                return response()->json(['ok' => true, 'message' => '已退出授权会话'])->header('Cache-Control', 'no-store');
            }
            return response()->json(['ok' => false, 'authorized' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->status)->header('Cache-Control', 'no-store');
        }
        abort_unless(($gateway->entryPanel()['entryDomain'] ?? '') === strtolower($request->getHost()), 403);
        $routed = $request->server('PANEL_GATEWAY_TARGET');
        abort_unless($routed === null || $routed === config('panel_modules.gateway.panel_id'), 403);
        $request->attributes->set('native_gateway_entry', true);
        [$status, $body] = $gateway->handle($request, $endpoint);
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
