<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class ClientSignature
{
    private const DEFAULT_SECRET = 'xiao-v2b-windows-client@2026-06-official-signature-v1';
    private const ALLOWED_CLOCK_SKEW = 300;

    public function handle($request, Closure $next)
    {
        $secret = (string)(config('v2board.client_api_secret') ?: env('XIAOV2B_CLIENT_API_SECRET', self::DEFAULT_SECRET));
        if ($secret === '') {
            abort(403, '客户端校验未配置');
        }

        $client = (string)$request->header('X-Xiao-Client');
        $version = (string)$request->header('X-Xiao-Version');
        $timestamp = (int)$request->header('X-Xiao-Timestamp');
        $nonce = (string)$request->header('X-Xiao-Nonce');
        $signature = (string)$request->header('X-Xiao-Sign');

        if ($client !== 'windows' || $version === '' || $timestamp <= 0 || $nonce === '' || $signature === '') {
            abort(403, '客户端校验失败');
        }

        if (abs(time() - $timestamp) > self::ALLOWED_CLOCK_SKEW) {
            abort(403, '客户端时间异常');
        }

        if (!preg_match('/^[a-f0-9]{24,64}$/i', $nonce)) {
            abort(403, '客户端校验失败');
        }

        $nonceKey = 'xiaov2b_client_nonce:' . sha1($client . '|' . $nonce);
        if (Cache::has($nonceKey)) {
            abort(403, '客户端请求已失效');
        }
        Cache::put($nonceKey, 1, self::ALLOWED_CLOCK_SKEW);

        $path = parse_url($request->getRequestUri(), PHP_URL_PATH) ?: $request->getPathInfo();
        $payload = implode("\n", [
            strtoupper($request->method()),
            $path,
            (string)$timestamp,
            $nonce,
            $client,
            $version
        ]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            abort(403, '客户端校验失败');
        }

        return $next($request);
    }
}
