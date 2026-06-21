<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\NodeExitIpService;
use App\Services\ServerService;
use App\Services\SubscriptionRuleService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MessagePack\Packer;

class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2') $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(500, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        $this->observeNodeExitIp($request);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id)
            ->map(function ($user) {
                return array_filter($user->toArray(), function ($v) {
                    return !is_null($v);
                });
            })->toArray();

        $response['users'] = $users;
        if (strpos($request->header('X-Response-Format'), 'msgpack') !== false) {
            $packer = new Packer();
            $response = $packer->pack($response);
            $eTag = sha1($response);
            if (strpos($request->header('If-None-Match'), $eTag) !== false) {
                abort(304);
            }

            return response($response, 200, ['Content-Type' => 'application/x-msgpack'])->header('ETag', "\"{$eTag}\"");
        } else {
            $eTag = sha1(json_encode($response));
            if (strpos($request->header('If-None-Match'), $eTag) !== false) {
                abort(304);
            }

            return response($response)->header('ETag', "\"{$eTag}\"");
        }
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $this->observeNodeExitIp($request);
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // JSON decoding error
            return response([
                'error' => 'Invalid traffic data'
            ], 400);
        }
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_ONLINE_USER', $this->nodeInfo->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_PUSH_AT', $this->nodeInfo->id), time(), 3600);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $alive = Cache::remember('ALIVE_LIST', 60, function () {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers();

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER_' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $results = Cache::many($keys);
            $alive = [];
            foreach ($results as $key => $data) {
                if (is_array($data) && isset($data['alive_ip'])) {
                    $alive[$idMap[$key]] = $data['alive_ip'];
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object)$alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $this->observeNodeExitIp($request);
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if (empty($data)) {
            return response([
                'data' => true
            ]);
        }
        if (!is_array($data)) {
            return response([
                'error' => 'Invalid online data format'
            ], 400);
        }
        $updateAt = time();
        $cacheKeys = array_map(function ($uid) {
            return 'ALIVE_IP_USER_' . $uid;
        }, array_keys($data));

        if (empty($cacheKeys)) {
            return response([
                'data' => true
            ]);
        }

        $cachedData = Cache::many($cacheKeys);
        $updates = [];

        foreach ($data as $uid => $ips) {
            if (!is_numeric($uid) || !is_array($ips)) {
                continue; // 跳过无效数据
            }
            $key = 'ALIVE_IP_USER_' . $uid;
            $ips_array = $cachedData[$key] ?? [];

            // 更新节点数据
            $ips_array[$this->nodeType . $this->nodeId] = ['aliveips' => $ips, 'lastupdateAt' => $updateAt];
            // 清理过期数据
            foreach ($ips_array as $nodetypeid => $oldips) {
                if ($nodetypeid !== 'alive_ip' && is_array($oldips) && ($updateAt - ($oldips['lastupdateAt'] ?? 0) > 100)) {
                    unset($ips_array[$nodetypeid]);
                }
            }

            // 在线设备按真实 IP 去重，避免同一出口访问多个节点时重复计数。
            $count = $this->countUniqueAliveIps($ips_array);
            $ips_array['alive_ip'] = $count;
            (new SubscriptionRuleService())->guardNodeAliveIp(
                $request,
                (int)$uid,
                $ips_array,
                $count,
                $this->nodeType,
                $this->nodeId
            );

            $updates[$key] = $ips_array;
        }

        // 批量更新缓存
        foreach ($updates as $key => $value) {
            Cache::put($key, $value, 120);
        }

        return response([
            'data' => true
        ]);
    }

    private function countUniqueAliveIps(array $ipsArray)
    {
        $ipmap = [];
        foreach ($ipsArray as $nodetypeid => $newdata) {
            if ($nodetypeid === 'alive_ip' || !is_array($newdata) || empty($newdata['aliveips']) || !is_array($newdata['aliveips'])) {
                continue;
            }
            foreach ($newdata['aliveips'] as $ipNodeId) {
                $ip = explode('_', (string)$ipNodeId)[0];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ipmap[$ip] = true;
                }
            }
        }
        return count($ipmap);
    }


    // Compatible with v2node backends that request incremental users; return JSON full list.
    public function user_delta(Request $request)
    {
        ini_set('memory_limit', -1);
        $this->observeNodeExitIp($request);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id)
            ->map(function ($user) {
                return array_filter($user->toArray(), function ($v) {
                    return !is_null($v);
                });
            })->toArray();

        return response(['users' => $users]);
    }

    private function nodeHost()
    {
        $fallbackHost = '';
        foreach ([
            $this->nodeInfo->host ?? '',
            $this->nodeInfo->server_name ?? '',
            $this->nodeInfo->listen_ip ?? '',
        ] as $host) {
            $host = trim((string)$host);
            if ($host !== '' && $host !== '0.0.0.0' && $host !== '::') {
                $fallbackHost = $host;
                break;
            }
        }

        if ($this->isPlaceholderNodeHost($fallbackHost)) {
            $childHost = $this->childNodeHost();
            if ($childHost !== '') {
                return $childHost;
            }
        }

        return $fallbackHost;
    }

    private function childNodeHost()
    {
        try {
            $servers = $this->serverService->getAllServers();
        } catch (\Throwable $e) {
            return '';
        }

        foreach ($servers as $server) {
            if ((string)($server['type'] ?? '') !== (string)$this->nodeType) {
                continue;
            }
            if ((string)($server['parent_id'] ?? '') !== (string)$this->nodeId) {
                continue;
            }
            if (array_key_exists('show', $server) && (int)$server['show'] !== 1) {
                continue;
            }

            foreach ([
                $server['host'] ?? '',
                $server['server_name'] ?? '',
                $server['listen_ip'] ?? '',
            ] as $host) {
                $host = trim((string)$host);
                if ($host !== '' && !$this->isPlaceholderNodeHost($host)) {
                    return $host;
                }
            }
        }

        return '';
    }

    private function isPlaceholderNodeHost($host)
    {
        return in_array(trim((string)$host), ['', '0.0.0.0', '::', '1.1.1.1'], true);
    }

    private function observeNodeExitIp(Request $request)
    {
        (new NodeExitIpService())->observe(
            $request,
            $this->nodeType,
            $this->nodeId,
            $this->nodeInfo->name ?? '',
            $this->nodeHost()
        );
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $this->observeNodeExitIp($request);
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'encryption' => $this->nodeInfo->encryption,
                    'encryption_settings' => $this->nodeInfo->encryption_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
