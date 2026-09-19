<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\PlanController;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanTrafficQuotaTest extends TestCase
{
    private const GIB = 1073741824;
    private $database;
    private $app;

    protected function setUp(): void
    {
        // Do not boot the application kernel or load any site's .env/configuration.
        $this->app = new Application();
        $this->app->instance('config', new Repository());
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->app->instance('db', $this->database->getDatabaseManager());
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('make')->andReturnUsing(function ($data, $status, $headers) {
            return new Response($data, $status, $headers);
        });
        $this->app->instance(ResponseFactory::class, $response);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('group_id');
            $table->integer('transfer_enable');
            $table->integer('device_limit')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            foreach (array_merge(array_keys(OrderService::STR_TO_TIME), ['onetime_price', 'reset_price']) as $period) {
                $table->integer($period)->nullable();
            }
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('plan_id')->nullable();
            $table->integer('group_id');
            $table->bigInteger('transfer_enable');
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('expired_at')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('balance')->default(0);
            $table->integer('banned')->default(0);
            $table->string('token')->default('fixture-only');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->string('period');
            $table->integer('type')->default(1);
            $table->integer('status')->default(1);
            $table->integer('refund_amount')->default(0);
            $table->text('surplus_order_ids')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        Mockery::close();
    }

    private function plan(array $prices): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Quota fixture', 'group_id' => 1, 'transfer_enable' => 100,
            'device_limit' => 2, 'speed_limit' => 10, 'reset_traffic_method' => 2
        ], $prices));
    }

    private function user(Plan $plan, $expiry = null): User
    {
        return User::create([
            'plan_id' => $plan->id, 'group_id' => 1, 'transfer_enable' => 900 * self::GIB,
            'u' => 25 * self::GIB, 'd' => 35 * self::GIB, 'expired_at' => $expiry,
            'device_limit' => 2, 'speed_limit' => 10
        ])->fresh();
    }

    private function savePlan(Plan $plan, array $overrides = [], bool $force = true): void
    {
        $request = PlanSave::create('/api/v1/admin/plan/save', 'POST', array_merge([
            'id' => $plan->id, 'force_update' => $force, 'name' => 'Updated quota fixture',
            'group_id' => 2, 'transfer_enable' => 200, 'device_limit' => 8, 'speed_limit' => 100
        ], $overrides));
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $request->setValidator($validator->make($request->all(), $request->rules()));
        $response = (new PlanController())->save($request);
        $this->assertSame(['data' => true], $response->getOriginalContent());
    }

    /** @dataProvider forceUpdateCases */
    public function test_force_update_respects_billing_type(array $prices, array $changes, $expiry, int $expectedGiB): void
    {
        $plan = $this->plan($prices);
        $user = $this->user($plan, $expiry);
        $otherUser = $this->user($this->plan(['month_price' => 100]), 2000000000);
        $otherBefore = $otherUser->getAttributes();
        $before = $user->getAttributes();
        $this->savePlan($plan, $changes);
        $expected = array_merge($before, [
            'transfer_enable' => $expectedGiB * self::GIB, 'group_id' => 2,
            'device_limit' => 8, 'speed_limit' => 100
        ]);
        unset($expected['updated_at']);
        $actual = $user->fresh()->getAttributes();
        unset($actual['updated_at']);
        $this->assertSame($expected, $actual);
        $this->assertSame($otherBefore, $otherUser->fresh()->getAttributes());
        $this->assertSame(200, (int)$plan->fresh()->transfer_enable);
    }

    public function forceUpdateCases(): array
    {
        $cases = [
            'one-time accumulated quota' => [['onetime_price' => 100], [], null, 900],
            'free one-time' => [['onetime_price' => 0], [], null, 900],
            'one-time finite expiry' => [['onetime_price' => 100], [], 2000000000, 900],
            'one-time expired account' => [['onetime_price' => 100], [], 1, 900],
            'one-time price removed' => [['onetime_price' => 100], ['onetime_price' => null], 1, 900],
            'one-time converted to period' => [['onetime_price' => 100], ['onetime_price' => null, 'month_price' => 100], 1, 900],
            'period added to one-time' => [['onetime_price' => 100], ['month_price' => 100], 1, 900],
            'period converted to one-time' => [['month_price' => 100], ['month_price' => null, 'onetime_price' => 100], 2000000000, 900],
            'period expired account' => [['month_price' => 100], [], 1, 200],
            'period old no-expiry account' => [['month_price' => 100], [], null, 900],
            'no prices no-expiry account' => [[], [], null, 900],
            'disabled period retains behavior' => [['month_price' => 100], ['month_price' => null], 2000000000, 200],
            'mixed prices one-time user' => [['onetime_price' => 100, 'month_price' => 100], [], null, 900],
            'mixed prices periodic user' => [['onetime_price' => 100, 'month_price' => 100], [], 2000000000, 200],
            'mixed prices remove period' => [['onetime_price' => 100, 'month_price' => 100], ['month_price' => null], 2000000000, 900],
            'mixed free period' => [['onetime_price' => 100, 'month_price' => 0], [], 2000000000, 200],
            'mixed free one-time' => [['onetime_price' => 0, 'month_price' => 100], [], null, 900]
        ];
        foreach (array_keys(OrderService::STR_TO_TIME) as $period) {
            $cases[$period] = [[$period => 100], [], 2000000000, 200];
        }
        return $cases;
    }

    public function test_saving_without_force_update_never_changes_users(): void
    {
        foreach ([['onetime_price' => 100], ['month_price' => 100]] as $prices) {
            $plan = $this->plan($prices);
            $user = $this->user($plan, 2000000000);
            $before = $user->getAttributes();
            $this->savePlan($plan, [], false);
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertSame(200, (int)$plan->fresh()->transfer_enable);
        }
    }

    public function test_plan_save_failure_rolls_back_all_user_updates(): void
    {
        $plan = $this->plan(['month_price' => 100]);
        $user = $this->user($plan, 2000000000);
        $before = $user->getAttributes();
        $connection = $this->database->getConnection();
        $connection->unprepared("CREATE TRIGGER reject_plan BEFORE UPDATE ON v2_plan BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
        try {
            $this->savePlan($plan);
            $this->fail('Expected a plan update failure');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(100, (int)$plan->fresh()->transfer_enable);
    }

    private function purchase(Plan $plan, User $user, array $fields = []): Order
    {
        $order = Order::create(array_merge([
            'plan_id' => $plan->id, 'user_id' => $user->id,
            'period' => 'onetime_price', 'type' => 1
        ], $fields));
        (new OrderService($order))->open();
        $this->assertSame(3, (int)$order->fresh()->status);
        return $order;
    }

    /** @dataProvider oneTimePurchaseCases */
    public function test_one_time_purchase_keeps_original_unused_quota_carryover(int $quota, int $up, int $down, $expiry, int $expected): void
    {
        $plan = $this->plan(['onetime_price' => 100]);
        $user = $this->user($plan, $expiry);
        $user->update(['transfer_enable' => $quota, 'u' => $up, 'd' => $down]);
        $this->purchase($plan, $user);
        $user->refresh();
        $this->assertSame($expected, (int)$user->transfer_enable);
        $this->assertSame(0, (int)$user->u);
        $this->assertSame(0, (int)$user->d);
        $expectedRemaining = ($expiry === null ? max(0, $quota - $up - $down) : 0) + 100 * self::GIB;
        $this->assertSame($expectedRemaining, (int)($user->transfer_enable - $user->u - $user->d));
        $this->assertNull($user->expired_at);
        $this->assertSame($plan->id, (int)$user->plan_id);
    }

    public function oneTimePurchaseCases(): array
    {
        $gib = self::GIB;
        return [
            'unused carryover' => [500 * $gib, 80 * $gib, 120 * $gib, null, 400 * $gib],
            'all unused' => [500 * $gib, 0, 0, null, 600 * $gib],
            'exhausted quota' => [500 * $gib, 200 * $gib, 300 * $gib, null, 100 * $gib],
            'overused quota' => [100 * $gib, 80 * $gib, 120 * $gib, null, 100 * $gib],
            'new account' => [0, 0, 0, null, 100 * $gib],
            'byte precision' => [500 * $gib + 5, 3, 1, null, 600 * $gib + 1],
            'cycle balance not carried' => [500 * $gib, 80 * $gib, 120 * $gib, 2000000000, 100 * $gib]
        ];
    }

    public function test_force_update_between_repeat_purchases_preserves_carryover(): void
    {
        $plan = $this->plan(['onetime_price' => 100]);
        $user = $this->user($plan);
        $user->update(['transfer_enable' => 500 * self::GIB, 'u' => 80 * self::GIB, 'd' => 120 * self::GIB]);
        $this->savePlan($plan);
        $this->assertSame(500 * self::GIB, (int)$user->fresh()->transfer_enable);
        $this->purchase($plan, $user);
        $this->assertSame(500 * self::GIB, (int)$user->fresh()->transfer_enable);
        $this->assertSame(0, (int)$user->fresh()->u);
        $this->assertSame(0, (int)$user->fresh()->d);
        $this->savePlan($plan, ['transfer_enable' => 300]);
        $this->savePlan($plan, ['transfer_enable' => 300]);
        $user->refresh()->update(['u' => 75 * self::GIB, 'd' => 25 * self::GIB]);
        $this->purchase($plan, $user);
        $this->assertSame(700 * self::GIB, (int)$user->fresh()->transfer_enable);
        $this->assertSame(0, (int)$user->fresh()->u);
        $this->assertSame(0, (int)$user->fresh()->d);
    }

    /** @dataProvider resetEventCases */
    public function test_purchase_events_do_not_give_back_used_quota(int $type): void
    {
        $this->app['config']->set('v2board', [
            'new_order_event_id' => 1, 'renew_order_event_id' => 1, 'change_order_event_id' => 1
        ]);
        $plan = $this->plan(['onetime_price' => 100]);
        $user = $this->user($plan);
        $this->purchase($plan, $user, ['type' => $type]);
        $this->assertSame(940 * self::GIB, (int)$user->fresh()->transfer_enable);
        $this->assertSame(0, (int)$user->fresh()->u);
        $this->assertSame(0, (int)$user->fresh()->d);
    }

    public function resetEventCases(): array
    {
        return ['new' => [1], 'renewal' => [2], 'change without conversion' => [3]];
    }

    public function test_converted_orders_do_not_carry_the_same_balance_twice(): void
    {
        $plan = $this->plan(['onetime_price' => 100]);
        $user = $this->user($plan);
        $old = Order::create(['plan_id' => $plan->id, 'user_id' => $user->id, 'period' => 'onetime_price', 'status' => 3]);
        $this->purchase($plan, $user, ['surplus_order_ids' => [$old->id]]);
        $this->assertSame(100 * self::GIB, (int)$user->fresh()->transfer_enable);
        $this->assertSame(4, (int)$old->fresh()->status);
    }

    public function test_periodic_renewal_still_uses_plan_quota_without_stacking(): void
    {
        $plan = $this->plan(['month_price' => 100]);
        $expiry = strtotime('+10 days');
        $user = $this->user($plan, $expiry);
        $this->purchase($plan, $user, ['period' => 'month_price', 'type' => 2]);
        $user->refresh();
        $this->assertSame(100 * self::GIB, (int)$user->transfer_enable);
        $this->assertSame(25 * self::GIB, (int)$user->u);
        $this->assertSame(35 * self::GIB, (int)$user->d);
        $this->assertSame(strtotime('+1 month', $expiry), (int)$user->expired_at);
    }
}
