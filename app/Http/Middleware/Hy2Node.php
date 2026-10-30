<?php
namespace App\Http\Middleware;

use App\Models\ServerV2node;
use Closure;
use Illuminate\Http\Request;

class Hy2Node
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('X-V2node-Contract') !== 'hy2-1') {
            return response()->json(['error' => 'contract_mismatch'], 426);
        }
        $expected = (string) config('v2board.server_token');
        $token = $request->bearerToken();
        if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $id = $request->header('X-Node-ID', '');
        if (!ctype_digit($id) || (int) $id < 1) return response()->json(['error' => 'invalid_node'], 422);
        $node = ServerV2node::find((int) $id);
        if (!$node) return response()->json(['error' => 'node_not_found'], 404);
        if ($node->protocol !== 'hysteria2') return response()->json(['error' => 'hy2_required'], 409);
        if (strlen($request->getContent()) > 2 * 1024 * 1024) {
            return response()->json(['error' => 'body_too_large'], 413);
        }
        $request->attributes->set('hy2_node', $node);
        $response = $next($request);
        $response->headers->set('X-V2node-Contract', 'hy2-1');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setVary(['Authorization', 'X-Node-ID', 'X-V2node-Contract'], false);
        return $response;
    }
}
