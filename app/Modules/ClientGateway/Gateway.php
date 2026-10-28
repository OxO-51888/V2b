<?php

namespace App\Modules\ClientGateway;

use Illuminate\Http\Request;

class Gateway
{
    private $store;
    private $crypto;
    private $settings;
    private $base;
    private $proxy;

    public function __construct(?string $directory = null, ?PanelProxy $proxy = null)
    {
        $directory = $directory ?? config('panel_modules.gateway.directory');
        $this->settings = json_decode(file_get_contents($directory . '/settings.json'), true, 32, JSON_THROW_ON_ERROR);
        $panels = json_decode(file_get_contents($directory . '/panels.json'), true, 32, JSON_THROW_ON_ERROR);
        if (count($panels['panels'] ?? []) !== 1
            || ($panels['panels'][0]['id'] ?? '') !== config('panel_modules.gateway.panel_id')) {
            throw new \RuntimeException('Gateway must contain only this site');
        }
        $this->base = $panels['panels'][0];
        if (config('panel_modules.gateway.storage') !== 'database') {
            throw new \RuntimeException('Gateway requires panel database storage');
        }
        $this->store = new PanelStore(\Illuminate\Support\Facades\DB::connection(), $this->base['id']);
        $this->crypto = new Crypto($directory . '/data');
        $this->proxy = $proxy ?? new PanelProxy();
    }

    private function panel(): array
    {
        $panel = $this->base;
        $override = $this->store->get('panel_overrides', $panel['id']) ?? [];
        foreach (['name', 'status', 'allowedClientNames', 'entryDomain', 'entryCheckedAt'] as $key) {
            if (array_key_exists($key, $override)) $panel[$key] = $override[$key];
        }
        return $panel;
    }

    private function publicPanel(array $panel): array
    {
        $url = parse_url($panel['panelUrl']);
        $result = ['id' => $panel['id'], 'name' => $panel['name'], 'status' => $panel['status'],
            'backendDomain' => strtolower($url['host'] . (isset($url['port']) ? ':' . $url['port'] : '')),
            'gatewayTarget' => $panel['gatewayTarget'], 'allowedClientNames' => $panel['allowedClientNames'],
            'clientName' => $panel['allowedClientNames'][0] ?? '', 'hasPanelUrl' => true,
            'hasDatabase' => !empty($panel['database']['name']),
            'entryDomain' => $panel['entryDomain'] ?? '', 'entryCheckedAt' => $panel['entryCheckedAt'] ?? null];
        return $result + ['revision' => hash('sha256', Crypto::json($result))];
    }

    public function admin(string $method, string $path, array $data): array
    {
        if ($method === 'GET' && $path === '/api/admin/panels') {
            return ['defaultPanelId' => $this->base['id'], 'panels' => [$this->publicPanel($this->panel())]];
        }
        if ($method === 'GET' && $path === '/api/admin/devices') {
            $offset = filter_var($data['offset'] ?? 0, FILTER_VALIDATE_INT);
            abort_unless($offset !== false && $offset >= 0 && $offset <= 1000000, 422);
            return $this->store->devices($offset);
        }
        abort_unless($method === 'POST' && $path === '/api/admin/panels/update', 404);
        abort_if(array_diff(array_keys($data), ['panelId', 'name', 'status', 'clientName', 'revision']), 422);
        abort_unless(($data['panelId'] ?? '') === $this->base['id'], 422);
        foreach (['name', 'clientName'] as $key) {
            abort_unless(is_string($data[$key] ?? null) && trim($data[$key]) !== '' && mb_strlen($data[$key]) <= 120, 422);
        }
        abort_unless(in_array($data['status'] ?? '', ['active', 'disabled'], true) && is_string($data['revision'] ?? null), 422);
        return $this->store->transaction(function () use ($data) {
            $panel = $this->panel();
            $current = $this->publicPanel($panel);
            abort_unless(hash_equals($current['revision'], $data['revision']), 409, '设置已被修改，请刷新后重试');
            $name = trim($data['name']); $client = trim($data['clientName']);
            if ($current['name'] === $name && $current['clientName'] === $client && $current['status'] === $data['status']) return $current;
            $override = array_merge($this->store->get('panel_overrides', $panel['id']) ?? [],
                ['name' => $name, 'status' => $data['status'], 'allowedClientNames' => [$client], 'updatedAt' => Crypto::now()]);
            $this->store->put('panel_overrides', $panel['id'], $override);
            $this->store->audit('admin.panel.update', ['panelId' => $panel['id']] + $override);
            return $this->publicPanel($this->panel());
        });
    }

