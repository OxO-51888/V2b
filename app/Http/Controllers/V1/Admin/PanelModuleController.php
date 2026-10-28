<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PanelModuleService;
use Illuminate\Http\Request;

class PanelModuleController extends Controller
{
    public function gateway(PanelModuleService $service)
    {
        $data = $service->request('gateway', 'GET', '/api/admin/panels');
        $data['entry_urls'] = \App\Services\ClientGateway\SubscriptionEntry::upstreams();
        $data['entry_url'] = $data['entry_urls'][0] ?? '';
        $data['public_url'] = $data['entry_url'] === '' ? '' : $data['entry_url'] . '/api/v1/client-gateway';
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function gatewaySave(Request $request, PanelModuleService $service)
    {
        $this->exact($request, ['name', 'status', 'clientName', 'revision']);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'clientName' => 'required|string|max:120',
            'status' => 'required|in:active,disabled', 'revision' => 'required|string|size:64',
        ]);
        $data['panelId'] = config('panel_modules.gateway.panel_id');
        return ['data' => $service->request('gateway', 'POST', '/api/admin/panels/update', $data)];
    }

    public function devices(Request $request, PanelModuleService $service)
    {
        $this->exact($request, ['offset']);
        $data = $request->validate(['offset' => 'sometimes|integer|min:0|max:1000000']);
        return response()->json(['data' => $service->request('gateway', 'GET', '/api/admin/devices', $data)])->header('Cache-Control', 'no-store');
    }

    public function telegram(PanelModuleService $service)
    {
        return response()->json(['data' => $service->request('telegram', 'GET', '/v1/state')])->header('Cache-Control', 'no-store');
    }

    public function telegramSave(Request $request, PanelModuleService $service)
    {
        return $this->saveTelegram($request, $service, false);
    }

    public function telegramConfigure(Request $request, PanelModuleService $service)
    {
        abort_unless(config('panel_modules.telegram.driver') === 'local', 404);
        return $this->saveTelegram($request, $service, true);
    }

    private function saveTelegram(Request $request, PanelModuleService $service, bool $configure)
    {
        $this->exact($request, array_merge(['enabled', 'verify_join', 'verify_message', 'revision'], $configure ? ['token', 'chat_id'] : []));
        $data = $request->validate([
            'enabled' => 'required|boolean', 'verify_join' => 'required|boolean',
            'verify_message' => 'required|boolean', 'revision' => 'required|string|size:64',
            'token' => 'sometimes|nullable|string|max:128', 'chat_id' => 'sometimes|string|regex:/^-[1-9][0-9]{0,14}$/',
        ]);
        foreach (['enabled', 'verify_join', 'verify_message'] as $key) {
            abort_unless(is_bool($data[$key]), 422, '开关格式不正确');
        }
        return ['data' => $service->request('telegram', 'POST', $configure ? '/v1/configure' : '/v1/group', $data)];
    }

    public function events(Request $request, PanelModuleService $service)
    {
        $this->exact($request, ['before', 'limit']);
        $data = $request->validate(['before' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:100']);
        return response()->json(['data' => $service->request('telegram', 'GET', '/v1/events', $data)])->header('Cache-Control', 'no-store');
    }

    private function exact(Request $request, array $allowed): void
    {
        // Admin middleware replaces this field with its authenticated user context.
        $allowed[] = 'user';
        abort_if(array_diff(array_keys($request->all()), $allowed), 422, '请求包含不允许的设置');
    }
}
