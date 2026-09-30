<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class CommunityConsoleRoute
{
    public function map(Registrar $router)
    {
        $router->post('/guest/community/console', 'V1\\Guest\\CommunityConsoleController@handle');
    }
}
