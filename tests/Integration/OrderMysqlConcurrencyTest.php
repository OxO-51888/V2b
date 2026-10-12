<?php

namespace Tests\Integration;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderDatabase;

class OrderMysqlConcurrencyTest extends TestCase
{
    private $db;
    private $directory;
    private $children = [];
    private $user;
    private $plan;
    private const GB = 1073741824;

    protected function setUp(): void
    {
        if (!getenv('ORDER_TEST_CONNECTION')) $this->markTestSkipped('Requires a disposable MySQL instance');
        $config = json_decode(getenv('ORDER_TEST_CONNECTION'), true);
        $socket = realpath(dirname($config['unix_socket'] ?? ''));
        if (!$socket || !preg_match('~^/tmp/order-safety-test-[a-f0-9]+$~D', $socket)
            || $config['database'] !== 'order_safety_test' || ($config['host'] ?? '') !== '') {
            throw new \RuntimeException('Refusing a non-isolated database');
        }
        $this->directory = $socket . '/workers-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        [, $this->db] = OrderDatabase::boot($config);
        foreach (['v2_order', 'v2_user', 'v2_plan'] as $table) $this->db->getSchemaBuilder()->dropIfExists($table);
        OrderDatabase::schema($this->db);
        $this->plan = Plan::create(['transfer_enable' => 100]);
        $this->user = User::create([
            'balance' => 1000, 'plan_id' => $this->plan->id,
            'transfer_enable' => 100 * self::GB, 'u' => 20 * self::GB, 'd' => 10 * self::GB
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

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $this->plan->id,
            'trade_no' => bin2hex(random_bytes(12)), 'status' => 1,
            'period' => 'onetime_price', 'type' => 1
        ], $attributes));
    }

    private function start(array $requests): void
    {
        foreach ($requests as $i => [$order, $action]) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/order_worker.php', getenv('ORDER_TEST_BOOTSTRAP'),
                (string)$order->id, $action, $this->directory, (string)$i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 20);
            stream_set_timeout($pipes[2], 20);
            $this->children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($this->directory . '/*.ready')) !== count($requests)) {
            if (microtime(true) > $deadline) $this->fail('Workers did not reach barrier');
            usleep(10000);
        }
        touch($this->directory . '/start');
    }

    private function finish(): array
    {
        $results = [];
        foreach ($this->children as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stdout . $stderr);
            $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->children = [];
        return $results;
    }

    public function testParallelCancellationsRefundOnce(): void
    {
        $order = $this->order(['status' => 0, 'balance_amount' => 500]);
        $this->start(array_fill(0, 4, [$order, 'cancel']));
        $results = $this->finish();
        $this->assertSame(1, count(array_filter($results, fn($result) => $result['result'] === true)));
        $this->assertSame(2, $order->fresh()->status);
        $this->assertSame(1500, $this->user->fresh()->balance);
    }

    public function testParallelPaymentsDispatchOnce(): void
    {
        $order = $this->order(['status' => 0]);
        $this->start(array_fill(0, 4, [$order, 'paid']));
        $results = $this->finish();
        $this->assertSame(1, array_sum(array_column($results, 'dispatched')));
        $this->assertSame(1, $order->fresh()->status);
        $this->assertMatchesRegularExpression('/^receipt-[0-3]$/', $order->fresh()->callback_no);
    }

    public function testPaymentCancellationRaceKeepsStatusAndRefundConsistent(): void
    {
        $order = $this->order(['status' => 0, 'balance_amount' => 500]);
        $this->start([[$order, 'cancel'], [$order, 'paid'], [$order, 'cancel'], [$order, 'paid']]);
        $results = $this->finish();
        $state = $order->fresh()->status;
        $this->assertContains($state, [1, 2]);
        $this->assertSame($state === 2 ? 1500 : 1000, $this->user->fresh()->balance);
        $this->assertSame($state === 2 ? 0 : 1, array_sum(array_column($results, 'dispatched')));
    }

    public function testParallelRechargeOpeningCreditsOnce(): void
    {
        $order = $this->order(['type' => 9, 'total_amount' => 500]);
        $this->start(array_fill(0, 4, [$order, 'open']));
        $this->finish();
        $this->assertSame(1500, $this->user->fresh()->balance);
        $this->assertSame(3, $order->fresh()->status);
    }

    public function testParallelDistinctRechargeOrdersPreserveAllCredits(): void
    {
        $requests = [];
        for ($i = 0; $i < 4; $i++) $requests[] = [$this->order(['type' => 9, 'total_amount' => 500]), 'open'];
        $this->start($requests);
        $this->finish();
        $this->assertSame(3000, $this->user->fresh()->balance);
        $this->assertSame(4, Order::where('status', 3)->count());
    }

    public function testParallelDuplicateOneTimeOrdersAddQuotaOnce(): void
    {
        $order = $this->order();
        $this->start(array_fill(0, 4, [$order, 'open']));
        $this->finish();
        $this->assertSame(170 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testParallelDistinctOneTimeOrdersDoNotLoseQuota(): void
    {
        $requests = [];
        for ($i = 0; $i < 4; $i++) $requests[] = [$this->order(), 'open'];
        $this->start($requests);
        $this->finish();
        $this->assertSame(470 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testParallelMonthlyRenewalDoesNotMultiplyExpiry(): void
    {
        $expiry = strtotime('+2 months +3 days');
        $this->user->update(['expired_at' => $expiry]);
        $order = $this->order(['type' => 2, 'period' => 'month_price']);
        $this->start(array_fill(0, 4, [$order, 'open']));
        $this->finish();
        $this->assertSame(strtotime('+1 month', $expiry), $this->user->fresh()->expired_at);
        $this->assertSame(30 * self::GB, $this->user->fresh()->u + $this->user->fresh()->d);
    }

    public function testKilledWorkerRollsBackAndRetryCompletesOnce(): void
    {
        $order = $this->order(['type' => 9, 'total_amount' => 500]);
        $this->start([[$order, 'kill-open']]);
        $deadline = microtime(true) + 10;
        while (!is_file($this->directory . '/uncommitted')) {
            if (microtime(true) > $deadline) $this->fail('Worker did not write uncommitted credit');
            usleep(10000);
        }
        [$process, $pipes] = $this->children[0];
        $this->assertTrue(proc_terminate($process, 9));
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
        $this->children = [];
        $this->assertSame(1000, $this->user->fresh()->balance);
        $this->assertSame(1, $order->fresh()->status);
        (new OrderService($order))->open();
        (new OrderService($order))->open();
        $this->assertSame(1500, $this->user->fresh()->balance);
        $this->assertSame(3, $order->fresh()->status);
    }
}