    public function entryPanel(): array { return $this->publicPanel($this->panel()); }

    public function entryProbe(string $challenge): array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $challenge), 422);
        $claim = ['challenge' => $challenge, 'panelId' => $this->base['id'], 'service' => 'panel-client-gateway',
            'publicKeyHash' => hash('sha256', trim($this->crypto->publicPem)), 'time' => Crypto::now()];
        return ['claim' => $claim, 'signature' => $this->crypto->sign($claim), 'publicKey' => $this->crypto->publicPem];
    }

    public function configureEntry(string $domain, string $revision, callable $check): array
    {
        $current = $this->entryPanel();
        abort_unless(hash_equals($current['revision'], $revision), 409, '设置已被修改，请刷新后重试');
        // External provisioning and HTTPS checks must not hold database row locks.
        $check($this->crypto->publicPem);
        return $this->store->transaction(function () use ($domain, $revision) {
            $current = $this->entryPanel();
            abort_unless(hash_equals($current['revision'], $revision), 409, '设置已被修改，请刷新后重试');
            $override = $this->store->get('panel_overrides', $this->base['id']) ?? [];
            $override['entryDomain'] = $domain;
            $override['entryCheckedAt'] = Crypto::now();
            $this->store->put('panel_overrides', $this->base['id'], $override);
            $this->store->audit('admin.entry.configure', ['panelId' => $this->base['id'], 'domain' => $domain]);
            return $this->entryPanel();
        });
    }

    public function handle(Request $request, string $endpoint): array
    {
        try {
            if (strlen($request->getContent()) > 4 * 1024 * 1024) $this->deny(413, 'BODY_TOO_LARGE');
            if ($request->isMethod('GET') && $endpoint === 'health') {
                return [200, ['ok' => true, 'service' => 'panel-client-gateway', 'time' => Crypto::now()]];
            }
            if ($request->isMethod('GET') && $endpoint === 'api/public-key') {
                return [200, ['ok' => true, 'data' => ['publicKey' => $this->crypto->publicPem, 'issuer' => $this->issuer()]]];
            }
            if (strpos($endpoint, 'api/gateway/panel/') === 0) {
                $path = substr($endpoint, strlen('api/gateway/panel'));
                if (!$this->proxy->allowed($request->method(), $path)) $this->deny(403, 'PANEL_GATEWAY_ENDPOINT_DENIED');
                [$session, $device, $panel] = $this->authorized($this->token($request));
                return $this->proxy->handle($request, $path, $panel);
            }
            if (!$request->isMethod('POST') || !in_array($endpoint, ['api/client/verify', 'api/client/heartbeat', 'api/client/deactivate'], true)) {
                $this->deny(404, 'NOT_FOUND');
            }
            try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { $this->deny(400, 'INVALID_JSON'); }
            if (!is_array($body) || array_is_list($body) && $body !== []) $this->deny(400, 'INVALID_JSON');
            if ($endpoint === 'api/client/verify') return [200, $this->verify($body, $request->attributes->get('native_gateway_entry', false) === true)];
            $token = $this->token($request) ?: $this->text($body['sessionToken'] ?? '', 200);
            if ($endpoint === 'api/client/deactivate') {
                if ($token !== '') $this->store->deleteSession(hash('sha256', $token));
                return [200, ['ok' => true, 'message' => '已退出授权会话']];
            }
            return [200, $this->heartbeat($token, $body)];
        } catch (GatewayException $e) {
            return [$e->status, ['ok' => false, 'authorized' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()]];
        }
    }

    private function verify(array $body, bool $entry = false): array
    {
        if (($body['clientId'] ?? '') !== $this->base['id']) $this->deny(403, 'SITE_MISMATCH');
        $key = $body['secure']['publicKey'] ?? $body['publicKey'] ?? '';
        if (!is_string($key) || $key === '') $this->deny(400, 'SECURE_KEY_REQUIRED');
        try { $key = $this->crypto->clientKey($key); }
        catch (\InvalidArgumentException $e) { $this->deny(400, 'SECURE_ENCRYPT_FAILED'); }
        $version = $this->text($body['version'] ?? '', 32);
        if ($version === '' || version_compare($version, $this->settings['minVersion'] ?? '0.1.0', '<')) $this->deny(426, 'AUTH_DENIED');
        $name = $this->text($body['clientName'] ?? $body['name'] ?? '', 120);
        $runtime = $this->text($body['runtimeName'] ?? $body['appName'] ?? $body['productName'] ?? $name, 120);
        $installer = ($body['requestType'] ?? $body['type'] ?? '') === 'installer';
        $deviceId = $this->text($body['deviceId'] ?? '', 160);
        if (!$installer && !preg_match('/\A[a-zA-Z0-9._:-]{12,160}\z/', $deviceId)) $this->deny(400, 'AUTH_DENIED');
        return $this->store->transaction(function () use ($body, $key, $version, $name, $runtime, $installer, $deviceId, $entry) {
            $panel = $this->panel();
            $this->checkNames($panel, $name, $runtime);
            $now = Crypto::now();
            $claim = ['issuer' => $this->issuer(), 'product' => $panel['id'], 'clientId' => $panel['id'],
                'clientName' => $name, 'runtimeName' => $runtime, 'version' => $version, 'panelId' => $panel['id'], 'issuedAt' => $now];
            if ($installer) {
                $claim += ['requestType' => 'installer', 'installerId' => $this->text($body['installerId'] ?? '', 160),
                    'expiresAt' => Crypto::now(600), 'policy' => ['allowInstall' => true]];
                $data = ['requestType' => 'installer', 'panelId' => $panel['id'], 'expiresAt' => $claim['expiresAt'],
                    'grant' => $claim, 'signature' => $this->crypto->sign($claim)];
            } else {
                $token = ($entry ? $panel['id'] . '.' : '') . Crypto::b64(random_bytes(32));
                $expiry = Crypto::now($this->ttl());
                $device = $this->store->get('devices', $deviceId) ?? ['deviceId' => $deviceId, 'firstSeenAt' => $now];
                $device = array_merge($device, ['status' => 'approved', 'clientId' => $panel['id'], 'panelId' => $panel['id'],
                    'version' => $version, 'clientName' => $name, 'runtimeName' => $runtime,
                    'appHash' => $this->text($body['appHash'] ?? '', 160), 'lastSeenAt' => $now]);
                unset($device['deviceName'], $device['os']);
                $email = $this->email($body['userEmail'] ?? $body['email'] ?? '');
                if ($email !== '') $device['userEmail'] = $email;
                $session = ['tokenHash' => hash('sha256', $token), 'deviceId' => $deviceId, 'clientId' => $panel['id'],
                    'panelId' => $panel['id'], 'issuedAt' => $now, 'expiresAt' => $expiry];
                $claim += ['deviceId' => $deviceId, 'expiresAt' => $expiry, 'policy' => ['allowRun' => true, 'allowCore' => true, 'allowTray' => true]];
                $data = ['sessionToken' => $token, 'expiresAt' => $expiry, 'heartbeatAfter' => $this->heartbeatAfter(),
                    'panelId' => $panel['id'], 'gateway' => ['panelBaseUrl' => $entry ? '/api/gateway/panel' : '/api/v1/client-gateway/api/gateway/panel'],
                    'license' => $claim, 'signature' => $this->crypto->sign($claim)];
            }
            $envelope = $this->crypto->envelope($key, ['ok' => true, 'authorized' => true, 'data' => $data]);
            if (!$installer) {
                $this->store->pruneSessions($now);
                $this->store->put('devices', $deviceId, $device);
                $this->store->put('sessions', $session['tokenHash'], $session);
            }
            $this->store->audit($installer ? 'client.verify.installer.secure.allowed' : 'client.verify.secure.allowed',
                ['clientId' => $panel['id'], 'version' => $version, 'deviceId' => $installer ? '' : $deviceId]);
            return $envelope;
        });
    }

    private function authorized(string $token): array
    {
        if ($token === '') $this->deny(401, 'SESSION_REQUIRED');
        $session = $this->store->get('sessions', hash('sha256', $token));
        if (!$session || !isset($session['expiresAt']) || $session['expiresAt'] <= Crypto::now()) $this->deny(401, 'SESSION_EXPIRED');
        $device = $this->store->get('devices', $session['deviceId']);
        if (!$device) $this->deny(403, 'DEVICE_NOT_ALLOWED');
        $panel = $this->panel();
        if (($session['panelId'] ?? '') !== $panel['id'] || ($device['panelId'] ?? '') !== $panel['id']) $this->deny(403, 'SITE_MISMATCH');
        $this->checkNames($panel, $device['clientName'] ?? '', $device['runtimeName'] ?? '');
        return [$session, $device, $panel];
    }

    private function heartbeat(string $token, array $body): array
    {
        return $this->store->transaction(function () use ($token, $body) {
            [$session, $device, $panel] = $this->authorized($token);
            $name = $this->text($body['clientName'] ?? $body['name'] ?? $device['clientName'], 120);
            $runtime = $this->text($body['runtimeName'] ?? $body['appName'] ?? $body['productName'] ?? $device['runtimeName'], 120);
            $this->checkNames($panel, $name, $runtime);
            $device['clientName'] = $name; $device['runtimeName'] = $runtime;
            $email = $this->email($body['userEmail'] ?? $body['email'] ?? '');
            if ($email !== '') $device['userEmail'] = $email;
            $device['lastSeenAt'] = Crypto::now();
            $session['expiresAt'] = Crypto::now($this->ttl());
            $this->store->put('sessions', $session['tokenHash'], $session);
            $this->store->put('devices', $device['deviceId'], $device);
            return ['ok' => true, 'authorized' => true, 'data' => ['expiresAt' => $session['expiresAt'],
                'serverTime' => Crypto::now(), 'heartbeatAfter' => $this->heartbeatAfter()]];
        });
    }

    private function checkNames(array $panel, string $name, string $runtime): void
    {
        if ($panel['status'] !== 'active') $this->deny(403, 'PANEL_DISABLED');
        if (($this->settings['strictClientName'] ?? true) === false) return;
        $names = array_map(fn($value) => mb_strtolower(trim($value)), $panel['allowedClientNames']);
        if (!in_array(mb_strtolower($name), $names, true) || !in_array(mb_strtolower($runtime), $names, true)) $this->deny(403, 'AUTH_DENIED');
    }

    private function token(Request $request): string
    {
        $header = $request->header('Authorization', '');
        return preg_match('/\ABearer\s+(.+)\z/i', $header, $match) ? trim($match[1]) : '';
    }

    private function text($value, int $max): string { return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : ''; }
    private function email($value): string { $value = strtolower($this->text($value, 160)); return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
    private function issuer(): string { return $this->settings['issuer'] ?? 'Client Authorization'; }
    private function heartbeatAfter(): int { return max(60, (int) ($this->settings['heartbeatAfterSeconds'] ?? 300)); }
    private function ttl(): int { return max($this->heartbeatAfter() + 300, (int) ($this->settings['sessionTtlSeconds'] ?? 7200)); }
    private function deny(int $status, string $code): void { throw new GatewayException($status, $code, '授权校验未通过，请重新打开客户端或联系管理员'); }
}
