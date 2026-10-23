<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;
    private $credentials;
    private $email;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->credentials = [$user->password, $user->password_algo, $user->password_salt];
        $this->email = $user->email;
    }

    public function generateAuthData(Request $request)
    {
        return self::withSessionLock($this->user->id, function ($guard) use ($request) {
            return $this->user->getConnection()->transaction(function () use ($request, $guard) {
                // Serialize credential changes with issuance, not ordinary JWT reads.
                $user = $this->user->newQuery()->lockForUpdate()->find($this->user->id);
                if (!$user || (int)$user->banned || $user->email !== $this->email
                    || $this->credentials !== [$user->password, $user->password_algo, $user->password_salt]) {
                    abort(403, 'Authentication expired. Please log in again.');
                }
                return self::generateAuthDataLocked($user, $request, $guard);
            });
        });
    }

    private static function generateAuthDataLocked(User $user, Request $request, callable $guard)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        $cacheKey = CacheKey::get('USER_SESSIONS', $user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ];
        $guard();
        if (!Cache::put($cacheKey, $sessions)) abort(503, 'Unable to store authentication session.');
        return [
            'token' => $user->token,
            'is_admin' => $user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        if (!is_string($jwt) || strlen($jwt) > 4096 || !preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $jwt)) return false;
        try {
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            $id = $data['id'] ?? null;
            $session = $data['session'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id <= 0
                || !is_string($session) || $session === '' || strlen($session) > 128) return false;
            if (!self::checkSession($id, $session)) return false;
            // Cached identities must not outlive session revocation or role changes.
            $user = User::select(['id', 'email', 'is_admin', 'is_staff', 'banned'])->find($id);
            if (!$user || (int)$user->banned) return false;
            return $user->only(['id', 'email', 'is_admin', 'is_staff']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = Cache::get(CacheKey::get("USER_SESSIONS", $userId), []);
        return is_array($sessions) && array_key_exists($session, $sessions);
    }

    private static function withLock($key, callable $callback)
    {
        // Start the deadline before acquisition so a slow DB cannot outlive the lease.
        $deadline = microtime(true) + 30;
        try {
            return Cache::lock($key, 30)->block(3, function () use ($callback, $deadline) {
                return $callback(function () use ($deadline) {
                    if (microtime(true) >= $deadline) abort(503, 'Authentication lock expired. Please retry.');
                });
            });
        } catch (LockTimeoutException $e) {
            abort(503, 'Authentication is busy. Please retry.');
        }
    }

    private static function withSessionLock($userId, callable $callback)
    {
        return self::withLock(CacheKey::get('USER_SESSIONS', $userId) . ':lock', $callback);
    }

    public function getSessions()
    {
        $sessions = (array)Cache::get(CacheKey::get('USER_SESSIONS', $this->user->id), []);
        foreach ($sessions as $id => $meta) {
            $sessions[$id] = array_intersect_key((array)$meta, array_flip(['ip', 'login_at', 'ua']));
        }
        return $sessions;
    }

    public function removeSession($sessionId)
    {
        if (!is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 128) return false;
        return self::withSessionLock($this->user->id, function ($guard) use ($sessionId) {
            $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
            $sessions = (array)Cache::get($cacheKey, []);
            if (!array_key_exists($sessionId, $sessions)) return true;
            $authData = $sessions[$sessionId]['auth_data'] ?? null;
            unset($sessions[$sessionId]);
            $guard();
            if (!Cache::put($cacheKey, $sessions)) abort(503, 'Unable to revoke authentication session.');
            if (is_string($authData)) Cache::forget($authData);
            return true;
        });
    }

    public function removeAllSession()
    {
        return self::withSessionLock($this->user->id, function ($guard) {
            return self::removeAllSessionsLocked($this->user->id, $guard);
        });
    }

    private static function removeAllSessionsLocked($userId, callable $guard)
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $guard();
        // Writing an empty map is idempotent and reports storage failures unambiguously.
        if (!Cache::put($cacheKey, [])) abort(503, 'Unable to revoke authentication sessions.');
        foreach ($sessions as $guid => $meta) {
            if (is_string($meta['auth_data'] ?? null)) {
                Cache::forget($meta['auth_data']);
            }
        }
        return true;
    }

    public function updateUser(array $params, bool $ordinaryOnly = false)
    {
        return self::withSessionLock($this->user->id, function ($guard) use ($params, $ordinaryOnly) {
            return $this->user->getConnection()->transaction(function () use ($guard, $params, $ordinaryOnly) {
                $user = $this->user->newQuery()->lockForUpdate()->find($this->user->id);
                if (!$user) abort(403, 'User does not exist.');
                if ($ordinaryOnly && ((int)$user->is_admin || (int)$user->is_staff)) {
                    abort(403, 'Cannot update a privileged user.');
                }
                $user->fill($params);
                if ($user->isDirty(['email', 'password', 'banned', 'is_admin', 'is_staff'])) {
                    self::removeAllSessionsLocked($user->id, $guard);
                }
                $guard();
                if (!$user->save()) abort(500, 'Unable to update user.');
                return true;
            });
        });
    }

    public function resetPassword($password, bool $verifyCredentials = false)
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) abort(500, 'Unable to hash password.');
        return self::withSessionLock($this->user->id, function ($guard) use ($hash, $verifyCredentials) {
            return $this->user->getConnection()->transaction(function () use ($guard, $hash, $verifyCredentials) {
                $user = $this->user->newQuery()->lockForUpdate()->find($this->user->id);
                if (!$user) abort(403, 'User does not exist.');
                if ($user->email !== $this->email || ($verifyCredentials
                    && $this->credentials !== [$user->password, $user->password_algo, $user->password_salt])) {
                    abort(403, 'Authentication expired. Please log in again.');
                }
                self::removeAllSessionsLocked($user->id, $guard);
                $user->password = $hash;
                $user->password_algo = null;
                $user->password_salt = null;
                $guard();
                if (!$user->save()) abort(500, 'Unable to reset password.');
                return true;
            });
        });
    }

    public static function issueQuickLoginToken($authorization)
    {
        $identity = self::decryptAuthData($authorization);
        if (!$identity) abort(403, 'Authentication expired. Please log in again.');
        return self::withSessionLock($identity['id'], function ($guard) use ($authorization, $identity) {
            if (!self::decryptAuthData($authorization)) abort(403, 'Authentication expired. Please log in again.');
            $code = Helper::guid();
            $guard();
            if (!Cache::put(CacheKey::get('TEMP_TOKEN', $code), [
                'uid' => $identity['id'],
                'authorization' => $authorization,
            ], 60)) abort(503, 'Unable to store quick login token.');
            return $code;
        });
    }

    public static function consumeQuickLoginToken($code, Request $request)
    {
        if (!is_string($code) || !preg_match('/\A[a-f0-9]{32}\z/D', $code)) abort(403, 'Invalid quick login token.');
        $key = CacheKey::get('TEMP_TOKEN', $code);
        $token = self::withLock($key . ':lock', function ($guard) use ($key) {
            $token = Cache::get($key);
            if ($token === null) abort(403, 'Invalid quick login token.');
            $guard();
            // Cache::pull ignores failed deletion; a checked pull must fail closed.
            if (!Cache::forget($key)) abort(503, 'Unable to consume quick login token.');
            return $token;
        });
        if (!is_array($token) || !isset($token['uid'], $token['authorization'])
            || (!is_int($token['uid']) && !is_string($token['uid']))
            || !ctype_digit((string)$token['uid']) || (int)$token['uid'] <= 0
            || !is_string($token['authorization'])) abort(403, 'Invalid quick login token.');

        // Release the one-time code lock before acquiring the user lock; never reacquire it.
        return self::withSessionLock($token['uid'], function ($guard) use ($token, $request) {
            $model = new User();
            return $model->getConnection()->transaction(function () use ($model, $guard, $token, $request) {
                $user = $model->newQuery()->lockForUpdate()->find($token['uid']);
                $identity = self::decryptAuthData($token['authorization']);
                if (!$user || (int)$user->banned || !$identity || (string)$identity['id'] !== (string)$user->id) {
                    abort(403, 'Authentication expired. Please log in again.');
                }
                return self::generateAuthDataLocked($user, $request, $guard);
            });
        });
    }
}
