<?php

namespace App\Modules\ClientGateway;

use Illuminate\Http\Request;

class SubscriptionEntry
{
    public static function upstreams(): array
    {
        $configured = trim((string) config('v2board.subscribe_url', ''));
        if ($configured === '') {
            return self::panelUpstreams();
        }
        $origins = [];
        $publicIpOnly = true;
        $hasEntry = false;
        foreach (explode(',', $configured) as $value) {
            if (trim($value) === '') continue;
            $origin = self::configuredOrigin($value);
            if ($origin === null) {
                $publicIpOnly = false;
                continue;
            }
            $hasEntry = true;
            if (filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)) continue;
            $publicIpOnly = false;
            $origins[] = $origin;
        }
        // Public IP subscription entrances are proxies, not per-site gateway origins.
        if ($hasEntry && $publicIpOnly) return self::panelUpstreams();
        return array_values(array_unique($origins));
    }

    private static function panelUpstreams(): array
    {
        $origin = self::normalizeOrigin((string) (config('v2board.app_url') ?: config('app.url')));
        if ($origin === null || filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)) return [];
        return [$origin];
    }

    private static function configuredOrigin(string $value): ?string
    {
        $origin = self::normalizeOrigin($value);
        if ($origin !== null) return $origin;
        $value = trim($value);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return null;
        $url = parse_url($value);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['path'] ?? '') !== '/proxy'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || !filter_var($url['host'] ?? '', FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || ($url['port'] ?? 443) < 1 || ($url['port'] ?? 443) > 65535
            || !preg_match('/\Aurl=[^&;]+\z/D', $url['query'] ?? '')) return null;
        parse_str($url['query'], $parameters);
        if (array_keys($parameters) !== ['url'] || !is_string($parameters['url'])) return null;
        $origin = self::normalizeOrigin($parameters['url']);
        // Only a configured HTTPS domain can be unwrapped, never an IP or another proxy URL.
        return $origin !== null && !filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)
            ? $origin : null;
    }

    private static function normalizeOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return null;
        $url = parse_url($value);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)) return null;
        $host = strtolower($url['host'] ?? '');
        $publicIp = filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$publicIp && (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host)
            || strlen($host) > 253)) return null;
        $port = $url['port'] ?? 443;
        if ($port < 1 || $port > 65535) return null;
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
    }

    public static function accepts(Request $request): bool
    {
        // Only configured hosts select an entrance; request headers never choose an upstream.
        $host = strtolower($request->getHost());
        foreach (self::upstreams() as $origin) {
            if (parse_url($origin, PHP_URL_HOST) === $host) return true;
        }
        return false;
    }
}
