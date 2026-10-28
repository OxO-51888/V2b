<?php

namespace App\Modules\Security;

final class SubscriptionClientUserAgent
{
    public const KNOWN = [
        'clash', 'clash meta', 'clashmeta', 'clash-verge', 'clashverge', 'clash verge',
        'clash for windows', 'clashforwindows', 'clashforandroid', 'cfw', 'mihomo', 'metacubex',
        'sing-box', 'singbox', 'sfa', 'sfi', 'v2ray', 'v2rayn', 'v2rayng', 'v2raytun', 'xray',
        'shadowrocket', 'quantumult', 'quantumultx', 'quantumult x', 'quanx', 'loon', 'karing',
        'loonlite', 'stash', 'surge', 'surfboard', 'flowz', 'nekobox', 'nekoray', 'hiddify',
        'hiddifynext', 'anxray', 'kitsunebi', 'flclash', 'clashnyanpasu', 'clash-nyanpasu',
        'v2box', 'qv2ray', 'openclash', 'passwall', 'passwall2', 'homeproxy', 'trojan',
        'trojan-go', 'naive', 'tuic', 'hysteria', 'hysteria2', 'hy2',
    ];

    public static function known(string $ua): bool
    {
        $ua = strtolower(trim($ua));
        if ($ua === '') return false;
        foreach (self::KNOWN as $needle) {
            if (str_contains($ua, $needle)) return true;
        }
        return str_contains($ua, 'go-http-client');
    }
}
