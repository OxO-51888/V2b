<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class EmailVerificationService
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function matches(string $stored, string $submitted): bool
    {
        // Database collations may equate distinct internationalized mailboxes.
        return self::normalize($stored) === self::normalize($submitted);
    }

    public static function findUser(string $email): ?User
    {
        $user = User::where('email', self::normalize($email))->first();
        return $user && self::matches($user->email, $email) ? $user : null;
    }

    public static function locked(string $email, callable $callback)
    {
        $key = 'auth:email:lock:' . hash('sha256', base_path() . ':' . self::normalize($email));
        try {
            return Cache::lock($key, 30)->block(3, $callback);
        } catch (LockTimeoutException $e) {
            abort(429, __('Too many requests, please try again later.'));
        }
    }

    public static function verifyAndRun(string $email, $code, callable $callback)
    {
        return self::locked($email, function () use ($email, $code, $callback) {
            $normalized = self::normalize($email);
            $limitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $normalized);
            $attempts = (int) Cache::get($limitKey, 0);
            if ($attempts >= 3) abort(500, __('Reset failed, Please try again later'));

            $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $normalized);
            $cached = Cache::get($codeKey);
            if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/D', $code)
                || !is_scalar($cached) || (string) $cached === ''
                || !hash_equals((string) $cached, $code)) {
                Cache::put($limitKey, $attempts + 1, 300);
                abort(500, __('Incorrect email verification code'));
            }

            // Consume before any account write; an expired lock must not permit replay.
            if (!Cache::forget($codeKey)) abort(503, __('Too many requests, please try again later.'));
            Cache::forget($limitKey);
            return $callback();
        });
    }
}
