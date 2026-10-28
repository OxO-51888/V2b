<?php

namespace Tests\Integration;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentAttemptDatabase;

/** Real row-lock tests; runnable only against the explicitly isolated socket. */
class PaymentAttemptMysqlConcurrencyTest extends TestCase
{
    private $db;
    private $directory;
    private $bootstrap;
    private $children = [];
    private $user;
    private $alipay;
    private $wechat;

    protected function setUp(): void
    {
        $connection = getenv('PAYMENT_ATTEMPT_TEST_CONNECTION') ?: getenv('PAYMENT_TEST_CONNECTION');
        if (!$connection) {
            $this->markTestSkipped('Requires the disposable payment-attempt MySQL instance');
        }
        $config = json_decode($connection, true, 512, JSON_THROW_ON_ERROR);
        $socket = is_array($config) ? realpath($config['unix_socket'] ?? '') : false;
        $root = $socket ? dirname($socket) : false;
        if (!$root || !preg_match('~^/tmp/(?:payment-attempt|order-safety)-test-[a-f0-9]+$~D', $root)
            || ($config['driver'] ?? '') !== 'mysql'
            || ($config['database'] ?? '') !== 'payment_attempt_test'
            || ($config['host'] ?? null) !== ''
            || array_intersect(['url', 'read', 'write'], array_keys($config))) {
            throw new \RuntimeException('Refusing a non-isolated database');
        }
        $this->bootstrap = realpath(getenv('PAYMENT_ATTEMPT_TEST_BOOTSTRAP') ?: getenv('ORDER_TEST_BOOTSTRAP') ?: '');
        if (!$this->bootstrap || !is_file($this->bootstrap) || !is_readable($this->bootstrap)) {
            throw new \RuntimeException('Requires a readable isolated test bootstrap');
        }
        $config['unix_socket'] = $socket;
        $this->directory = $root . '/workers-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700)) throw new \RuntimeException('Cannot create worker barrier');
        [, $this->db] = PaymentAttemptDatabase::boot($config);
        foreach (['v2_payment_attempt', 'v2_payment', 'v2_order', 'v2_user', 'v2_plan'] as $table) {
            $this->db->getSchemaBuilder()->dropIfExists($table);
        }
        PaymentAttemptDatabase::schema($this->db);
        $this->user = User::create(['balance' => 1000]);
        $this->alipay = Payment::forceCreate([
            'id' => 4, 'payment' => 'SGEpay', 'enable' => 1,
            'handling_fee_fixed' => 0, 'handling_fee_percent' => 10,
        ]);
        $this->wechat = Payment::forceCreate([
            'id' => 2, 'payment' => 'SGEpay', 'enable' => 1,
            'handling_fee_fixed' => 0, 'handling_fee_percent' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
        }
        if ($this->directory) {
            foreach (glob($this->directory . '/*') as $file) unlink($file);
            rmdir($this->directory);
        }
        if ($this->db) $this->db->disconnect();
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function order(): Order
    {
        return Order::create([
            'user_id' => $this->user->id, 'trade_no' => bin2hex(random_bytes(12)),
            'period' => 'deposit', 'status' => 0, 'type' => 9, 'total_amount' => 2000,
        ]);
    }

    private function prepare(Order $order, Payment $payment): void
    {
        (new PaymentAttemptService())->prepareCheckout($order->trade_no, $this->user->id, $payment);
    }

    private function paymentCallback(Order $order, int $paymentId, string $receipt, string $amount): array
    {
        return [
            'action' => 'settle', 'order_id' => $order->id,
            'payment_id' => $paymentId, 'receipt' => $receipt,
            'method' => 'SGEpay', 'amount' => $amount,
        ];
    }

    private function concurrent(array $requests): array
    {
        foreach ($requests as $i => $request) {
            $process = proc_open([
                PHP_BINARY, __DIR__ . '/../Support/payment_attempt_worker.php',
                $this->bootstrap, $this->directory, (string)$i,
                base64_encode(json_encode($request, JSON_THROW_ON_ERROR)),
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 35);
            stream_set_timeout($pipes[2], 35);
            $this->children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($this->directory . '/*.ready')) !== count($requests)) {
            if (microtime(true) > $deadline) $this->fail('Payment workers did not reach barrier');
            usleep(10000);
        }
        touch($this->directory . '/start');
        $results = [];
        foreach ($this->children as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stdout . $stderr);
            $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->children = [];
        return $results;
    }

    public function testDifferentChannelsRacingForOneOrderHaveOneWinner(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->prepare($order, $this->wechat);
        $results = $this->concurrent([
            $this->paymentCallback($order, 4, 'alipay-race', '22.00'),
            $this->paymentCallback($order, 2, 'wechat-race', '21.00'),
            $this->paymentCallback($order, 4, 'alipay-race', '22.00'),
            $this->paymentCallback($order, 2, 'wechat-race', '21.00'),
        ]);
        $this->assertSame(1, array_sum(array_column($results, 'newly_paid')));
        $this->assertSame(2, count(array_filter($results, fn($row) => $row['accepted'])));
        $paid = $order->fresh();
        $this->assertSame(1, (int)$paid->status);
        $this->assertContains((int)$paid->payment_id, [2, 4]);
        $winningReceipt = (int)$paid->payment_id === 4 ? 'alipay-race' : 'wechat-race';
        $winningFee = (int)$paid->payment_id === 4 ? 200 : 100;
        $this->assertSame($winningReceipt, $paid->callback_no);
        $this->assertSame($winningFee, (int)$paid->handling_amount);
        $this->assertNotNull($paid->paid_at);
        $attempts = $this->db->table('v2_payment_attempt');
        $this->assertSame(2, $attempts->count());
        $claimed = $this->db->table('v2_payment_attempt')->whereNotNull('callback_hash')->get();
        $this->assertCount(1, $claimed);
        $this->assertSame((int)$paid->payment_id, (int)$claimed[0]->payment_id);
        $this->assertSame(hash('sha256', "SGEpay\0" . $winningReceipt), $claimed[0]->callback_hash);
        $this->assertSame(1000, (int)$this->user->fresh()->balance);
    }

    public function testOneGatewayTransactionCannotPayTwoOrdersConcurrently(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->prepare($first, $this->alipay);
        $this->prepare($second, $this->alipay);
        $results = $this->concurrent([
            $this->paymentCallback($first, 4, 'shared-gateway-transaction', '22.00'),
            $this->paymentCallback($second, 4, 'shared-gateway-transaction', '22.00'),
        ]);
        $this->assertSame(1, array_sum(array_column($results, 'newly_paid')));
        $this->assertSame(1, count(array_filter($results, fn($row) => $row['accepted'])));
        $this->assertSame(1, Order::where('status', 1)->count());
        $this->assertSame(1, Order::where('status', 0)->count());
        $this->assertSame(1, $this->db->table('v2_payment_attempt')->whereNotNull('callback_hash')->count());
        $winner = Order::where('status', 1)->firstOrFail();
        $loser = Order::where('status', 0)->firstOrFail();
        $this->assertSame('shared-gateway-transaction', $winner->callback_no);
        $this->assertNull($loser->callback_no);
        $this->assertNull($loser->paid_at);
        $this->assertSame(0, $this->db->table('v2_payment_attempt')->where('order_id', $loser->id)->whereNotNull('callback_hash')->count());
        $this->assertSame(1, array_sum(array_column($results, 'dispatched')));
        (new OrderService($winner))->open();
        (new OrderService($winner))->open();
        $this->assertSame(3000, (int)$this->user->fresh()->balance);
        $this->assertSame(3, (int)$winner->fresh()->status);
        $this->assertSame(0, (int)$loser->fresh()->status);
    }

    public function testRepeatedCallbacksAndConcurrentOpeningCreditOnlyOnce(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $paid = (new PaymentAttemptService())->settle(
            $order->trade_no, 'duplicate-notification', 4, 'SGEpay', ['paid_amount' => '22.00']
        );
        $this->assertTrue($paid['newly_paid']);
        $requests = array_fill(0, 4, $this->paymentCallback($order, 4, 'duplicate-notification', '22.00'));
        $requests[] = ['action' => 'open', 'order_id' => $order->id];
        $requests[] = ['action' => 'open', 'order_id' => $order->id];
        $results = $this->concurrent($requests);
        $this->assertSame(0, array_sum(array_column($results, 'newly_paid')));
        $this->assertSame(6, count(array_filter($results, fn($row) => $row['accepted'])));
        $this->assertSame(3000, (int)$this->user->fresh()->balance);
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame('duplicate-notification', $order->fresh()->callback_no);
        $this->assertSame(1, $this->db->table('v2_payment_attempt')->whereNotNull('callback_hash')->count());
    }
}
