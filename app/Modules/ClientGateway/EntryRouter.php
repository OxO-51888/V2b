<?php

namespace App\Modules\ClientGateway;

use Illuminate\Http\Request;

class EntryRouter
{
    public function target(Request $request, string $endpoint, array $policy, array $sessions = []): string
    {
        $panels = array_column($policy['sites'] ?? [], 'panel_id');
        $body = [];
        if ($request->isMethod('POST')) {
            try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new GatewayException(400, 'INVALID_JSON', '请求格式错误'); }
            if (!is_array($body) || (array_is_list($body) && $body !== [])) {
                throw new GatewayException(400, 'INVALID_JSON', '请求格式错误');
            }
        }
        if ($endpoint === 'api/client/verify') {
            $panel = $body['clientId'] ?? '';
            if (!is_string($panel) || !in_array($panel, $panels, true)) {
                throw new GatewayException(403, 'SITE_MISMATCH', '客户端与网站不匹配');
            }
            return $panel;
        }
        $token = $request->bearerToken() ?: ($body['sessionToken'] ?? '');
        if (!is_string($token) || strlen($token) > 200) $token = '';
        $panel = preg_match('/\A(panel_[0-9]{2})\.[A-Za-z0-9_-]+\z/', $token, $match)
            ? $match[1] : ($sessions[hash('sha256', $token)] ?? '');
        if (!is_string($panel) || !in_array($panel, $panels, true)) {
            throw new GatewayException(401, $token === '' ? 'SESSION_REQUIRED' : 'SESSION_EXPIRED', '请重新打开客户端');
        }
        return $panel;
    }

    public function dispatch(Request $request, string $endpoint): ?string
    {
        if ($request->server('PANEL_GATEWAY_DISPATCH') !== '1') return null;
        if (in_array($endpoint, ['health', 'api/public-key'], true)) return null;
        $policy = json_decode(file_get_contents(config('panel_modules.gateway.entry_policy')), true, 32, JSON_THROW_ON_ERROR);
        $path = config('panel_modules.gateway.session_routes');
        $sessions = $path && is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        $panel = $this->target($request, $endpoint, $policy, $sessions);
        return $panel === config('panel_modules.gateway.panel_id') ? null : $panel;
    }
}
