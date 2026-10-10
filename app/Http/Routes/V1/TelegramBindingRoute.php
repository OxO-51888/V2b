<?php

namespace App\Http\Routes\V1;

use App\Http\Middleware\TelegramBotAuth;
use Illuminate\Contracts\Routing\Registrar;

class TelegramBindingRoute
{
    public function map(Registrar $router)
    {
        $router->group(['prefix' => 'bot/telegram', 'middleware' => [TelegramBotAuth::class]], function ($router) {
            $router->post('email/request', 'V1\\Guest\\TelegramBindingController@requestCode');
            $router->post('email/confirm', 'V1\\Guest\\TelegramBindingController@confirmCode');
            $router->post('email/unbind/prepare', 'V1\\Guest\\TelegramBindingController@prepareUnbind');
            $router->post('email/unbind/confirm', 'V1\\Guest\\TelegramBindingController@confirmUnbind');
            $router->post('verify', 'V1\\Guest\\TelegramBindingController@verify');
            $router->post('account/subscription', 'V1\\Guest\\TelegramBindingController@readSubscription');
            $router->post('account/plan', 'V1\\Guest\\TelegramBindingController@readPlan');
        });
    }
}
