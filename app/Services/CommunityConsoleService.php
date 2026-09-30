<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommunityConsoleService
{
    public function authenticate(Request $request): User
    {
        abort_unless(config('community.enabled') && config('community_console.enabled'), 404);
        $site = (string)config('community_console.site');
        $secret = (string)config('community_console.secret');
        $time = (string)$request->header('X-Console-Time');
        $nonce = (string)$request->header('X-Console-Nonce');
        $signature = (string)$request->header('X-Console-Signature');
        abort_unless(strlen($secret) >= 32 && $site !== '' && $request->header('X-Console-Site') === $site
            && preg_match('/^[0-9]{10}$/D', $time) && abs(time() - (int)$time) <= 30
            && preg_match('/^[a-f0-9]{64}$/D', $nonce) && preg_match('/^[a-f0-9]{64}$/D', $signature), 401);
        $message = "POST\n/api/v1/guest/community/console\n{$site}\n{$time}\n{$nonce}\n" . hash('sha256', $request->getContent());
        abort_unless($request->method() === 'POST' && hash_equals(hash_hmac('sha256', $message, $secret), $signature), 401);
        abort_unless(Cache::add('community_console_nonce:' . $site . ':' . $nonce, 1, 65), 401);
        $admin = User::find((int)config('community_console.admin_id'));
        abort_unless($admin && (int)$admin->is_admin && !(int)$admin->banned, 403, '后台管理账号不可用');
        return $admin;
    }
}
