<?php

namespace Tests\Integration;

use App\Jobs\StatUserJob;
use App\Models\Order;
use App\Services\AccountingReceipt;
use App\Services\TrafficAccounting;
use App\Services\TrafficBuffer;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountingDatabase;
use Tests\Support\AccountingRedis;

class AccountingIntegrationTest extends TestCase
{
    private $db;
    private $directory;
    private $children = [];

    protected function setUp(): void
    {
        if (!getenv('ACCOUNTING_TEST_CONNECTION')) $this->markTestSkipped('Requires private MySQL and Redis sockets');
        $config = json_decode(getenv('ACCOUNTING_TEST_CONNECTION'), true);
        $socket = realpath(dirname($config['unix_socket'] ?? ''));
        if (!$socket || !preg_match('~^/tmp/order-safety-test-[a-f0-9]+$~D', $socket)
            || $config['database'] !== 'accounting_safety_test' || ($config['host'] ?? '') !== '') {
            throw new \RuntimeException('Refusing a non-isolated database');
        }
        [$app, $this->db] = AccountingDatabase::boot($config);
        foreach (['v2_user', 'v2_plan', 'v2_order', 'v2_stat_user', 'v2_log', 'v2_accounting_receipt'] as $table) {
            $this->db->getSchemaBuilder()->dropIfExists($table);
        }
        AccountingDatabase::schema($this->db);
        AccountingRedis::connect($app);
        Redis::flushdb();
        $this->directory = $socket . '/accounting-workers-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700);
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
        Facade::clearResolvedInstances();
    }

    private function start(array $requests): void
    {
        foreach ($requests as $i => [$action, $payload]) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/accounting_worker.php', $action,
                base64_encode(json_encode($payload)), $this->directory, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 30);
            stream_set_timeout($pipes[2], 30);
            $this->children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($this->directory . '/*.ready')) !== count($requests)) {
            if (microtime(true) > $deadline) $this->fail('Workers did not become ready');
            usleep(10000);
        }
        touch($this->directory . '/start');
    }

    private function finish(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
        }
        $this->children = [];
    }

    private function accounting(): TrafficAccounting
    {
        return new TrafficAccounting(new TrafficBuffer(), new AccountingReceipt());
    }

    public function testLuaBatchSizeReplayAcknowledgementAndUploadOnlyUsers(): void
    {
        for ($id = 1; $id <= 1001; $id++) {
            $this->db->table('v2_user')->insert(['id' => $id]);
            Redis::hincrby(TrafficBuffer::UPLOAD, (string) $id, 10);
            if ($id % 2) Redis::hincrby(TrafficBuffer::DOWNLOAD, (string) $id, 20);
        }
        $buffer = new TrafficBuffer();
        $first = $buffer->claim();
        $this->assertLessThanOrEqual(250, count($first['rows']));
        $this->assertSame($first, $buffer->claim());
        $this->accounting()->drain();
        $this->assertSame(10010, (int) $this->db->table('v2_user')->sum('u'));
        $this->assertSame(10020, (int) $this->db->table('v2_user')->sum('d'));
        $this->assertSame(0, Redis::hlen(TrafficBuffer::UPLOAD));
        $this->assertSame(0, Redis::hlen(TrafficBuffer::DOWNLOAD));
        $this->assertNull(Redis::get(TrafficBuffer::PENDING));
    }

    public function testActualRedisConcurrentReportsAreNotLostDuringDrain(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        $this->start([['produce', ['count' => 1000]], ['produce', ['count' => 1000]]]);
        for ($i = 0; $i < 30; $i++) { $this->accounting()->drain(1); usleep(10000); }
        $this->finish();
        $this->accounting()->drain();
        $user = $this->db->table('v2_user')->first();
        $this->assertSame(2000, (int) $user->u);
        $this->assertSame(4000, (int) $user->d);
    }

    public function testConcurrentReceiptReplayChargesExactlyOnce(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        $batch = ['id' => bin2hex(random_bytes(16)), 'rows' => [['1', '10', '20']]];
        $this->start(array_fill(0, 4, ['apply', $batch]));
        $this->finish();
        $user = $this->db->table('v2_user')->first();
        $this->assertSame(10, (int) $user->u);
        $this->assertSame(20, (int) $user->d);
        $this->assertSame(1, $this->db->table('v2_accounting_receipt')->count());
    }

    public function testConcurrentStatisticsAccumulateWithDuplicateJobReplay(): void
    {
        $a = base64_encode(serialize(new StatUserJob([1 => [10, 20]], ['rate' => 1], 'hysteria')));
        $b = base64_encode(serialize(new StatUserJob([1 => [7, 8]], ['rate' => 1], 'hysteria')));
        $this->start([['stat', ['job' => $a]], ['stat', ['job' => $a]], ['stat', ['job' => $b]], ['stat', ['job' => $b]]]);
        $this->finish();
        $row = $this->db->table('v2_stat_user')->first();
        $this->assertSame(17, (int) $row->u);
        $this->assertSame(28, (int) $row->d);
    }

    public function testPurchaseAndTrafficPreserveRemainingQuotaInEitherOrder(): void
    {
        $gb = 1073741824;
        $this->db->table('v2_plan')->insert(['id' => 1, 'transfer_enable' => 100]);
        $this->db->table('v2_user')->insert(['id' => 1, 'plan_id' => 1, 'u' => 20 * $gb, 'd' => 10 * $gb,
            'transfer_enable' => 100 * $gb]);
        $order = Order::create(['user_id' => 1, 'plan_id' => 1, 'status' => 1, 'type' => 1,
            'period' => 'onetime_price', 'trade_no' => 'isolated-order']);
        $batch = ['id' => bin2hex(random_bytes(16)), 'rows' => [['1', (string) (2 * $gb), (string) (3 * $gb)]]];
        $this->start([['order', ['id' => $order->id]], ['apply', $batch], ['apply', $batch]]);
        $this->finish();
        $user = $this->db->table('v2_user')->first();
        $this->assertSame(165 * $gb, $user->transfer_enable - $user->u - $user->d);
        $this->assertSame(3, (int) $this->db->table('v2_order')->value('status'));
    }

    public function testOrderStatusIndexAvoidsHistoricCompletedOrders(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/2026_10_12_120100_add_order_status_scan_index.php';
        (new \AddOrderStatusScanIndex())->up();
        (new \AddOrderStatusScanIndex())->up();
        for ($start = 0; $start < 10000; $start += 250) {
            $rows = [];
            for ($i = 0; $i < 250; $i++) $rows[] = ['user_id' => 1, 'status' => 3, 'trade_no' => 'closed-' . ($start + $i)];
            $this->db->table('v2_order')->insert($rows);
        }
        $this->db->table('v2_order')->insert(['user_id' => 1, 'status' => 1, 'trade_no' => 'paid-order']);
        $explain = $this->db->select('EXPLAIN SELECT id,trade_no FROM v2_order WHERE status=1 AND id>0 ORDER BY id LIMIT 200')[0];
        $this->assertSame('idx_status_id', $explain->key);
        $this->assertLessThan(50, (int) $explain->rows);
    }
}
