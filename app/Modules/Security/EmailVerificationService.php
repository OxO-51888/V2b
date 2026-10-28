<?php

namespace App\Modules\Security;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use ReCaptcha\ReCaptcha;
use RuntimeException;
use Throwable;

class EmailVerificationService
{
    private const int CODE_TTL = 300;
    private const int RESEND_TTL = 60;

    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    public function send(string $email, ?bool $passwordReset, string $ip, ?string $captchaToken): void
    {
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            PassportRejection::RequestRateLimited->reject();
        }
        RateLimiter::hit($ip, 60);

        if ((int) config('v2board.recaptcha_enable', 0)) {
            $captcha = app()->make(ReCaptcha::class, ['secret' => (string) config('v2board.recaptcha_key')]);
            if (!$captcha->verify($captchaToken ?? '')->isSuccess()) {
                PassportRejection::InvalidCaptcha->reject();
            }
        }

        self::locked($email, function () use ($email, $passwordReset): void {
            $normalized = self::normalize($email);
            $user = User::where('email', $normalized)->first();
            if ($user && !self::matches($user->email, $email)) {
                PassportRejection::InvalidEmailFormat->reject();
            }
            $this->checkMailboxPolicy($email);
            if ($passwordReset === false && $user) {
                PassportRejection::EmailRegistered->reject();
            }
            if ($passwordReset === true && !$user) {
                PassportRejection::EmailNotRegistered->reject();
            }
            if ((int) Cache::get(CacheKey::get('FORGET_REQUEST_LIMIT', $normalized), 0) >= 3) {
                PassportRejection::ResetRateLimited->reject();
            }
            if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $normalized))) {
                PassportRejection::EmailRecentlySent->reject();
            }

            $this->queueCode($user?->email ?? $email, $normalized);
        });
    }

    private function checkMailboxPolicy(string $email): void
    {
        if ((int) config('v2board.email_whitelist_enable', 0)
            && !Helper::emailSuffixVerify($email, config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            PassportRejection::EmailNotAllowed->reject();
        }
        if ((int) config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email, 2)[0];
            if (str_contains($prefix, '.') || str_contains($prefix, '+')) {
                PassportRejection::EmailAliasNotAllowed->reject();
            }
        }
    }

    private function queueCode(string $recipient, string $normalized): void
    {
        $code = (string) random_int(100000, 999999);
        $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $normalized);
        $cooldownKey = CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $normalized);

        try {
            // The worker must never receive a code before its verification state exists.
            if (!Cache::put($codeKey, $code, self::CODE_TTL)
                || !Cache::put($cooldownKey, time(), self::RESEND_TTL)) {
                throw new RuntimeException('Unable to persist email verification state.');
            }
            $this->dispatcher->dispatch(new SendEmailJob([
                'email' => $recipient,
                'subject' => config('v2board.app_name', 'V2Board') . __('Email verification code'),
                'template_name' => 'verify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'code' => $code,
                    'url' => config('v2board.app_url'),
                ],
            ]));
        } catch (Throwable $exception) {
            // Do not remove a newer request's state if this operation lost its lock.
            if (Cache::get($codeKey) === $code) {
                Cache::forget($codeKey);
                Cache::forget($cooldownKey);
            }
            throw $exception;
        }
    }

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

    public static function locked(string $email, callable $callback): mixed
    {
        $key = 'auth:email:lock:' . hash('sha256', base_path() . ':' . self::normalize($email));
        try {
            return Cache::lock($key, 30)->block(3, $callback);
        } catch (LockTimeoutException $e) {
            PassportRejection::RequestRateLimited->reject();
        }
    }

    public static function verifyAndRun(string $email, mixed $code, callable $callback): mixed
    {
        return self::locked($email, function () use ($email, $code, $callback) {
            $normalized = self::normalize($email);
            $limitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $normalized);
            $attempts = (int) Cache::get($limitKey, 0);
            if ($attempts >= 3) PassportRejection::ResetRateLimited->reject();

            $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $normalized);
            $cached = Cache::get($codeKey);
            if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/D', $code)
                || !is_scalar($cached) || (string) $cached === ''
                || !hash_equals((string) $cached, $code)) {
                Cache::put($limitKey, $attempts + 1, 300);
                PassportRejection::InvalidEmailCode->reject();
            }

            // Consume before any account write; an expired lock must not permit replay.
            if (!Cache::forget($codeKey)) abort(503, __('Too many requests, please try again later.'));
            Cache::forget($limitKey);
            return $callback();
        });
    }
}
