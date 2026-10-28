<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Modules\ClientGateway\ClientStateService;
use Illuminate\Http\Request;

class ClientStateController extends Controller
{
    public function show(Request $request, ClientStateService $state)
    {
        $scope = $request->query('scope', 'full');
        if (!is_string($scope) || !in_array($scope, ['full', 'account'], true)) abort(422, '客户端状态范围无效');
        return response(['data' => $state->snapshot($request, $scope)])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
