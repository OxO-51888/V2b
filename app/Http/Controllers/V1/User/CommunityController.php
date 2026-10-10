<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CommunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CommunityController extends Controller
{
    public function handle(Request $request, string $action, CommunityService $community)
    {
        $user = $community->authenticate($request);
        abort_if($action === 'mute' && !(int)$user->is_admin && !(int)$user->is_staff, 403);
        $key = 'community:' . $user->id . ':' . (in_array($action, ['post', 'upload'], true) ? 'write' : 'read');
        $limit = in_array($action, ['post', 'upload'], true) ? 20 : 100;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, '操作过于频繁，请稍后再试');
        RateLimiter::hit($key, 60);
        $data = $community->validatedData($request, $action);
        if ($action === 'post' && !(bool)$user->is_admin && !(bool)$user->is_staff) {
            // Derive a bounded label here; raw headers never leave the panel.
            $data['client_os'] = self::messageSystem($request);
        }
        $result = $community->call($user, $action, $data);
        if ($action === 'bootstrap') {
            $result['me']['can_view_accounts'] = false;
        } elseif ($action === 'history') {
            $result['can_view_accounts'] = false;
        }
        return response()->json(['data' => $result])
            ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private static function messageSystem(Request $request): string
    {
        $platform = strtolower(trim((string)$request->header('Sec-CH-UA-Platform', ''), " \t\n\r\0\x0B\""));
        $platforms = ['android' => 'Android', 'ios' => 'iOS', 'ipados' => 'iPadOS',
            'windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux',
            'chrome os' => 'ChromeOS', 'chromeos' => 'ChromeOS', 'harmonyos' => 'HarmonyOS'];
        $agent = (string)$request->userAgent();
        if (strlen($agent) > 2048) $agent = '';
        // Prefer explicit mobile tokens where a browser reports a desktop platform.
        foreach (['/\b(?:HarmonyOS|OpenHarmony)\b/i' => 'HarmonyOS', '/\biPad\b/i' => 'iPadOS',
            '/\b(?:iPhone|iPod)\b/i' => 'iOS'] as $pattern => $system) {
            if (preg_match($pattern, $agent)) return $system;
        }
        if (isset($platforms[$platform])) return $platforms[$platform];
        foreach (['/\bAndroid\b/i' => 'Android', '/\bCrOS\b/i' => 'ChromeOS',
            '/\bWindows (?:NT|Phone|CE)\b/i' => 'Windows', '/\b(?:Macintosh|Mac OS X)\b/i' => 'macOS',
            '/\bLinux\b/i' => 'Linux'] as $pattern => $system) {
            if (preg_match($pattern, $agent)) return $system;
        }
        return '';
    }
}
