<?php

namespace App\Modules\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class SubscriptionClientIp
{
    public static function peer(Request $request): string
    {
        return self::normalize((string) $request->server('REMOTE_ADDR', '')) ?? '';
    }

    public static function trusted(string $ip, array $extraCidrs = []): bool
    {
        if (!self::normalize($ip)) return false;
        $ranges = array_merge(['127.0.0.1', '::1'], (array) config('v2board.trusted_proxy_ips', []),
            (array) config('v2board.trusted_proxy_cidrs', []), $extraCidrs);
        foreach ($ranges as $range) {
            if (is_string($range) && (self::normalize($range) || self::validCidr($range)) && IpUtils::checkIp($ip, $range)) return true;
        }
        return false;
    }

    public static function resolve(Request $request, array $extraCidrs = []): string
    {
        $peer = self::peer($request);
        if (!self::trusted($peer, $extraCidrs)) return $peer;

        // Only the connected proxy may supply a chain; walk it from the nearest hop.
        if ($request->headers->has('X-Forwarded-For')) {
            $header = (string) $request->header('X-Forwarded-For');
            if (strlen($header) > 4096) return $peer;
            $chain = explode(',', $header);
            if (count($chain) > 32) return $peer;
            foreach (array_reverse($chain) as $hop) {
                $ip = self::normalize(trim($hop));
                if ($ip === null) return $peer;
                if (!self::trusted($ip, $extraCidrs)) return $ip;
            }
            return $ip ?? $peer;
        }

        foreach (['CF-Connecting-IP', 'X-Real-IP'] as $header) {
            if ($request->headers->has($header)) return self::normalize((string) $request->header($header)) ?? $peer;
        }
        return $peer;
    }

    public static function validCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || self::normalize($parts[0]) === null) return false;
        return (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32);
    }

    private static function normalize(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($ip)) : null;
    }
}
