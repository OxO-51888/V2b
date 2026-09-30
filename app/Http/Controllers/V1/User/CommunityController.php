<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CommunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CommunityController extends Controller
{
    public function handle(Request $request, string $action, CommunityService $community)
    {
        $user = $community->authenticate($request);
        abort_if($action === 'mute' && !(int)$user->is_admin && !(int)$user->is_staff, 403);
        $key = 'community:' . $user->id . ':' . (in_array($action, ['post', 'upload'], true) ? 'write' : 'read');
        $limit = in_array($action, ['post', 'upload'], true) ? 20 : 100;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, '操作过于频繁，请稍后再试');
        RateLimiter::hit($key, 60);
        $data = $community->validatedData($request, $action);
        $result = $community->call($user, $action, $data);
        if ($action === 'bootstrap') {
            $result['me']['can_view_accounts'] = false;
        } elseif ($action === 'history') {
            $result['can_view_accounts'] = false;
        }
        return response()->json(['data' => $result])
            ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
