<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramBindingService;
use Illuminate\Http\Request;

class TelegramBindingController extends Controller
{
    private function identity(Request $request): array
    {
        return $request->validate(['telegram_id' => 'required|integer|min:1|max:9007199254740991']);
    }

    public function requestCode(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request) + $request->validate([
            'email' => 'required|string|max:254|email:rfc',
            'request_id' => 'required|string|regex:/^[a-f0-9]{32}$/D',
        ]);
        return response()->json(['data' => $service->requestCode((int) $data['telegram_id'], $data['email'], $data['request_id'])]);
    }

    public function confirmCode(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request) + $request->validate([
            'code' => 'required|string|regex:/^[0-9]{6}$/D',
            'request_id' => 'required|string|regex:/^[a-f0-9]{32}$/D',
        ]);
        return response()->json(['data' => $service->confirmCode((int) $data['telegram_id'], $data['code'], $data['request_id'])]);
    }

    public function verify(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request) + $request->validate(['require_active_plan' => 'sometimes|boolean']);
        return response()->json(['data' => $service->verify((int) $data['telegram_id'], (bool) ($data['require_active_plan'] ?? false))]);
    }

    public function readSubscription(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request);
        return response()->json(['data' => $service->readSubscription((int) $data['telegram_id'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function readPlan(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request);
        return response()->json(['data' => $service->readPlan((int) $data['telegram_id'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function prepareUnbind(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request);
        return response()->json(['data' => $service->prepareUnbind((int) $data['telegram_id'])]);
    }

    public function confirmUnbind(Request $request, TelegramBindingService $service)
    {
        $data = $this->identity($request) + $request->validate([
            'confirmation_token' => 'required|string|regex:/^[a-f0-9]{32}$/D',
            'request_id' => 'required|string|regex:/^[a-f0-9]{32}$/D',
        ]);
        return response()->json(['data' => $service->confirmUnbind((int) $data['telegram_id'], $data['confirmation_token'], $data['request_id'])]);
    }
}
