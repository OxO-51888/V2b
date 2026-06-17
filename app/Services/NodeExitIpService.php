<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NodeExitIpService
{
    private const CACHE_KEY = 'SUB_RULE_NODE_EXIT_IPS';
    private const REFRESH_SECONDS = 120;
    private const CACHE_SECONDS = 240;
    private const CLOUDFLARE_IPV4_CIDRS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22'
    ];

    public function observe(Request $request, $nodeType, $nodeId, $nodeName = '', $nodeHost = '')
    {
        $ip = $this->sourceIp($request, $nodeHost);
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $now = time();
        $records = $this->records(false);
        $key = 'ip:' . $ip;
        $record = isset($records[$key]) && is_array($records[$key]) ? $records[$key] : [];
        $nodes = isset($record['nodes']) && is_array($record['nodes']) ? $record['nodes'] : [];
        $nodeKey = implode(':', [(string)$nodeType, (string)$nodeId]);
        $nodes[$nodeKey] = [
            'node_type' => (string)$nodeType,
            'node_id' => (string)$nodeId,
            'node_name' => (string)$nodeName,
            'last_seen_at' => $now
        ];

        $records[$key] = [
            'ip' => $ip,
            'node_type' => (string)$nodeType,
            'node_id' => (string)$nodeId,
            'node_name' => $this->nodeSummary($nodes),
            'nodes' => $nodes,
            'proxy_ip' => (string)$request->ip(),
            'first_seen_at' => isset($record['first_seen_at']) ? (int)$record['first_seen_at'] : $now,
            'last_seen_at' => $now
        ];

        Cache::put(self::CACHE_KEY, $this->prune($records, $now), self::CACHE_SECONDS);

        return $records[$key];
    }

    public function isNodeExitIp($ip)
    {
        if (!$ip) {
            return false;
        }

        foreach ($this->records() as $record) {
            if (($record['ip'] ?? '') === $ip) {
                return true;
            }
        }

        return false;
    }

    public function snapshot()
    {
        $records = array_values($this->records());
        usort($records, function ($a, $b) {
            return (int)($b['last_seen_at'] ?? 0) <=> (int)($a['last_seen_at'] ?? 0);
        });

        $ips = [];
        $nodes = [];
        $lastSeenAt = 0;
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[$record['ip']] = true;
            }
            if (!empty($record['nodes']) && is_array($record['nodes'])) {
                foreach ($record['nodes'] as $nodeKey => $node) {
                    $nodes[$nodeKey] = true;
                }
            } elseif (!empty($record['node_type']) || !empty($record['node_id'])) {
                $nodes[implode(':', [(string)($record['node_type'] ?? ''), (string)($record['node_id'] ?? '')])] = true;
            }
            $lastSeenAt = max($lastSeenAt, (int)($record['last_seen_at'] ?? 0));
        }

        return [
            'refresh_seconds' => self::REFRESH_SECONDS,
            'ip_count' => count($ips),
            'node_count' => count($nodes),
            'last_seen_at' => $lastSeenAt,
            'records' => $records
        ];
    }

    private function records($writeBack = true)
    {
        $records = Cache::get(self::CACHE_KEY, []);
        if (!is_array($records)) {
            $records = [];
        }

        $records = $this->prune($this->mergeByIp($records), time());
        if ($writeBack) {
            Cache::put(self::CACHE_KEY, $records, self::CACHE_SECONDS);
        }

        return $records;
    }

    private function prune(array $records, $now)
    {
        foreach ($records as $key => $record) {
            if (!is_array($record) || empty($record['ip']) || (int)($record['last_seen_at'] ?? 0) < $now - self::REFRESH_SECONDS) {
                unset($records[$key]);
                continue;
            }

            if (!empty($record['nodes']) && is_array($record['nodes'])) {
                foreach ($record['nodes'] as $nodeKey => $node) {
                    if (!is_array($node) || (int)($node['last_seen_at'] ?? 0) < $now - self::REFRESH_SECONDS) {
                        unset($record['nodes'][$nodeKey]);
                    }
                }
                $record['node_name'] = $this->nodeSummary($record['nodes']);
                $records[$key] = $record;
            }
        }

        return $records;
    }

    private function mergeByIp(array $records)
    {
        $merged = [];
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['ip'])) {
                continue;
            }

            $ip = (string)$record['ip'];
            $key = 'ip:' . $ip;
            $current = isset($merged[$key]) && is_array($merged[$key]) ? $merged[$key] : [];
            $nodes = isset($current['nodes']) && is_array($current['nodes']) ? $current['nodes'] : [];

            if (!empty($record['nodes']) && is_array($record['nodes'])) {
                foreach ($record['nodes'] as $nodeKey => $node) {
                    if (is_array($node)) {
                        $nodes[$nodeKey] = $node;
                    }
                }
            } elseif (!empty($record['node_type']) || !empty($record['node_id'])) {
                $nodeKey = implode(':', [(string)($record['node_type'] ?? ''), (string)($record['node_id'] ?? '')]);
                $nodes[$nodeKey] = [
                    'node_type' => (string)($record['node_type'] ?? ''),
                    'node_id' => (string)($record['node_id'] ?? ''),
                    'node_name' => (string)($record['node_name'] ?? ''),
                    'last_seen_at' => (int)($record['last_seen_at'] ?? 0)
                ];
            }

            $lastSeenAt = max((int)($current['last_seen_at'] ?? 0), (int)($record['last_seen_at'] ?? 0));
            $useLatest = (int)($record['last_seen_at'] ?? 0) >= (int)($current['last_seen_at'] ?? 0);
            $merged[$key] = [
                'ip' => $ip,
                'node_type' => $useLatest ? (string)($record['node_type'] ?? '') : (string)($current['node_type'] ?? ''),
                'node_id' => $useLatest ? (string)($record['node_id'] ?? '') : (string)($current['node_id'] ?? ''),
                'node_name' => $this->nodeSummary($nodes),
                'nodes' => $nodes,
                'proxy_ip' => $useLatest ? (string)($record['proxy_ip'] ?? '') : (string)($current['proxy_ip'] ?? ''),
                'first_seen_at' => min((int)($current['first_seen_at'] ?? $record['first_seen_at'] ?? time()), (int)($record['first_seen_at'] ?? time())),
                'last_seen_at' => $lastSeenAt
            ];
        }

        return $merged;
    }

    private function nodeSummary(array $nodes)
    {
        $names = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string)($node['node_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($node['node_id'] ?? ''));
            }
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        if (!$names) {
            return '-';
        }

        $summary = implode(' / ', array_slice($names, 0, 2));
        if (count($names) > 2) {
            $summary .= ' 等' . count($names) . '个';
        }

        return $summary;
    }

    private function sourceIp(Request $request, $nodeHost = '')
    {
        $proxyIp = (string)$request->ip();
        if ($this->isTrustedProxy($proxyIp)) {
            foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
                $ip = $this->firstHeaderIp((string)$request->header($header, ''));
                if ($ip) {
                    return $ip;
                }
            }

            $nodeIp = $this->nodeHostIp($nodeHost);
            if ($nodeIp) {
                return $nodeIp;
            }

            return null;
        }

        return $proxyIp;
    }

    private function nodeHostIp($nodeHost)
    {
        $nodeHost = trim((string)$nodeHost);
        if ($nodeHost === '') {
            return null;
        }

        if (filter_var($nodeHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $nodeHost;
        }

        $ips = @gethostbynamel($nodeHost);
        if (!is_array($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }

    private function firstHeaderIp($value)
    {
        foreach (explode(',', $value) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }

    private function isTrustedProxy($ip)
    {
        if (!$ip) {
            return false;
        }

        $trustedIps = array_merge(['127.0.0.1'], (array)config('v2board.trusted_proxy_ips', []));
        if (in_array($ip, $trustedIps, true)) {
            return true;
        }

        $trustedCidrs = array_merge((array)config('v2board.trusted_proxy_cidrs', []), self::CLOUDFLARE_IPV4_CIDRS);
        foreach ($trustedCidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr($ip, $cidr)
    {
        if (!is_string($cidr) || strpos($cidr, '/') === false) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int)$mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
    }
}
