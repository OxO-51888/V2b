<?php
namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Modules\Core\Hy2Accounting;
use App\Modules\Core\Hy2Online;
use App\Modules\Core\Hy2UserSnapshot;
use App\Modules\Core\NodeExitIpService;
use App\Modules\Core\ServerService;
use App\Models\ServerV2node;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Hy2Controller extends Controller
{
    private function node(Request $request)
    {
        return $request->attributes->get('hy2_node');
    }

    private function jsonBody(Request $request): array
    {
        if (!$request->isJson()) abort(415, 'JSON required');
        try { $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { abort(422, 'Invalid JSON'); }
        if (!is_array($data)) abort(422, 'JSON object required');
        return $data;
    }

    private function cached(Request $request, array $data)
    {
        $response = response()->json($data)->setEtag(hash('sha256', json_encode($data)));
        $response->isNotModified($request);
        return $response;
    }

    private function heartbeat(Request $request, string $kind): void
    {
        $node = $this->node($request);
        Cache::put(CacheKey::get('SERVER_V2NODE_' . $kind, $node->id), time(), 3600);
        $host = trim((string) $node->host);
        if (in_array($host, ['', '1.1.1.1', '0.0.0.0', '::'], true)) {
            $host = (string) ServerV2node::where('parent_id', $node->id)->where('show', 1)->value('host');
        }
        (new NodeExitIpService())->observe($request, 'v2node', $node->id, $node->name, $host);
    }

    public function config(Request $request)
    {
        $node = $this->node($request);
        $data = $node->only(['listen_ip', 'server_port', 'protocol', 'tls', 'tls_settings',
            'up_mbps', 'down_mbps', 'obfs', 'obfs_password']);
        $data['server_port'] = (int) $data['server_port'];
        $data['tls'] = (int) $data['tls'];
        $data['up_mbps'] = (int) $data['up_mbps'];
        $data['down_mbps'] = (int) $data['down_mbps'];
        $data['ignore_client_bandwidth'] = $data['up_mbps'] === 0 && $data['down_mbps'] === 0;
        $data['base_config'] = [
            'push_interval' => (int) config('v2board.server_push_interval', 60),
            'pull_interval' => (int) config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int) config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int) config('v2board.server_device_online_min_traffic', 0),
        ];
        $data['routes'] = $node->route_id ? (new ServerService())->getRoutes($node->route_id) : [];
        return $this->cached($request, $data);
    }

    public function users(Request $request)
    {
        $node = $this->node($request);
        $this->heartbeat($request, 'LAST_CHECK_AT');
        return (new Hy2UserSnapshot())->response($request, $node->group_id ?? []);
    }

    public function state(Request $request, Hy2Accounting $accounting)
    {
        return response()->json($accounting->cursor($this->node($request)->id));
    }

    public function traffic(Request $request, Hy2Accounting $accounting)
    {
        $body = $this->jsonBody($request);
        $digest = hash('sha256', $request->getContent());
        try {
            $ack = $accounting->apply($this->node($request)->toArray(), $body, $digest);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) throw $e;
            return response()->json([
                'error' => 'traffic_sequence_conflict',
                'request_sequence' => $body['sequence'], 'request_digest' => $digest,
            ] + $accounting->cursor($this->node($request)->id), 409);
        }
        $this->heartbeat($request, 'LAST_PUSH_AT');
        return response()->json($ack);
    }

    public function alive(Request $request, Hy2Online $online)
    {
        return response()->json(['alive' => (object) $online->counts($this->node($request)->group_id ?? [])]);
    }

    public function reportAlive(Request $request, Hy2Online $online)
    {
        $data = $this->jsonBody($request);
        $online->replace($request, $this->node($request)->id, $data);
        Cache::put(CacheKey::get('SERVER_V2NODE_ONLINE_USER', $this->node($request)->id), count($data), 3600);
        $this->heartbeat($request, 'LAST_PUSH_AT');
        return response()->json(['accepted' => true]);
    }
}
