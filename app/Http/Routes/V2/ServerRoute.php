<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Server\Hy2Controller;
use App\Http\Middleware\Hy2Node;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group(['prefix' => 'server/hy2', 'middleware' => [Hy2Node::class]], function ($router) {
            $router->get('config', [Hy2Controller::class, 'config']);
            $router->get('users', [Hy2Controller::class, 'users']);
            $router->get('state', [Hy2Controller::class, 'state']);
            $router->post('traffic', [Hy2Controller::class, 'traffic']);
            $router->get('alive', [Hy2Controller::class, 'alive']);
            $router->post('alive', [Hy2Controller::class, 'reportAlive']);
        });
    }
}
