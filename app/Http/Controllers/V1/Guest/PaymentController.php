<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
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
            if (!$this->handle((string)$verify['trade_no'], (string)$verify['callback_no'], $paymentService->getPaymentId(), $verify)) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo, int $paymentId, array $verified)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($paymentId <= 0 || (int)$order->payment_id !== $paymentId) abort(500, 'payment does not match');
        if (array_key_exists('paid_amount', $verified)
            && !$this->amountMatches($verified['paid_amount'], (int)$order->total_amount + (int)$order->handling_amount)) {
            abort(500, 'amount does not match');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }

    private function amountMatches($amount, int $expected): bool
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) return false;
        if (!preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/D', (string)$amount, $parts)) return false;
        $cents = (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
        return $cents === $expected;
    }
}
