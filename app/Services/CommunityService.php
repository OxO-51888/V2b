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
use Illuminate\Support\Facades\DB;

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
        if (is_file(storage_path('framework/community-identity-cutover.lock'))
            && !(PHP_SAPI === 'cli' && getenv('COMMUNITY_IDENTITY_PROBE') === '1')) {
            abort(503, '群聊维护中，请稍后重试');
        }
        $url = rtrim((string)config('community.url'), '/');
        $parts = parse_url($url);
        $localTunnel = ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', '::1'], true);
        abort_unless(($parts['scheme'] ?? '') === 'https' || $localTunnel, 503, '群聊尚未配置安全连接');
        $secret = (string)config('community.secret');
        abort_unless(strlen($secret) >= 32 && config('community.site'), 503, '群聊尚未配置');
        $body = json_encode(array_merge($data, [
            'action' => $action,
            'user_id' => $this->identityToken($user->id),
            'moderator' => (bool)$user->is_admin || (bool)$user->is_staff,
            'administrator' => (bool)$user->is_admin,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $path = '/bridge/v1';
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $body), $secret);
        $response = null;
        try {
            $response = (new Client(['connect_timeout' => 3, 'timeout' => 20, 'http_errors' => false, 'allow_redirects' => false]))
                ->post($url . $path, ['headers' => [
                    'Content-Type' => 'application/json',
                    'X-Chat-Site' => config('community.site'),
                    'X-Chat-Timestamp' => $timestamp,
                    'X-Chat-Nonce' => $nonce,
                    'X-Chat-Signature' => $signature,
                ], 'body' => $body, 'stream' => true]);
            $status = $response->getStatusCode();
            $limit = $status === 200 ? ($action === 'file' ? 4198400 : 1048576) : 16384;
            $result = $this->readBridgeResponse($response, $limit);
            if ($status === 200 && $action === 'file') $result = $this->safeImageResponse($result);
        } catch (\Throwable $error) {
            abort(503, '群聊返回异常，请稍后重试');
        } finally {
            if ($response !== null) {
                try { $response->getBody()->close(); } catch (\Throwable $ignored) {}
            }
        }
        if ($status !== 200) {
            $code = isset($result['error']) && is_string($result['error']) ? $result['error'] : '';
            abort(in_array($status, [403, 404, 409, 413, 422, 429], true) ? $status : 503,
                ['muted' => '当前已被禁言', 'rate_limited' => '发送过于频繁，请稍后再试', 'invalid_input' => '消息或图片格式不正确',
                 'not_found' => '消息或图片不存在', 'forbidden' => '无权执行此操作', 'conflict' => '请刷新后重试'][$code] ?? '群聊暂时不可用');
        }
        return $result;
    }

    private function readBridgeResponse($response, int $limit): array
    {
        $type = strtolower(trim($response->getHeaderLine('Content-Type')));
        if (!preg_match('/^application\/json(?:\s*;\s*charset=(?:"utf-8"|utf-8))?$/D', $type)) {
            throw new \UnexpectedValueException('Invalid bridge content type');
        }
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($length !== '' && (!preg_match('/^[0-9]+$/D', $length) || strlen($length) > 10 || (int)$length > $limit)) {
            throw new \UnexpectedValueException('Invalid bridge content length');
        }
        $stream = $response->getBody();
        $raw = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(8192, $limit + 1 - strlen($raw)));
            if ($chunk === '' && !$stream->eof()) throw new \UnexpectedValueException('Incomplete bridge response');
            $raw .= $chunk;
            if (strlen($raw) > $limit) throw new \UnexpectedValueException('Bridge response too large');
        }
        if ($length !== '' && strlen($raw) !== (int)$length) throw new \UnexpectedValueException('Truncated bridge response');
        $trimmed = ltrim($raw);
        if ($trimmed === '' || $trimmed[0] !== '{') throw new \UnexpectedValueException('Bridge object required');
        $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new \UnexpectedValueException('Bridge object required');
        return $result;
    }

    private function safeImageResponse(array $result): array
    {
        if (count($result) !== 2 || !isset($result['mime'], $result['data']) || !is_string($result['mime']) || !is_string($result['data'])
            || !in_array($result['mime'], ['image/png', 'image/jpeg'], true)
            || $result['data'] === '' || strlen($result['data']) > 4194304 || strlen($result['data']) % 4 !== 0) {
            throw new \UnexpectedValueException('Invalid image response');
        }
        $raw = base64_decode($result['data'], true);
        if ($raw === false || strlen($raw) > 3145728 || base64_encode($raw) !== $result['data']) {
            throw new \UnexpectedValueException('Invalid image encoding');
        }
        if ($result['mime'] === 'image/png') {
            $this->validatePngChunks($raw);
        } elseif (substr($raw, 0, 3) !== "\xff\xd8\xff" || substr($raw, -2) !== "\xff\xd9") {
            throw new \UnexpectedValueException('Invalid JPEG framing');
        }
        $image = null;
        set_error_handler(static function () { throw new \UnexpectedValueException('Invalid image data'); });
        try {
            $size = getimagesizefromstring($raw);
            $expectedType = $result['mime'] === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if ($size === false || $size[2] !== $expectedType || $size[0] < 1 || $size[1] < 1
                || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 24000000) {
                throw new \UnexpectedValueException('Invalid image dimensions');
            }
            $image = imagecreatefromstring($raw);
            if ($image === false || imagesx($image) !== $size[0] || imagesy($image) !== $size[1]) {
                throw new \UnexpectedValueException('Invalid image pixels');
            }
            // Re-encode actual pixels so remote attachment metadata, appended
            // payloads, and original container bytes never reach the browser.
            imagesavealpha($image, true);
            foreach ($expectedType === IMAGETYPE_PNG ? [0] : [90, 80, 70] as $quality) {
                ob_start();
                try {
                    $ok = $expectedType === IMAGETYPE_PNG ? imagepng($image, null, 9) : imagejpeg($image, null, $quality);
                    $clean = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if ($ok && is_string($clean) && $clean !== '' && strlen($clean) <= 3145728) {
                    return ['mime' => $result['mime'], 'data' => base64_encode($clean)];
                }
            }
            throw new \UnexpectedValueException('Normalized image too large');
        } finally {
            try { if ($image !== null && $image !== false) imagedestroy($image); }
            finally { restore_error_handler(); }
        }
    }

    private function validatePngChunks(string $raw): void
    {
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new \UnexpectedValueException('Invalid PNG signature');
        $offset = 8;
        $first = true;
        $end = false;
        while ($offset < strlen($raw)) {
            if (strlen($raw) - $offset < 12) throw new \UnexpectedValueException('Truncated PNG chunk');
            $length = unpack('N', substr($raw, $offset, 4))[1];
            if ($length > strlen($raw) - $offset - 12) throw new \UnexpectedValueException('Truncated PNG data');
            $type = substr($raw, $offset + 4, 4);
            if (!preg_match('/^[A-Za-z]{4}$/D', $type) || ($first && ($type !== 'IHDR' || $length !== 13))) {
                throw new \UnexpectedValueException('Invalid PNG chunk');
            }
            $data = substr($raw, $offset + 4, $length + 4);
            if (!hash_equals(hash('crc32b', $data, true), substr($raw, $offset + 8 + $length, 4))) {
                throw new \UnexpectedValueException('Invalid PNG checksum');
            }
            $offset += $length + 12;
            $first = false;
            if ($type === 'IEND') {
                if ($length !== 0 || $offset !== strlen($raw)) throw new \UnexpectedValueException('Invalid PNG end');
                $end = true;
                break;
            }
        }
        if (!$end) throw new \UnexpectedValueException('Missing PNG end');
    }

    public function identityToken($userId): string
    {
        [$site, $id, $token] = $this->identityDigest($userId);
        try {
            $identities = DB::table('community_identities');
            $identities->insertOrIgnore(['site' => $site, 'user_id' => $id, 'chat_id' => $token]);
            $stored = DB::table('community_identities')->where('site', $site)->where('user_id', $id)->value('chat_id');
            // A different identity key needs an explicit migration; never silently
            // replace an existing mapping and create a second remote account.
            if (!is_string($stored) || !hash_equals($token, $stored)) {
                throw new \UnexpectedValueException('Identity mapping mismatch');
            }
            return $token;
        } catch (\Throwable $error) {
            abort(503, '群聊身份暂时不可用');
        }
    }

    private function identityDigest($userId): array
    {
        $site = config('community.site');
        $secret = config('community.identity_secret');
        abort_unless(is_string($site) && preg_match('/^[a-z0-9_-]{1,32}$/D', $site)
            && is_string($secret) && preg_match('/^[a-f0-9]{64}$/Di', $secret), 503, '群聊身份尚未配置');
        abort_unless(is_int($userId) || is_string($userId), 503, '群聊身份无效');
        $id = (string)$userId;
        $maximum = (string)PHP_INT_MAX;
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $id)
            && (strlen($id) < strlen($maximum) || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) <= 0)),
            503, '群聊身份无效');
        $token = 'c1' . hash_hmac('sha256', "panelchat-identity-v1\0" . $site . "\0" . $id, hex2bin($secret));
        return [$site, $id, $token];
    }

    private function identityPanelId($token): string
    {
        abort_unless(is_string($token) && preg_match('/^c1[a-f0-9]{64}$/D', $token), 404, '账号不存在');
        try {
            $panelId = DB::table('community_identities')->where('site', config('community.site'))->where('chat_id', $token)->value('user_id');
        } catch (\Throwable $error) {
            abort(503, '群聊身份暂时不可用');
        }
        abort_unless($panelId !== null, 404, '账号不存在');
        [, $id, $expected] = $this->identityDigest($panelId);
        abort_unless(hash_equals($expected, $token), 404, '账号不存在');
        return $id;
    }

    public function accountDetails(User $viewer, string $targetId): array
    {
        abort_unless((int)$viewer->is_admin, 403, '仅管理员可查看账号详情');
        // Resolve the site-scoped chat identity on the bridge, never trust a browser-supplied panel ID.
        $identity = $this->call($viewer, 'member', ['target_id' => $targetId]);
        $panelId = $this->identityPanelId($identity['panel_user'] ?? null);
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
            $rules['edit'] = ['post_id' => 'required|regex:/^[a-z0-9]{26}$/D', 'message' => 'present|nullable|string|max:2000'];
            $rules['events'] = ['cursor' => 'nullable|string|max:53|regex:/^[a-f0-9]{32}:[0-9]{1,20}$/D'];
            $rules['account'] = ['target_id' => 'required|regex:/^[a-z0-9]{26}$/'];
            $rules['muted_members'] = ['after' => 'nullable|regex:/^[a-z0-9]{26}$/'];
        }
        abort_unless(isset($rules[$action]), 404);
        $data = $request->validate($rules[$action]);
        if ($action === 'edit') {
            // The chat service checks authorship and whether existing images permit empty text.
            $data['message'] = (string)($data['message'] ?? '');
        }
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
