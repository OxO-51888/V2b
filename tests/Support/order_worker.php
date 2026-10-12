<?php

require $argv[1];

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Bus;
use Tests\Support\OrderDatabase;

$config = json_decode(getenv('ORDER_TEST_CONNECTION'), true);
[$app, $db] = OrderDatabase::boot($config);
$order = Order::findOrFail((int)$argv[2]);
$service = new OrderService($order);
$action = $argv[3];
$directory = $argv[4];
$id = $argv[5];
User::retrieved(function () { usleep(100000); });
if ($action === 'kill-open') {
    User::saved(function () use ($directory) {
        touch($directory . '/uncommitted');
        sleep(20);
    });
}
touch($directory . '/' . $id . '.ready');
$deadline = microtime(true) + 15;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Start barrier timed out');
    usleep(10000);
}
if ($action === 'paid') $result = $service->paid('receipt-' . $id);
elseif ($action === 'cancel') $result = $service->cancel();
else $result = $service->open();
echo json_encode(['result' => $result, 'dispatched' => Bus::dispatched(\App\Jobs\OrderHandleJob::class)->count()]);
