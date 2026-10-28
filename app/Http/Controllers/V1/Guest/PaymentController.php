<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\PaymentAttemptService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!is_array($verify) || !is_scalar($verify['trade_no'] ?? null)
                || !is_scalar($verify['callback_no'] ?? null)
                || (string)$verify['trade_no'] === '' || (string)$verify['callback_no'] === '') abort(500, 'verify error');
            if (!$this->handle((string)$verify['trade_no'], (string)$verify['callback_no'], $paymentService->getPaymentId(), $paymentService->method, $verify)) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo, int $paymentId, string $method, array $verified)
    {
        (new PaymentAttemptService())->settle($tradeNo, $callbackNo, $paymentId, $method, $verified);
        return true;
    }
}
