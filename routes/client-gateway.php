<?php

use Illuminate\Support\Facades\Route;

if (!config('panel_modules.enabled')) return;

$controller = 'V1\\Guest\\PanelGatewayController@';
foreach (['health', 'api/public-key'] as $endpoint) {
    Route::get('/' . $endpoint, $controller . 'entry')->defaults('endpoint', $endpoint);
}
foreach (['verify', 'heartbeat', 'deactivate'] as $action) {
    $endpoint = 'api/client/' . $action;
    Route::post('/' . $endpoint, $controller . 'entry')->defaults('endpoint', $endpoint);
}
Route::match(['GET', 'POST'], '/api/gateway/panel/{path}', $controller . 'panelEntry')
    ->where('path', 'passport/auth/login|user/[A-Za-z]+(?:/[A-Za-z]+)?');
