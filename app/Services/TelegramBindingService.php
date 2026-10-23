<?php

namespace App\Services;

use App\Jobs\SendTelegramBindingEmail;
use App\Models\User;
use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramBindingService
{
    public const TTL = 600;

    public static function key(string $suffix): string
    {
        return 'tg_binding:v1:' . substr(hash('sha256', base_path()), 0, 16) . ':' . $suffix;
    }

    private function locked(string $name, callable $callback)
    {
        $lock = Cache::lock(self::key('lock:' . $name), 30);
        abort_unless($lock->get(), 429, '操作正在处理，请稍后重试');
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('telegram_binding.secret'));
    }

    public function codeHash(string $id, string $code): string
    {
        return $this->digest('code:' . $id . ':' . $code);
    }

    private function mask(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);
        return substr($name, 0, 2) . '***@' . $domain;
    }

    private function eligible($user): bool
    {
        return $user && (int) $user->banned !== 1;
    }

    private function result($user): array
    {
        $allowed = $this->eligible($user);
        return ['allowed' => $allowed, 'emailMasked' => $allowed ? $this->mask($user->email) : '',
            'reason' => $allowed ? '网站注册用户' : '未找到可用的已绑定账号'];
    }

    public function verify(int $telegramId, bool $requireActivePlan = false): array
    {
        $user = User::where('telegram_id', $telegramId)->first();
        $result = $this->result($user);
        if ($result['allowed'] && $requireActivePlan && (!$user->plan_id || $user->transfer_enable <= 0
            || ($user->expired_at && $user->expired_at <= time()))) {
            return ['allowed' => false, 'emailMasked' => '', 'reason' => '账号没有可用套餐'];
        }
        return $result;
    }

    private function readBoundAccount(int $telegramId, string $action, array $fields): User
    {
        abort_unless(Cache::add(self::key('read:' . $action . ':' . $telegramId), 1, 3),
            429, '查询过于频繁，请等待3秒后重试');
        $users = User::where('telegram_id', $telegramId)->limit(2)->get(array_merge(['id', 'banned'], $fields));
        abort_if($users->count() > 1, 409, '绑定关系异常，请联系管理员');
        $user = $users->first();
        abort_unless($this->eligible($user), 422, '请先绑定可用的网站账号，再查询订阅或套餐');
        return $user;
    }

    public function readSubscription(int $telegramId): array
    {
        $user = $this->readBoundAccount($telegramId, 'subscription', ['token']);
        abort_unless(is_string($user->token) && $user->token !== '', 503, '订阅信息暂不可用，请登录网站查看');
        return ['subscribe_url' => Helper::getSubscribeUrl($user->token)];
    }

    public function readPlan(int $telegramId): array
    {
        $user = $this->readBoundAccount($telegramId, 'plan', ['plan_id', 'expired_at', 'u', 'd', 'transfer_enable']);
        $plan = $user->plan_id ? Plan::where('id', $user->plan_id)->first(['name']) : null;
        $total = max(0, (int) $user->transfer_enable);
        $used = max(0, (int) $user->u) + max(0, (int) $user->d);
        $expires = $user->expired_at === null ? null : (int) $user->expired_at;
        $status = 'active';
        if (!$user->plan_id) $status = 'no_plan';
        elseif (!$plan) $status = 'plan_missing';
        elseif ($expires !== null && $expires <= time()) $status = 'expired';
        elseif ($used >= $total) $status = 'exhausted';
        return ['plan_name' => $plan ? mb_substr((string) $plan->name, 0, 160) : '',
            'status' => $status, 'expires_at' => $expires,
            'total_bytes' => $total, 'used_bytes' => $used, 'remaining_bytes' => max(0, $total - $used)];
    }

    public function requestCode(int $telegramId, string $email, string $requestId): array
    {
        abort_unless(config('telegram_binding.mail_enabled'), 503, '邮箱绑定测试中，验证码发送暂未开放');
        $email = strtolower(trim($email));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254, 422, '邮箱格式不正确');
        return $this->locked('request', function () use ($telegramId, $email, $requestId) {
            return $this->locked('tg:' . $telegramId, function () use ($telegramId, $email, $requestId) {
                $key = self::key('challenge:' . $telegramId);
                $previous = Cache::get($key);
                if ($previous && $previous['request_id'] === $requestId) {
                    abort_unless($previous['email'] === $email, 409, '请求编号冲突');
                    return ['status' => 'queued', 'emailMasked' => $this->mask($email), 'expires_at' => $previous['expires_at']];
                }
                $emailKey = $this->digest('email:' . $email);
                foreach (['tg:' . $telegramId, 'email:' . $emailKey] as $identity) {
                    abort_if(Cache::has(self::key('cooldown:' . $identity)), 429, '请等待60秒后再获取验证码');
                    abort_if((int) Cache::get(self::key('hour:' . $identity), 0) >= 5, 429, '获取次数过多，请一小时后重试');
                }
                abort_if((int) Cache::get(self::key('hour:all'), 0) >= 60, 429, '发送繁忙，请稍后重试');
                foreach (['tg:' . $telegramId, 'email:' . $emailKey, 'all'] as $identity) {
                    $rateKey = self::key('hour:' . $identity);
                    Cache::add($rateKey, 0, 3600);
                    Cache::increment($rateKey);
                    if ($identity !== 'all') Cache::put(self::key('cooldown:' . $identity), 1, 60);
                }
                $user = User::where('email', $email)->first();
                abort_unless($this->eligible($user), 422, '邮箱未注册或账号不可用');
                $code = (string) random_int(100000, 999999);
                $id = bin2hex(random_bytes(16));
                $challenge = ['id' => $id, 'user_id' => (int) $user->id, 'telegram_id' => $telegramId,
                    'bound_telegram_id' => $user->telegram_id ? (string) $user->telegram_id : null,
                    'email' => $email, 'code_hash' => $this->codeHash($id, $code), 'attempts' => 0,
                    'expires_at' => time() + self::TTL, 'request_id' => $requestId];
                Cache::put($key, $challenge, self::TTL);
                try {
                    app(Dispatcher::class)->dispatch(new SendTelegramBindingEmail($telegramId, $id, $code));
                } catch (\Throwable $error) {
                    Cache::forget($key);
                    abort(503, '验证码发送未成功提交，请稍后重试');
                }
                return ['status' => 'queued', 'emailMasked' => $this->mask($email), 'expires_at' => $challenge['expires_at']];
            });
        });
    }

    public function confirmCode(int $telegramId, string $code, string $requestId): array
    {
        abort_unless(preg_match('/^[0-9]{6}$/D', $code), 422, '请输入6位验证码');
        return $this->locked('tg:' . $telegramId, function () use ($telegramId, $code, $requestId) {
            $receiptKey = self::key('receipt:' . $telegramId . ':' . $requestId);
            $receipt = Cache::get($receiptKey);
            if ($receipt) {
                abort_unless(hash_equals($receipt['code_hash'], $this->codeHash($receipt['id'], $code)), 409, '请求编号冲突');
                $user = User::where('telegram_id', $telegramId)->first();
                abort_unless($this->eligible($user) && (int) $user->id === ($receipt['user_id'] ?? null)
                    && hash_equals($receipt['email_hash'] ?? '', $this->digest('email:' . strtolower($user->email))),
                    409, '绑定状态已变化，请重新获取验证码');
                return $receipt['result'];
            }
            $key = self::key('challenge:' . $telegramId);
            $challenge = Cache::get($key);
            abort_unless($challenge && $challenge['expires_at'] > time(), 422, '验证码已失效，请重新获取');
            if (!hash_equals($challenge['code_hash'], $this->codeHash($challenge['id'], $code))) {
                $challenge['attempts']++;
                if ($challenge['attempts'] >= 5) Cache::forget($key);
                else Cache::put($key, $challenge, max(1, $challenge['expires_at'] - time()));
                abort(422, $challenge['attempts'] >= 5 ? '错误次数过多，请重新获取验证码' : '验证码不正确');
            }
            $result = DB::transaction(function () use ($challenge, $telegramId) {
                $user = User::where('id', $challenge['user_id'])->lockForUpdate()->first();
                abort_unless($this->eligible($user) && strtolower($user->email) === $challenge['email'], 422, '账号状态已变化，请重新获取验证码');
                $boundTelegramId = $user->telegram_id ? (string) $user->telegram_id : null;
                // A code issued before another successful binding must not reclaim that binding.
                if (array_key_exists('bound_telegram_id', $challenge)) {
                    abort_unless($boundTelegramId === $challenge['bound_telegram_id'], 409, '绑定关系已变化，请重新获取验证码');
                } else {
                    abort_if($boundTelegramId !== null && $boundTelegramId !== (string) $telegramId,
                        409, '请重新获取验证码后更换Telegram账号');
                }
                abort_if(User::where('telegram_id', $telegramId)->where('id', '<>', $user->id)->exists(), 409, '当前Telegram已绑定其他邮箱，请联系管理员');
                $user->telegram_id = $telegramId;
                $user->save();
                return $this->result($user) + ['status' => 'bound'];
            });
            Cache::put($receiptKey, ['id' => $challenge['id'], 'code_hash' => $challenge['code_hash'],
                'user_id' => $challenge['user_id'], 'email_hash' => $this->digest('email:' . $challenge['email']), 'result' => $result], 120);
            Cache::forget($key);
            Cache::forget(self::key('unbind:' . $telegramId));
            return $result;
        });
    }

    public function prepareUnbind(int $telegramId): array
    {
        return $this->locked('tg:' . $telegramId, function () use ($telegramId) {
            $user = User::where('telegram_id', $telegramId)->first();
            if (!$user) {
                Cache::forget(self::key('unbind:' . $telegramId));
                return ['status' => 'not_bound'];
            }
            $token = bin2hex(random_bytes(16));
            Cache::put(self::key('unbind:' . $telegramId), [
                'token_hash' => $this->digest('unbind:' . $token), 'user_id' => (int) $user->id,
                'email_hash' => $this->digest('email:' . strtolower($user->email)), 'expires_at' => time() + 300,
            ], 300);
            return ['status' => 'confirmation_required', 'emailMasked' => $this->mask($user->email),
                'confirmation_token' => $token, 'expires_in' => 300];
        });
    }

    public function confirmUnbind(int $telegramId, string $token, string $requestId): array
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/D', $token), 422, '请重新点击解绑邮箱并确认');
        return $this->locked('tg:' . $telegramId, function () use ($telegramId, $token, $requestId) {
            $tokenHash = $this->digest('unbind:' . $token);
            $receiptKey = self::key('unbind_receipt:' . $telegramId . ':' . $requestId);
            $receipt = Cache::get($receiptKey);
            if ($receipt) {
                abort_unless(hash_equals($receipt['token_hash'], $tokenHash), 409, '请求编号冲突');
                abort_if(User::where('telegram_id', $telegramId)->exists(), 409, '绑定关系已变化，请重新点击解绑邮箱并确认');
                return $receipt['result'];
            }
            $key = self::key('unbind:' . $telegramId);
            $pending = Cache::get($key);
            abort_unless($pending && $pending['expires_at'] > time() && hash_equals($pending['token_hash'], $tokenHash),
                422, '解绑确认已过期或失效，请重新点击解绑邮箱');
            DB::transaction(function () use ($pending, $telegramId) {
                $user = User::where('id', $pending['user_id'])->lockForUpdate()->first();
                abort_unless($user && (string) $user->telegram_id === (string) $telegramId
                    && hash_equals($pending['email_hash'], $this->digest('email:' . strtolower($user->email))),
                    409, '绑定关系已变化，请重新点击解绑邮箱并确认');
                $user->telegram_id = null;
                $user->save();
            });
            Cache::forget(self::key('challenge:' . $telegramId));
            Cache::forget($key);
            $result = ['status' => 'unbound', 'allowed' => false];
            Cache::put($receiptKey, ['token_hash' => $tokenHash, 'result' => $result], 120);
            return $result;
        });
    }
}
