<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PanelModuleRoute
{
    public function map(Registrar $router)
    {
        if (!config('panel_modules.enabled')) return;
        $router->match(['GET', 'POST'], '/client-gateway/{endpoint}', 'V1\\Guest\\PanelGatewayController@relay')
            ->where('endpoint', '.+');
        $router->match(['GET', 'POST'], '/client-entry/{endpoint}', 'V1\\Guest\\PanelGatewayEntryController@relay')
            ->where('endpoint', '.+');
        $router->group([
            'prefix' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/modules',
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            $controller = 'V1\\Admin\\PanelModuleController@';
            $router->get('/gateway', $controller . 'gateway');
            $router->post('/gateway', $controller . 'gatewaySave');
            $router->post('/gateway/configure', $controller . 'gatewayConfigure');
            $router->get('/gateway/devices', $controller . 'devices');
            $router->get('/telegram', $controller . 'telegram');
            $router->post('/telegram', $controller . 'telegramSave');
            $router->post('/telegram/configure', $controller . 'telegramConfigure');
            $router->get('/telegram/events', $controller . 'events');
        });
    }
}
