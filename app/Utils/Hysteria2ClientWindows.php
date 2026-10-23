<?php

namespace App\Utils;

class Hysteria2ClientWindows
{
    private static function settings(array $proxy): ?array
    {
        if (($proxy['type'] ?? '') !== 'hysteria2') return null;

        $settings = config('v2board.hysteria2_client_windows', []);
        $host = strtolower(rtrim((string)($proxy['server'] ?? ''), '.'));
        $windows = is_array($settings) ? ($settings[$host] ?? null) : null;
        if (!is_array($windows)) return null;
        $stream = $windows['stream'] ?? null;
        $connection = $windows['connection'] ?? null;
        if (!is_int($stream) || !is_int($connection)
            || $stream < 65536 || $stream > 67108864
            || $connection < $stream || $connection > 268435456) {
            return null;
        }
        return ['stream' => $stream, 'connection' => $connection];
    }

    public static function mihomo(array $proxy): array
    {
        $windows = self::settings($proxy);
        if ($windows === null) return $proxy;

        $proxy['initial-stream-receive-window'] = $windows['stream'];
        $proxy['max-stream-receive-window'] = $windows['stream'];
        $proxy['initial-connection-receive-window'] = $windows['connection'];
        $proxy['max-connection-receive-window'] = $windows['connection'];
        return $proxy;
    }

    public static function singbox(array $proxy, ?string $version): array
    {
        // Older cores reject unknown JSON fields, so require an explicit supported version.
        if ($version === null || !preg_match('/^\d+\.\d+\.\d+$/D', $version)
            || !version_compare($version, '1.14.0', '>=')) return $proxy;
        $windows = self::settings($proxy);
        if ($windows === null) return $proxy;

        $proxy['stream_receive_window'] = $windows['stream'];
        $proxy['connection_receive_window'] = $windows['connection'];
        return $proxy;
    }
}
