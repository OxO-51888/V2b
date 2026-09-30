<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommunityService
{
    public function authenticate(Request $request)
    {
        abort_unless(config('community.enabled'), 404);
        $authorization = $request->header('authorization');
        abort_unless(is_string($authorization) && strlen($authorization) < 4096, 401, '请先登录面板');
        try {
            $claims = (array)JWT::decode($authorization, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable $error) {
            abort(401, '登录已失效，请重新登录');
        }
        abort_unless(isset($claims['id'], $claims['session']) && is_scalar($claims['id']) && is_string($claims['session']), 401);
        $sessions = Cache::get(CacheKey::get('USER_SESSIONS', $claims['id']), []);
        abort_unless(is_array($sessions) && array_key_exists($claims['session'], $sessions), 401, '登录已失效，请重新登录');
        // Do not reuse the panel's cached identity: revocations and bans apply immediately.
        $user = User::find($claims['id']);
        abort_unless($user && !(int)$user->banned, 403, '当前账号无法进入群聊');
        $allowed = array_filter(array_map('trim', explode(',', (string)config('community.allowed_users'))));
        $hasActivePlan = (int)$user->plan_id > 0
            && ($user->expired_at === null || (int)$user->expired_at > time());
        abort_unless((int)$user->is_admin || in_array((string)$user->id, $allowed, true) || $hasActivePlan,
            403, '群聊仅向有效套餐用户开放，请检查套餐及到期时间');
        return $user;
    }

    public function call(User $user, string $action, array $data)
    {
        $url = rtrim((string)config('community.url'), '/');
        $parts = parse_url($url);
        $localTunnel = ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', '::1'], true);
        abort_unless(($parts['scheme'] ?? '') === 'https' || $localTunnel, 503, '群聊尚未配置安全连接');
        $secret = (string)config('community.secret');
        abort_unless(strlen($secret) >= 32 && config('community.site'), 503, '群聊尚未配置');
        $body = json_encode(array_merge($data, [
            'action' => $action,
            'user_id' => (string)$user->id,
            'moderator' => (bool)$user->is_admin || (bool)$user->is_staff,
            'administrator' => (bool)$user->is_admin,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $path = '/bridge/v1';
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $body), $secret);
        try {
            $response = (new Client(['connect_timeout' => 3, 'timeout' => 20, 'http_errors' => false, 'allow_redirects' => false]))
                ->post($url . $path, ['headers' => [
                    'Content-Type' => 'application/json',
                    'X-Chat-Site' => config('community.site'),
                    'X-Chat-Timestamp' => $timestamp,
                    'X-Chat-Nonce' => $nonce,
                    'X-Chat-Signature' => $signature,
                ], 'body' => $body]);
        } catch (\Throwable $error) {
            abort(503, '群聊暂时连接不上，请稍后重试');
        }
        $status = $response->getStatusCode();
        $result = json_decode((string)$response->getBody(), true);
        if ($status >= 400) {
            abort(in_array($status, [403, 404, 409, 413, 422, 429], true) ? $status : 503,
                ['muted' => '当前已被禁言', 'rate_limited' => '发送过于频繁，请稍后再试', 'invalid_input' => '消息或图片格式不正确',
                 'not_found' => '消息或图片不存在', 'forbidden' => '无权执行此操作', 'conflict' => '请刷新后重试'][$result['error'] ?? ''] ?? '群聊暂时不可用');
        }
        abort_unless(is_array($result), 503, '群聊返回异常');
        return $result;
    }

    public function accountDetails(User $viewer, string $targetId): array
    {
        abort_unless((int)$viewer->is_admin, 403, '仅管理员可查看账号详情');
        // Resolve the site-scoped chat identity on the bridge, never trust a browser-supplied panel ID.
        $identity = $this->call($viewer, 'member', ['target_id' => $targetId]);
        $panelId = $identity['panel_user'] ?? null;
        abort_unless(is_string($panelId) && preg_match('/^[1-9][0-9]*$/D', $panelId), 404, '账号不存在');
        $target = User::select(['id', 'email', 'plan_id', 'expired_at', 'created_at', 'last_login_at',
            'banned', 'is_admin', 'is_staff', 'transfer_enable', 'u', 'd', 'balance', 'commission_balance',
            'device_limit', 'speed_limit'])->find($panelId);
        abort_unless($target, 404, '账号不存在');
        $plan = $target->plan_id ? Plan::select(['id', 'name'])->find($target->plan_id) : null;
        $used = (int)$target->u + (int)$target->d;
        return [
            'id' => (int)$target->id,
            'email' => $target->email,
            'role' => $target->is_admin ? 'admin' : ($target->is_staff ? 'staff' : 'user'),
            'banned' => (bool)$target->banned,
            'plan_id' => $target->plan_id ? (int)$target->plan_id : null,
            'plan_name' => $plan ? $plan->name : null,
            'subscription_status' => !$target->plan_id ? 'none' :
                ($target->expired_at !== null && (int)$target->expired_at <= time() ? 'expired' : 'active'),
            'expired_at' => $target->expired_at === null ? null : (int)$target->expired_at,
            'created_at' => (int)$target->created_at,
            'last_login_at' => $target->last_login_at ? (int)$target->last_login_at : null,
            'traffic_total' => (int)$target->transfer_enable,
            'traffic_used' => $used,
            'traffic_remaining' => max(0, (int)$target->transfer_enable - $used),
            'balance' => (int)$target->balance,
            'commission_balance' => (int)$target->commission_balance,
            'currency' => (string)config('v2board.currency', 'CNY'),
            'device_limit' => $target->device_limit ? (int)$target->device_limit : null,
            'speed_limit' => $target->speed_limit ? (int)$target->speed_limit : null,
        ];
    }

    public function validatedData(Request $request, string $action, bool $console = false): array
    {
        $rules = [
            'bootstrap' => [],
            'history' => ['before' => 'nullable|regex:/^[a-z0-9]{26}$/'],
            'post' => ['message' => 'nullable|string|max:2000', 'root_id' => 'nullable|regex:/^[a-z0-9]{26}$/',
                'file_ids' => 'nullable|array|max:2', 'file_ids.*' => 'regex:/^[a-z0-9]{26}$/', 'client_id' => 'required|uuid'],
            'read' => [],
            'delete' => ['post_id' => 'required|regex:/^[a-z0-9]{26}$/'],
            'mute' => ['target_id' => 'required|regex:/^[a-z0-9]{26}$/', 'muted' => 'required|boolean'],
            'upload' => $console ? ['image_data' => 'required|string|max:4194304']
                : ['image' => 'required|file|mimetypes:image/png,image/jpeg,image/webp|max:3072'],
            'file' => ['file_id' => 'required|regex:/^[a-z0-9]{26}$/'],
        ];
        if ($console) {
            $rules['events'] = ['cursor' => 'nullable|string|max:53|regex:/^[a-f0-9]{32}:[0-9]{1,20}$/D'];
            $rules['account'] = ['target_id' => 'required|regex:/^[a-z0-9]{26}$/'];
            $rules['muted_members'] = ['after' => 'nullable|regex:/^[a-z0-9]{26}$/'];
        }
        abort_unless(isset($rules[$action]), 404);
        $data = $request->validate($rules[$action]);
        if ($action === 'post') {
            abort_if(trim((string)($data['message'] ?? '')) === '' && empty($data['file_ids']), 422, '请输入消息');
            $data['client_id'] = strtolower($data['client_id']);
        }
        if ($action === 'upload' && !$console) {
            $data = ['image_data' => base64_encode(file_get_contents($request->file('image')->getRealPath()))];
        }
        return $data;
    }
}
