<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class CommunityRoute
{
    public function map(Registrar $router)
    {
        $router->post('/user/community/{action}', 'V1\\User\\CommunityController@handle')
            ->where('action', 'bootstrap|history|post|read|delete|mute|upload|file');
    }
}
