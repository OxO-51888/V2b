<?php

namespace Tests\Unit;

use App\Console\Commands\CheckOrder;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderDatabase;

class OrderConcurrencyTest extends TestCase
{
    private $app;
    private $db;
    private $user;
    private $plan;
    private const GB = 1073741824;

    protected function setUp(): void
    {
        [$this->app, $this->db] = OrderDatabase::boot();
        OrderDatabase::schema($this->db);
        $this->plan = Plan::create(['transfer_enable' => 100, 'speed_limit' => 50]);
        $this->user = User::create([
            'balance' => 1000, 'plan_id' => $this->plan->id,
            'transfer_enable' => 100 * self::GB, 'u' => 20 * self::GB, 'd' => 10 * self::GB
        ]);
    }

    protected function tearDown(): void
    {
        Order::flushEventListeners();
        User::flushEventListeners();
        $this->db->disconnect();
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
            'trade_no' => bin2hex(random_bytes(12)), 'status' => 0,
            'period' => 'onetime_price', 'type' => 1,
        ], $attributes));
    }

    private function service(Order $order): OrderService
    {
        return new OrderService(Order::findOrFail($order->id));
    }

    private function assertRolledBack(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected transactional failure');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(500, $error->getStatusCode());
        }
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function testTwoStaleCancellationsRefundOnlyOnce(): void
    {
        $order = $this->order(['balance_amount' => 500]);
        $first = $this->service($order);
        $second = $this->service($order);
        $this->assertTrue($first->cancel());
        $this->assertFalse($second->cancel());
        $this->assertSame(2, $order->fresh()->status);
        $this->assertSame(1500, $this->user->fresh()->balance);
    }

    public function testPaymentWinningRaceCannotBeCancelledOrRefunded(): void
    {
        $order = $this->order(['balance_amount' => 500]);
        $cancel = $this->service($order);
        $this->assertTrue($this->service($order)->paid('payment-one'));
        $this->assertFalse($cancel->cancel());
        $this->assertSame(1, $order->fresh()->status);
        $this->assertSame(1000, $this->user->fresh()->balance);
    }

    public function testCancelledOrderIsNeverReopenedByStalePayment(): void
    {
        $order = $this->order(['balance_amount' => 500]);
        $payment = $this->service($order);
        $this->service($order)->cancel();
        $this->assertTrue($payment->paid('late-payment'));
        $this->assertSame(2, $order->fresh()->status);
        $this->assertNull($order->fresh()->callback_no);
        Bus::assertNothingDispatched();
    }

    public function testDuplicatePaymentPreservesFirstReceiptAndDispatchesOnce(): void
    {
        $order = $this->order();
        $first = $this->service($order);
        $second = $this->service($order);
        $this->assertTrue($first->paid('first'));
        $this->assertTrue($second->paid('second'));
        $this->assertSame('first', $order->fresh()->callback_no);
        $this->assertNotNull($order->fresh()->paid_at);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
    }

    public function testStalePaidCallbackCannotDowngradeCompletedOrder(): void
    {
        $order = $this->order();
        $late = $this->service($order);
        $this->service($order)->paid('first');
        $this->service($order)->open();
        $late->paid('second');
        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame('first', $order->fresh()->callback_no);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveStatuses')]
    public function testOpenDoesNothingForNonPaidOrders(int $status): void
    {
        $order = $this->order(['status' => $status, 'type' => 9, 'total_amount' => 500]);
        $this->service($order)->open();
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame(1000, $this->user->fresh()->balance);
    }

    public static function inactiveStatuses(): array { return [[0], [2], [3], [4]]; }

    public function testDuplicateRechargeJobCreditsBonusOnlyOnce(): void
    {
        $this->app['config']->set('v2board.deposit_bounus', ['5:1', '10:3']);
        $order = $this->order(['status' => 1, 'period' => 'deposit', 'type' => 9, 'total_amount' => 1000]);
        $first = $this->service($order);
        $second = $this->service($order);
        $first->open();
        $second->open();
        $this->assertSame(2300, $this->user->fresh()->balance);
        $this->assertSame(3, $order->fresh()->status);
    }

    public function testDifferentRechargeOrdersDoNotOverwriteBalance(): void
    {
        $first = $this->service($this->order(['status' => 1, 'type' => 9, 'total_amount' => 500]));
        $second = $this->service($this->order(['status' => 1, 'type' => 9, 'total_amount' => 700]));
        $first->open();
        $second->open();
        $this->assertSame(2200, $this->user->fresh()->balance);
    }

    public function testOneTimePurchaseKeepsOriginalRemainingPlusNewQuotaAlgorithm(): void
    {
        $order = $this->order(['status' => 1]);
        $first = $this->service($order);
        $second = $this->service($order);
        $first->open();
        $second->open();
        $user = $this->user->fresh();
        $this->assertSame(170 * self::GB, $user->transfer_enable);
        $this->assertSame(0, $user->u + $user->d);
        $this->assertNull($user->expired_at);
        $this->assertSame(50, $user->speed_limit);
    }

    public function testOverusedOneTimePurchaseDoesNotCarryNegativeQuota(): void
    {
        $this->user->update(['u' => 110 * self::GB]);
        $this->service($this->order(['status' => 1]))->open();
        $this->assertSame(100 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testTwoDistinctOneTimeOrdersBothAddTheirQuota(): void
    {
        $first = $this->service($this->order(['status' => 1]));
        $second = $this->service($this->order(['status' => 1]));
        $first->open();
        $second->open();
        $this->assertSame(270 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testMonthlyRenewalKeepsUsageUnlessConfiguredToReset(): void
    {
        $expiry = strtotime('+2 months +3 days');
        $this->user->update(['expired_at' => $expiry]);
        $order = $this->order(['status' => 1, 'type' => 2, 'period' => 'month_price']);
        $first = $this->service($order);
        $second = $this->service($order);
        $first->open();
        $second->open();
        $user = $this->user->fresh();
        $this->assertSame(strtotime('+1 month', $expiry), $user->expired_at);
        $this->assertSame(30 * self::GB, $user->u + $user->d);
        $this->assertSame(100 * self::GB, $user->transfer_enable);
    }

    public function testConfiguredRenewalResetAndPaidResetRemainUnchanged(): void
    {
        $this->user->update(['expired_at' => strtotime('+2 months +3 days')]);
        $this->app['config']->set('v2board.renew_order_event_id', 1);
        $this->service($this->order(['status' => 1, 'type' => 2, 'period' => 'month_price']))->open();
        $this->assertSame(0, $this->user->fresh()->u + $this->user->fresh()->d);
        $expiry = $this->user->fresh()->expired_at;
        $this->user->fresh()->update(['u' => 15, 'd' => 25]);
        $this->service($this->order(['status' => 1, 'type' => 4, 'period' => 'reset_price']))->open();
        $this->assertSame(0, $this->user->fresh()->u + $this->user->fresh()->d);
        $this->assertSame($expiry, $this->user->fresh()->expired_at);
    }

    public function testPlanConversionAndRefundAreAppliedOnce(): void
    {
        $old = $this->order(['status' => 3]);
        $order = $this->order(['status' => 1, 'type' => 3, 'refund_amount' => 200, 'surplus_order_ids' => [$old->id]]);
        $first = $this->service($order);
        $second = $this->service($order);
        $first->open();
        $second->open();
        $this->assertSame(4, $old->fresh()->status);
        $this->assertSame(1200, $this->user->fresh()->balance);
        $this->assertSame(100 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testFailedCompletionRollsBackUserAndSurplusOrdersAndCanRetry(): void
    {
        $old = $this->order(['status' => 3]);
        $order = $this->order(['status' => 1, 'refund_amount' => 200, 'surplus_order_ids' => [$old->id]]);
        Order::saving(function ($item) { if ($item->status === 3) return false; });
        $service = $this->service($order);
        $this->assertRolledBack(fn() => $service->open());
        $this->assertSame(1, $order->fresh()->status);
        $this->assertSame(3, $old->fresh()->status);
        $this->assertSame(1000, $this->user->fresh()->balance);
        $this->assertSame(30 * self::GB, $this->user->fresh()->u + $this->user->fresh()->d);
        Order::flushEventListeners();
        $service->open();
        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(1200, $this->user->fresh()->balance);
    }

    public function testFailedRechargeRollsBackCredit(): void
    {
        $order = $this->order(['status' => 1, 'type' => 9, 'total_amount' => 500]);
        Order::saving(fn($item) => $item->status !== 3);
        $this->assertRolledBack(fn() => $this->service($order)->open());
        $this->assertSame(1000, $this->user->fresh()->balance);
        $this->assertSame(1, $order->fresh()->status);
    }

    public function testFailedRefundRollsBackCancellation(): void
    {
        $order = $this->order(['balance_amount' => 500]);
        User::saving(fn() => false);
        $this->assertRolledBack(fn() => $this->service($order)->cancel());
        $this->assertSame(0, $order->fresh()->status);
        $this->assertSame(1000, $this->user->fresh()->balance);
        User::flushEventListeners();
        $this->assertTrue($this->service($order)->cancel());
        $this->assertSame(1500, $this->user->fresh()->balance);
    }

    public function testDeadlockRetryReloadsRolledBackUserAndOrder(): void
    {
        $order = $this->order(['status' => 1, 'type' => 9, 'total_amount' => 500]);
        $attempts = 0;
        User::saved(function () use (&$attempts) {
            if (++$attempts === 1) throw new \PDOException('Deadlock found when trying to get lock', 40001);
        });
        $this->service($order)->open();
        $this->assertSame(2, $attempts);
        $this->assertSame(1500, $this->user->fresh()->balance);
        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function testMissingUserCannotConsumePaymentOrRefund(): void
    {
        $paid = $this->order(['status' => 1, 'user_id' => 9999]);
        $unpaid = $this->order(['balance_amount' => 500, 'user_id' => 9999]);
        $this->assertRolledBack(fn() => $this->service($paid)->open());
        $this->assertRolledBack(fn() => $this->service($unpaid)->cancel());
        $this->assertSame(1, $paid->fresh()->status);
        $this->assertSame(0, $unpaid->fresh()->status);
    }

    public function testMissingPlanDoesNotConsumePaidOrder(): void
    {
        $order = $this->order(['status' => 1, 'plan_id' => 9999]);
        $this->assertRolledBack(fn() => $this->service($order)->open());
        $this->assertSame(1, $order->fresh()->status);
        $this->assertSame(100 * self::GB, $this->user->fresh()->transfer_enable);
    }

    public function testQueueFailureLeavesPaymentRecordedForScheduledRecovery(): void
    {
        $order = $this->order();
        $originalBus = $this->app[Dispatcher::class];
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $bus);
        $this->assertFalse($this->service($order)->paid('receipt'));
        $this->assertSame(1, $order->fresh()->status);
        $this->assertSame('receipt', $order->fresh()->callback_no);
        $this->app->instance(Dispatcher::class, $originalBus);
        $this->scan();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(3, $order->fresh()->status);
    }

    private function scan(): void
    {
        (new class extends CheckOrder {
            public function scan() { $this->runTask(); }
        })->scan();
    }

    public function testScanPrioritizesPaidAndSkipsRecentUnpaidAndFinishedOrders(): void
    {
        $expired = $this->order(['created_at' => time() - 7201]);
        $paid = $this->order(['status' => 1]);
        $this->order(['created_at' => time() - 7100]);
        foreach ([2, 3, 4] as $status) $this->order(['status' => $status]);
        $limit = ini_get('memory_limit');
        $this->scan();
        $jobs = Bus::dispatched(OrderHandleJob::class);
        $property = new \ReflectionProperty(OrderHandleJob::class, 'tradeNo');
        $this->assertSame([$paid->trade_no, $expired->trade_no], $jobs->map(fn($job) => $property->getValue($job))->values()->all());
        $this->assertSame($limit, ini_get('memory_limit'));
    }

    public function testScanChunksWithoutSkippingRowsChangedDuringDispatch(): void
    {
        for ($i = 0; $i < 405; $i++) $this->order(['status' => 1]);
        $bus = $this->createMock(Dispatcher::class);
        $seen = [];
        $bus->expects($this->exactly(405))->method('dispatch')->willReturnCallback(function ($job) use (&$seen) {
            $property = new \ReflectionProperty($job, 'tradeNo');
            $trade = $property->getValue($job);
            $seen[] = $trade;
            Order::where('trade_no', $trade)->update(['status' => 3]);
        });
        $this->app->instance(Dispatcher::class, $bus);
        $this->db->enableQueryLog();
        $this->scan();
        $this->assertCount(405, array_unique($seen));
        $this->assertSame(0, Order::where('status', 1)->count());
        $chunkQueries = array_filter($this->db->getQueryLog(), fn($query) => strpos($query['query'], 'limit 200') !== false);
        $this->assertCount(4, $chunkQueries);
    }
}
