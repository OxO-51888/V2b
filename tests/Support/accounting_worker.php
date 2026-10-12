<?php

require getenv('ACCOUNTING_TEST_BOOTSTRAP');

use App\Jobs\StatUserJob;
use App\Models\Order;
use App\Services\AccountingReceipt;
use App\Services\OrderService;
use App\Services\TrafficAccounting;
use App\Services\TrafficBuffer;
use Tests\Support\AccountingDatabase;
use Tests\Support\AccountingRedis;

$config = json_decode(getenv('ACCOUNTING_TEST_CONNECTION'), true);
if (($config['database'] ?? '') !== 'accounting_safety_test'
    || !preg_match('~^/tmp/order-safety-test-[a-f0-9]+/test.sock$~D', $config['unix_socket'] ?? '')) {
    throw new RuntimeException('Refusing a non-isolated database');
}
[$app, $db] = AccountingDatabase::boot($config);
AccountingRedis::connect($app);
$action = $argv[1];
$payload = json_decode(base64_decode($argv[2]), true);
$directory = $argv[3];
$id = $argv[4];
touch($directory . '/' . $id . '.ready');
$deadline = microtime(true) + 15;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Start barrier timed out');
    usleep(10000);
}
if ($action === 'stat') {
    unserialize(base64_decode($payload['job']), ['allowed_classes' => [StatUserJob::class]])->handle();
} elseif ($action === 'order') {
    (new OrderService(Order::findOrFail($payload['id'])))->open();
} elseif ($action === 'produce') {
    for ($i = 0; $i < $payload['count']; $i++) {
        Illuminate\Support\Facades\Redis::hincrby(TrafficBuffer::UPLOAD, '1', 1);
        Illuminate\Support\Facades\Redis::hincrby(TrafficBuffer::DOWNLOAD, '1', 2);
    }
} else {
    (new TrafficAccounting(new TrafficBuffer(), new AccountingReceipt()))->apply($payload);
}
echo json_encode(['ok' => true]);
