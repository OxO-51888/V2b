<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\CommunityService;
use App\Services\CommunityConsoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CommunityConsoleController extends Controller
{
    public function handle(Request $request, CommunityService $community, CommunityConsoleService $console)
    {
        $admin = $console->authenticate($request);
        $input = $request->validate(['action' => 'required|string', 'data' => 'present|array']);
        $action = $input['action'];
        $write = in_array($action, ['post', 'upload', 'delete', 'mute'], true);
        $bucket = $action === 'events' ? 'events' : ($write ? 'write' : 'read');
        $limit = $action === 'events' ? 90 : ($write ? 30 : 150);
        $key = 'community_console:' . $admin->id . ':' . $bucket;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, '操作过于频繁，请稍后再试');
        RateLimiter::hit($key, 60);
        $data = $community->validatedData(Request::create('/', 'POST', $input['data']), $action, true);
        $result = $action === 'account'
            ? $community->accountDetails($admin, $data['target_id'])
            : $community->call($admin, $action, $data);
        if ($action === 'bootstrap') $result['me']['can_view_accounts'] = true;
        if ($action === 'history') $result['can_view_accounts'] = true;
        if (in_array($action, ['account', 'post', 'delete', 'mute'], true)) {
            Log::info('community_console_action', ['admin_id' => $admin->id, 'action' => $action,
                'target_id' => $data['target_id'] ?? $data['post_id'] ?? null]);
        }
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
