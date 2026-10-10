<?php

namespace App\Http\Middleware;

use App\Services\TelegramBindingService;
use Closure;
use Illuminate\Support\Facades\Cache;

class TelegramBotAuth
{
    public function handle($request, Closure $next)
    {
        $secret = (string) config('telegram_binding.secret', '');
        abort_unless(config('telegram_binding.enabled') && strlen($secret) >= 32, 503, '邮箱绑定暂未开放');
        abort_if(strlen($request->getContent()) > 2048, 413, '请求过大');
        $timestamp = (string) $request->header('X-Tg-Timestamp', '');
        $nonce = (string) $request->header('X-Tg-Nonce', '');
        $signature = (string) $request->header('X-Tg-Signature', '');
        abort_unless(preg_match('/^[0-9]{10}$/D', $timestamp) && abs(time() - (int) $timestamp) <= 60
            && preg_match('/^[a-f0-9]{32}$/D', $nonce) && preg_match('/^[a-f0-9]{64}$/D', $signature), 401, '请求认证失败');
        $canonical = $timestamp . "\n" . $nonce . "\n" . $request->method() . "\n"
            . $request->getPathInfo() . "\n" . hash('sha256', $request->getContent());
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $secret), $signature), 401, '请求认证失败');
        abort_unless(Cache::add(TelegramBindingService::key('nonce:' . $nonce), 1, 120), 409, '请勿重复提交请求');
        return $next($request);
    }
}
