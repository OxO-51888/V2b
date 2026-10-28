<?php

namespace App\Modules\System;

use App\Services\PanelTelegramService;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class PanelModuleService
{
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function assertEnabled(): void
    {
        abort_unless(config('panel_modules.enabled') && config('panel_modules.site'), 404);
    }

    public function request(string $module, string $method, string $path, array $data = []): array
    {
        $this->assertEnabled();
        abort_unless(in_array($module, ['gateway', 'telegram'], true), 404);
        if ($module === 'gateway') {
            return app(\App\Services\ClientGateway\Gateway::class)->admin($method, $path, $data);
        }
        abort_unless(config('outbound.enabled', true), 503, 'Outbound notifications are disabled');
        if (config('panel_modules.telegram.driver') === 'local') {
            return app(\App\Services\PanelTelegramService::class)->request($method, $path, $data);
        }
        $base = rtrim((string) config("panel_modules.$module.url"), '/');
        $token = (string) config("panel_modules.$module.token");
        $allowed = $module === 'gateway'
            ? ['GET /api/admin/panels', 'POST /api/admin/panels/update', 'GET /api/admin/devices']
            : ['GET /v1/state', 'GET /v1/events', 'POST /v1/group'];
        abort_unless(in_array("$method $path", $allowed, true), 404);
        $url = parse_url($base);
        $valid = $module === 'gateway'
            ? (($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === '127.0.0.1')
            : (($url['scheme'] ?? '') === 'https');
        abort_unless($valid && strlen($token) >= 64, 503, '模块尚未配置');
        try {
            $headers = ['Accept' => 'application/json', 'User-Agent' => 'PanelModules/1.0'];
            $headers[$module === 'gateway' ? 'X-Admin-Token' : 'Authorization'] = $module === 'gateway' ? $token : 'Bearer ' . $token;
            $response = $this->http->request($method, $base . $path, [
                'connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false, 'http_errors' => false,
                'headers' => $headers, $method === 'GET' ? 'query' : 'json' => $data,
            ]);
        } catch (\Throwable $e) {
            abort(503, '模块暂时不可用，请稍后重试');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $status = in_array($response->getStatusCode(), [409, 422], true) ? $response->getStatusCode() : 503;
            abort($status, $status === 409 ? '设置已被其他管理员修改，请刷新后重试' : ($status === 422 ? '设置格式不正确' : '模块暂时不可用，请稍后重试'));
        }
        $decoded = json_decode((string) $response->getBody(), true);
        $payload = $decoded['data'] ?? null;
        abort_unless(is_array($payload), 503, '模块返回数据异常');
        if ($module === 'telegram' && $path === '/v1/state') {
            abort_unless(($payload['site'] ?? null) === config('panel_modules.site')
                && (string) ($payload['group']['chat_id'] ?? '') === (string) config('panel_modules.telegram.chat_id'), 503, '本站群组配置不匹配');
        }
        if ($module === 'gateway' && $path === '/api/admin/panels') {
            abort_unless(count($payload['panels'] ?? []) === 1
                && $payload['panels'][0]['id'] === config('panel_modules.gateway.panel_id'), 503, '本站网关配置不匹配');
        }
        return $payload;
    }
}
