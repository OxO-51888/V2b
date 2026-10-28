<?php

namespace App\Modules\Core;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

class PaymentAttemptService
{
    private const TABLE = 'v2_payment_attempt';

    public function prepareCheckout(string $tradeNo, int $userId, Payment $payment): Order
    {
        return DB::transaction(function () use ($tradeNo, $userId, $payment) {
            $order = Order::where('trade_no', $tradeNo)->where('user_id', $userId)->lockForUpdate()->first();
            if (!$order || (int)$order->status !== 0 || (int)$order->total_amount <= 0) {
                abort(500, __('Order does not exist or has been paid'));
            }
            if ((int)$payment->enable !== 1 || (int)$payment->id <= 0) abort(500, 'payment is not available');

            // Preserve an already-issued pre-upgrade link before replacing its binding.
            if ((int)$order->payment_id > 0) {
                $this->remember($order, (int)$order->payment_id, (int)$order->handling_amount);
            }
            $fee = null;
            if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
                $fee = (int)round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
            }
            $this->remember($order, (int)$payment->id, (int)$fee);
            $order->payment_id = $payment->id;
            $order->handling_amount = $fee;
            if (!$order->save()) abort(500, 'payment binding failed');
            return $order;
        }, 3);
    }

    // Called only after the configured payment driver has verified the notification.
    public function settle(string $tradeNo, string $callbackNo, int $paymentId, string $method, array $verified): array
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Payment settlement must own its transaction');
        if ($tradeNo === '' || $callbackNo === '' || $paymentId <= 0 || $method === '') abort(500, 'invalid payment');
        $hasAmount = array_key_exists('paid_amount', $verified);
        $amount = $hasAmount ? $this->amountInCents($verified['paid_amount']) : null;
        if (in_array($method, ['EPay', 'SGEpay'], true) && !$hasAmount) abort(500, 'missing payment amount');
        $result = DB::transaction(function () use ($tradeNo, $callbackNo, $paymentId, $method, $hasAmount, $amount) {
            $order = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order) abort(500, 'order is not found');
            $status = (int)$order->status;
            if (!in_array($status, [0, 1, 3, 4], true)) abort(500, 'order is not payable');
            if ($status !== 0 && ((int)$order->payment_id !== $paymentId || (string)$order->callback_no !== $callbackNo)) {
                abort(500, 'payment already settled with another receipt');
            }

            $attempts = DB::table(self::TABLE)->where('order_id', $order->id)->get();
            // Legacy compatibility is restricted to the one binding already on the order.
            if ($attempts->isEmpty() && (int)$order->payment_id === $paymentId) {
                $this->remember($order, $paymentId, (int)$order->handling_amount);
                $attempts = DB::table(self::TABLE)->where('order_id', $order->id)->get();
            }
            $matches = $attempts->filter(function ($attempt) use ($order, $paymentId, $hasAmount, $amount) {
                return (int)$attempt->payment_id === $paymentId
                    && (int)$attempt->total_amount === (int)$order->total_amount
                    && (!$hasAmount || (int)$attempt->expected_amount === $amount);
            })->values();
            // A driver without an amount cannot distinguish multiple fee snapshots.
            if ($matches->count() !== 1) abort(500, 'payment attempt or amount does not match');
            $attempt = $matches->first();
            $receiptHash = hash('sha256', $method . "\0" . $callbackNo);
            if ($attempt->callback_hash !== null && !hash_equals($attempt->callback_hash, $receiptHash)) {
                abort(500, 'payment attempt already settled');
            }
            // Unique across this provider, including its different configured channels.
            // A collision fails closed and rolls back the order transition.
            DB::table(self::TABLE)->where('id', $attempt->id)->update(['callback_hash' => $receiptHash]);
            if ($status !== 0) return ['order' => $order, 'newly_paid' => false];

            $changed = Order::where('id', $order->id)->where('status', 0)->update([
                'payment_id' => $paymentId,
                'handling_amount' => (int)$attempt->handling_amount,
                'status' => 1,
                'paid_at' => time(),
                'callback_no' => $callbackNo,
            ]);
            if ($changed !== 1) abort(500, 'order payment state changed');
            $order->refresh();
            return ['order' => $order, 'newly_paid' => true];
        }, 3);

        // Never enqueue before the payment transaction commits. A retry can recover
        // a queue outage without rewriting the receipt or granting the plan twice.
        if ((int)$result['order']->status === 1) {
            app(Dispatcher::class)->dispatch(new OrderHandleJob($result['order']->trade_no));
        }
        return $result;
    }

    private function remember(Order $order, int $paymentId, int $fee): void
    {
        $total = (int)$order->total_amount;
        if ($paymentId <= 0 || $total <= 0 || $fee < 0) abort(500, 'invalid payment snapshot');
        DB::table(self::TABLE)->insertOrIgnore([
            'order_id' => $order->id,
            'payment_id' => $paymentId,
            'total_amount' => $total,
            'handling_amount' => $fee,
            'expected_amount' => $total + $fee,
            'created_at' => time(),
        ]);
    }

    private function amountInCents($amount): int
    {
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/D', (string)$amount, $parts)) {
            abort(500, 'invalid payment amount');
        }
        return (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
    }
}
