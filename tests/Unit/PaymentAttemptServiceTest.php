<?php

namespace Tests\Unit;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentAttemptDatabase;

class PaymentAttemptServiceTest extends TestCase
{
    private $app;
    private $db;
    private $service;
    private $user;
    private $plan;
    private $alipay;
    private $wechat;
    private const GB = 1073741824;

    protected function setUp(): void
    {
        [$this->app, $this->db] = PaymentAttemptDatabase::boot();
        PaymentAttemptDatabase::schema($this->db);
        $this->service = new PaymentAttemptService();
        $this->plan = Plan::create(['transfer_enable' => 100, 'speed_limit' => 50]);
        $this->user = User::create(['balance' => 700, 'transfer_enable' => 0, 'expired_at' => 0]);
        $this->alipay = Payment::forceCreate(['id' => 4, 'name' => 'Alipay', 'payment' => 'SGEpay', 'enable' => 1, 'handling_fee_percent' => 10]);
        $this->wechat = Payment::forceCreate(['id' => 2, 'name' => 'WeChat', 'payment' => 'SGEpay', 'enable' => 1, 'handling_fee_percent' => 10]);
    }

    protected function tearDown(): void
    {
        foreach ([Order::class, User::class, Payment::class] as $model) $model::flushEventListeners();
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
            'trade_no' => 'fixture-' . bin2hex(random_bytes(10)), 'status' => 0,
            'total_amount' => 1800, 'period' => 'onetime_price', 'type' => 1,
        ], $attributes));
    }

    private function prepare(Order $order, Payment $payment): Order
    {
        return $this->service->prepareCheckout($order->trade_no, $order->user_id, $payment);
    }

    private function settle(Order $order, Payment $payment, string $receipt = 'fixture-receipt', array $verified = ['paid_amount' => '19.8']): array
    {
        return $this->service->settle($order->trade_no, $receipt, (int)$payment->id, $payment->payment, $verified);
    }

    private function reject(callable $callback, bool $uniqueCollision = false): void
    {
        try {
            $callback();
            $this->fail('Expected a rejected payment');
        } catch (HttpException $error) {
            $this->assertGreaterThanOrEqual(400, $error->getStatusCode());
        } catch (QueryException $error) {
            if (!$uniqueCollision) throw $error;
            $this->assertContains((string)($error->errorInfo[0] ?? $error->getCode()), ['23000', '23505', '19']);
        }
        $this->assertSame(0, $this->db->transactionLevel(), 'rejection must close transaction');
    }

    private function attempts(Order $order)
    {
        return $this->db->table('v2_payment_attempt')->where('order_id', $order->id);
    }

    public function testEarlierAlipayAttemptStillSettlesAfterWechatCheckout(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->prepare($order, $this->wechat);
        $this->assertSame((int)$this->wechat->id, (int)$order->fresh()->payment_id);
        $this->assertSame(2, $this->attempts($order)->count());
        $result = $this->settle($order, $this->alipay);
        $this->assertTrue($result['newly_paid']);
        $this->assertSame(1, (int)$result['order']->status);
        $this->assertSame((int)$this->alipay->id, (int)$order->fresh()->payment_id);
        $this->assertSame(180, (int)$order->fresh()->handling_amount);
        $this->assertSame('fixture-receipt', $order->fresh()->callback_no);
        $this->assertSame(hash('sha256', "SGEpay\0fixture-receipt"), $this->attempts($order)->where('payment_id', $this->alipay->id)->value('callback_hash'));
        $this->assertNull($this->attempts($order)->where('payment_id', $this->wechat->id)->value('callback_hash'));
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
    }

    public function testRepeatedCheckoutKeepsOneIdenticalSnapshot(): void
    {
        $order = $this->order();
        for ($i = 0; $i < 8; $i++) $this->prepare($order, $this->alipay);
        $this->assertSame(1, $this->attempts($order)->count());
        $this->assertSame(1980, (int)$this->attempts($order)->value('expected_amount'));
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, (int)$order->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function testDuplicateReceiptCannotGrantPlanTwice(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $first = $this->settle($order, $this->alipay);
        $paidAt = $order->fresh()->paid_at;
        $duplicate = $this->settle($order, $this->alipay);
        $this->assertTrue($first['newly_paid']);
        $this->assertFalse($duplicate['newly_paid']);
        $this->assertSame($paidAt, $order->fresh()->paid_at);
        (new OrderService($order))->open();
        (new OrderService($order))->open();
        $queued = Bus::dispatched(OrderHandleJob::class)->count();
        $completedDuplicate = $this->settle($order, $this->alipay);
        $this->assertFalse($completedDuplicate['newly_paid']);
        $this->assertSame($queued, Bus::dispatched(OrderHandleJob::class)->count());
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(100 * self::GB, (int)$this->user->fresh()->transfer_enable);
        $this->assertSame(700, (int)$this->user->fresh()->balance);
    }

    public function testSecondSuccessfulChannelCannotReplaceFirstWinner(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->prepare($order, $this->wechat);
        $this->settle($order, $this->alipay, 'winner');
        $before = $order->fresh()->getAttributes();
        $this->reject(fn() => $this->settle($order, $this->wechat, 'second-payment'));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertNull($this->attempts($order)->where('payment_id', $this->wechat->id)->value('callback_hash'));
    }

    public function testWrongAmountCannotRecordAReceipt(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        foreach (['0.01', '18.00', '19.79', '19.81', '20.00'] as $amount) {
            $this->reject(fn() => $this->settle($order, $this->alipay, 'wrong-amount', ['paid_amount' => $amount]));
            $this->assertSame(0, (int)$order->fresh()->status);
            $this->assertNull($this->attempts($order)->value('callback_hash'));
        }
        Bus::assertNothingDispatched();
    }

    public function testInvalidAmountsAreRejectedWithoutWrites(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        foreach ([null, '', false, [], '-19.8', '+19.8', ' 19.8', '19.8 ', '19.800', '1.98e1', 'NaN', 'Infinity', '19,80'] as $amount) {
            $this->reject(fn() => $this->settle($order, $this->alipay, 'invalid-amount', ['paid_amount' => $amount]));
            $this->assertSame(0, (int)$order->fresh()->status);
            $this->assertNull($this->attempts($order)->value('callback_hash'));
        }
        Bus::assertNothingDispatched();
    }

    public function testEquivalentMoneyFormatsAreAccepted(): void
    {
        foreach (['19.8', '19.80', 19.8] as $i => $amount) {
            $order = $this->order();
            $this->prepare($order, $this->alipay);
            $this->assertTrue($this->settle($order, $this->alipay, 'format-' . $i, ['paid_amount' => $amount])['newly_paid']);
        }
        $this->alipay->handling_fee_percent = 0;
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->assertTrue($this->settle($order, $this->alipay, 'whole-yuan', ['paid_amount' => 18])['newly_paid']);
    }

    public function testUninitiatedChannelIsNotAcceptedEvenWithCorrectAmount(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->reject(fn() => $this->settle($order, $this->wechat));
        $this->assertSame(0, (int)$order->fresh()->status);
        $this->assertSame(1, $this->attempts($order)->count());
        $this->assertNull($this->attempts($order)->value('callback_hash'));
    }

    public function testCancelledOrderRemainsCancelledWithRefundUnchanged(): void
    {
        $order = $this->order(['balance_amount' => 200]);
        $this->prepare($order, $this->alipay);
        (new OrderService($order))->cancel();
        $balance = $this->user->fresh()->balance;
        $this->reject(fn() => $this->settle($order, $this->alipay));
        $this->assertSame(2, (int)$order->fresh()->status);
        $this->assertSame($balance, $this->user->fresh()->balance);
        $this->assertNull($this->attempts($order)->value('callback_hash'));
        Bus::assertNothingDispatched();
    }

    public function testFulfilledOrDiscountedLegacyOrderCannotReceiveNewPayment(): void
    {
        foreach ([1, 3, 4] as $status) {
            $order = $this->order(['status' => $status, 'payment_id' => $this->alipay->id, 'handling_amount' => 180, 'callback_no' => 'original-receipt']);
            $this->reject(fn() => $this->settle($order, $this->alipay, 'different-receipt'));
            $this->assertSame($status, (int)$order->fresh()->status);
            $this->assertSame('original-receipt', $order->fresh()->callback_no);
            $this->assertSame(0, $this->attempts($order)->count());
        }
        Bus::assertNothingDispatched();
    }

    public function testDiscountedOrderAcceptsOnlyItsOriginalReceiptWithoutQueue(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->settle($order, $this->alipay);
        $order->refresh();
        $order->status = 4;
        $order->save();
        $queued = Bus::dispatched(OrderHandleJob::class)->count();
        $this->assertFalse($this->settle($order, $this->alipay)['newly_paid']);
        $this->assertSame(4, (int)$order->fresh()->status);
        $this->assertSame($queued, Bus::dispatched(OrderHandleJob::class)->count());
    }

    public function testLegacyCurrentChannelCanSettleAndBackfillsItsSnapshot(): void
    {
        $order = $this->order(['payment_id' => $this->alipay->id, 'handling_amount' => 180]);
        $this->assertSame(0, $this->attempts($order)->count());
        $this->assertTrue($this->settle($order, $this->alipay)['newly_paid']);
        $this->assertSame(1, $this->attempts($order)->count());
        $this->assertSame(1980, (int)$this->attempts($order)->value('expected_amount'));
    }

    public function testLegacyWrongChannelCannotBeBackfilledFromNotification(): void
    {
        $order = $this->order(['payment_id' => $this->wechat->id, 'handling_amount' => 180]);
        $this->reject(fn() => $this->settle($order, $this->alipay));
        $this->assertSame(0, $this->attempts($order)->count());
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    public function testLegacyLinkIsRememberedBeforePreparingAnotherChannel(): void
    {
        $order = $this->order(['payment_id' => $this->alipay->id, 'handling_amount' => 180]);
        $this->wechat->handling_fee_percent = 5;
        $this->prepare($order, $this->wechat);
        $this->assertSame(1980, (int)$this->attempts($order)->where('payment_id', $this->alipay->id)->value('expected_amount'));
        $this->assertSame(1890, (int)$this->attempts($order)->where('payment_id', $this->wechat->id)->value('expected_amount'));
        $this->assertTrue($this->settle($order, $this->alipay)['newly_paid']);
        $this->assertSame(180, (int)$order->fresh()->handling_amount);
    }

    public function testExistingLedgerDisablesLegacyFallbackForUnrecordedBinding(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $order->refresh();
        $order->payment_id = $this->wechat->id;
        $order->save();
        $this->reject(fn() => $this->settle($order, $this->wechat));
        $this->assertSame(1, $this->attempts($order)->count());
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    public function testOldFeeSnapshotIsHonoredAfterNewFeeCheckout(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->alipay->handling_fee_percent = 20;
        $this->prepare($order, $this->alipay);
        $this->assertSame(2, $this->attempts($order)->count());
        $this->assertSame(360, (int)$order->fresh()->handling_amount);
        $this->assertTrue($this->settle($order, $this->alipay)['newly_paid']);
        $this->assertSame(180, (int)$order->fresh()->handling_amount);
        $this->assertNull($this->attempts($order)->where('handling_amount', 360)->value('callback_hash'));
    }

    public function testNewFeeSnapshotCanAlsoWin(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->alipay->handling_fee_percent = 20;
        $this->prepare($order, $this->alipay);
        $this->assertTrue($this->settle($order, $this->alipay, 'new-fee', ['paid_amount' => '21.60'])['newly_paid']);
        $this->assertSame(360, (int)$order->fresh()->handling_amount);
    }

    public function testEpayAdaptersRequireTheirAmountField(): void
    {
        foreach (['EPay', 'SGEpay'] as $method) {
            $order = $this->order();
            $this->alipay->payment = $method;
            $this->prepare($order, $this->alipay);
            $this->reject(fn() => $this->settle($order, $this->alipay, 'missing-amount', []));
            $this->assertSame(0, (int)$order->fresh()->status);
        }
    }

    public function testAdapterWithoutAmountAcceptsOnlyUnambiguousSnapshot(): void
    {
        $this->alipay->payment = 'StripeAlipay';
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->assertTrue($this->settle($order, $this->alipay, 'no-amount-single', [])['newly_paid']);

        $ambiguous = $this->order();
        $this->prepare($ambiguous, $this->alipay);
        $this->alipay->handling_fee_percent = 20;
        $this->prepare($ambiguous, $this->alipay);
        $this->reject(fn() => $this->settle($ambiguous, $this->alipay, 'no-amount-ambiguous', []));
        $this->assertSame(0, (int)$ambiguous->fresh()->status);
        $this->assertSame(0, $this->attempts($ambiguous)->whereNotNull('callback_hash')->count());
    }

    public function testProviderReceiptCannotSettleTwoOrdersAcrossChannels(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->prepare($first, $this->alipay);
        $this->prepare($second, $this->wechat);
        $this->settle($first, $this->alipay, 'same-provider-transaction');
        $this->reject(fn() => $this->settle($second, $this->wechat, 'same-provider-transaction'), true);
        $this->assertSame(1, (int)$first->fresh()->status);
        $this->assertSame(0, (int)$second->fresh()->status);
        $this->assertNull($second->fresh()->paid_at);
        $this->assertNull($this->attempts($second)->value('callback_hash'));
        $this->assertSame(1, $this->db->table('v2_payment_attempt')->whereNotNull('callback_hash')->count());
    }

    public function testDifferentProviderNamespacesCanReuseAReceiptString(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->prepare($first, $this->alipay);
        $this->wechat->payment = 'StripeAlipay';
        $this->prepare($second, $this->wechat);
        $this->assertTrue($this->settle($first, $this->alipay, 'same-text')['newly_paid']);
        $this->assertTrue($this->settle($second, $this->wechat, 'same-text', [])['newly_paid']);
        $this->assertSame(2, $this->db->table('v2_payment_attempt')->whereNotNull('callback_hash')->count());
    }

    public function testCheckoutRejectsOtherUserFinishedOrderAndDisabledPayment(): void
    {
        $order = $this->order();
        $this->reject(fn() => $this->service->prepareCheckout($order->trade_no, $order->user_id + 100, $this->alipay));
        foreach ([1, 2, 3, 4] as $status) {
            $closed = $this->order(['status' => $status]);
            $this->reject(fn() => $this->prepare($closed, $this->alipay));
            $this->assertSame(0, $this->attempts($closed)->count());
        }
        $this->alipay->enable = 0;
        $this->reject(fn() => $this->prepare($order, $this->alipay));
        $this->assertSame(0, $this->attempts($order)->count());
        $this->assertNull($order->fresh()->payment_id);
    }

    public function testFreeOrdersStayOutsideExternalPaymentAttempts(): void
    {
        $order = $this->order(['total_amount' => 0]);
        $this->reject(fn() => $this->prepare($order, $this->alipay));
        $this->assertSame(0, $this->attempts($order)->count());
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    public function testFailedBindingSaveRollsBackBothSnapshots(): void
    {
        $order = $this->order(['payment_id' => $this->alipay->id, 'handling_amount' => 180]);
        Order::saving(function () { return false; });
        $this->reject(fn() => $this->prepare($order, $this->wechat));
        $this->assertSame(0, $this->attempts($order)->count());
        $this->assertSame((int)$this->alipay->id, (int)$order->fresh()->payment_id);
    }

    public function testDispatchRunsAfterCommitAndQueueFailureCanRecover(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $original = $this->app[Dispatcher::class];
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(function () use ($order) {
            $this->assertSame(0, $this->db->transactionLevel());
            $this->assertSame(1, (int)$order->fresh()->status);
            $this->assertNotNull($this->attempts($order)->value('callback_hash'));
            throw new \RuntimeException('fixture queue unavailable');
        });
        $this->app->instance(Dispatcher::class, $bus);
        try {
            $this->settle($order, $this->alipay);
            $this->fail('Expected simulated queue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('fixture queue unavailable', $error->getMessage());
        } finally {
            $this->app->instance(Dispatcher::class, $original);
        }
        $this->assertSame(1, (int)$order->fresh()->status);
        $this->assertSame('fixture-receipt', $order->fresh()->callback_no);
        $this->assertFalse($this->settle($order, $this->alipay)['newly_paid']);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        (new OrderService($order))->open();
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(100 * self::GB, (int)$this->user->fresh()->transfer_enable);
    }

    public function testSettlementCannotRunInsideAnUncommittedOuterTransaction(): void
    {
        $order = $this->order();
        $this->prepare($order, $this->alipay);
        $this->db->beginTransaction();
        try {
            $this->settle($order, $this->alipay);
            $this->fail('Expected ownership guard');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('transaction', $error->getMessage());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame(0, (int)$order->fresh()->status);
        $this->assertNull($this->attempts($order)->value('callback_hash'));
        Bus::assertNothingDispatched();
    }
}
