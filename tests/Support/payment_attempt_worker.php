<?php

// Validate before loading any application code or connecting. Never infer a DB.
$config = json_decode(getenv('PAYMENT_ATTEMPT_TEST_CONNECTION') ?: getenv('PAYMENT_TEST_CONNECTION') ?: 'null', true, 512, JSON_THROW_ON_ERROR);
$socket = is_array($config) ? realpath($config['unix_socket'] ?? '') : false;
$root = $socket ? dirname($socket) : false;
$directory = realpath($argv[2] ?? '');
$bootstrap = realpath($argv[1] ?? '');
$expectedBootstrap = realpath(getenv('PAYMENT_ATTEMPT_TEST_BOOTSTRAP') ?: getenv('ORDER_TEST_BOOTSTRAP') ?: '');
if (!$root || !preg_match('~^/tmp/(?:payment-attempt|order-safety)-test-[a-f0-9]+$~D', $root)
    || ($config['driver'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'payment_attempt_test'
    || ($config['host'] ?? null) !== '' || array_intersect(['url', 'read', 'write'], array_keys($config))
    || !$directory || dirname($directory) !== $root || !preg_match('/^workers-[a-f0-9]+$/D', basename($directory))
    || !$bootstrap || $bootstrap !== $expectedBootstrap || !is_file($bootstrap) || !is_readable($bootstrap)
    || !preg_match('/^[0-9]+$/D', $argv[3] ?? '')) {
    throw new RuntimeException('Refusing a non-isolated payment worker');
}
$config['unix_socket'] = $socket;
require $bootstrap;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\PaymentAttemptDatabase;

[$app, $db] = PaymentAttemptDatabase::boot($config);
$request = json_decode(base64_decode($argv[4], true), true, 512, JSON_THROW_ON_ERROR);
$order = Order::findOrFail((int)$request['order_id']);
if (!in_array($request['action'], ['settle', 'open'], true)) throw new RuntimeException('Unknown worker action');
// Widen the opening transaction without changing lock/transaction semantics.
User::retrieved(function () { usleep(100000); });
touch($directory . '/' . $argv[3] . '.ready');
$deadline = microtime(true) + 15;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Payment start barrier timed out');
    usleep(10000);
}
try {
    if ($request['action'] === 'settle') {
        $result = (new PaymentAttemptService())->settle(
            $order->trade_no, $request['receipt'], (int)$request['payment_id'],
            $request['method'], ['paid_amount' => $request['amount']]
        );
        $newlyPaid = (bool)$result['newly_paid'];
    } else {
        (new OrderService($order))->open();
        $newlyPaid = false;
    }
    $result = ['accepted' => true, 'newly_paid' => $newlyPaid];
} catch (HttpExceptionInterface $error) {
    // Only an intentional business rejection counts as the losing callback.
    // SQL errors, missing classes and other faults must fail the worker/test.
    $result = ['accepted' => false, 'newly_paid' => false, 'error' => $error->getMessage(), 'status' => $error->getStatusCode()];
} catch (QueryException $error) {
    // The receipt uniqueness constraint is the concurrent cross-order arbiter.
    // Accept only MySQL duplicate-key on that update; never hide other SQL faults.
    if ((string)$error->getCode() !== '23000' || (int)($error->errorInfo[1] ?? 0) !== 1062
        || stripos($error->getSql(), 'update') !== 0
        || stripos($error->getSql(), 'v2_payment_attempt') === false
        || stripos($error->getSql(), 'callback_hash') === false) {
        throw $error;
    }
    $result = ['accepted' => false, 'newly_paid' => false, 'error' => 'duplicate receipt', 'status' => 500];
}
$result['dispatched'] = Bus::dispatched(OrderHandleJob::class)->count();
echo json_encode($result, JSON_THROW_ON_ERROR);
