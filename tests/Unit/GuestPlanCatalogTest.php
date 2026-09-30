<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Routes\V1\GuestRoute;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class GuestPlanCatalogTest extends TestCase
{
    private const PERIODS = [
        'month_price', 'quarter_price', 'half_year_price', 'year_price',
        'two_year_price', 'three_year_price', 'onetime_price',
    ];

    private $app;
    private $database;

    protected function setUp(): void
    {
        // An isolated container and SQLite memory database: never load a site's .env.
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
            $table->text('content')->nullable();
            $table->integer('transfer_enable');
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            foreach (array_merge(self::PERIODS, ['reset_price']) as $period) {
                $table->integer($period)->nullable();
            }
            $table->integer('show')->default(1);
            $table->integer('sort')->default(0);
            $table->integer('capacity_limit')->nullable();
            $table->integer('group_id')->default(789);
            $table->integer('renew')->default(1);
            $table->integer('cost')->default(456);
            $table->text('internal_note')->default('private fixture');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('plan_id')->nullable();
            $table->integer('expired_at')->nullable();
            $table->string('email')->default('private-user@example.test');
            $table->integer('balance')->default(99999);
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

    private function plan(array $attributes = []): int
    {
        return $this->database->getConnection()->table('v2_plan')->insertGetId(array_merge([
            'name' => 'Public fixture plan',
            'content' => '<p>Configured description</p>',
            'transfer_enable' => 100,
            'month_price' => 1200,
        ], $attributes));
    }

    private function payload(): array
    {
        return json_decode((new PlanController())->fetch()->getContent(), true);
    }

    public function testOnlyVisiblePurchasablePlansAreReturnedInConfiguredOrder(): void
    {
        $last = $this->plan(['sort' => 30]);
        $first = $this->plan(['sort' => 10]);
        $second = $this->plan(['sort' => 10]);
        $this->plan(['show' => 0, 'sort' => 1]);
        $this->plan(['month_price' => null, 'reset_price' => 100]);
        $this->plan(['month_price' => null]);
        $this->plan(['month_price' => -1]);

        $this->assertSame([$first, $second, $last], array_column($this->payload()['data'], 'id'));
    }

    public function testEveryNormalPeriodCanEnableASaleAndZeroPricesArePreserved(): void
    {
        foreach (self::PERIODS as $index => $period) {
            $attributes = array_fill_keys(self::PERIODS, null);
            $attributes[$period] = $index === 0 ? 0 : ($index + 1) * 100;
            $attributes['name'] = $period;
            $this->plan($attributes);
        }

        $data = $this->payload()['data'];
        $this->assertCount(7, $data);
        foreach ($data as $index => $plan) {
            $enabled = self::PERIODS[$index];
            $this->assertSame($index === 0 ? 0 : ($index + 1) * 100, $plan[$enabled]);
            $this->assertTrue($plan['available']);
            foreach (self::PERIODS as $period) {
                if ($period !== $enabled) $this->assertNull($plan[$period]);
            }
        }
    }

    public function testAnonymousPayloadHasOnlyExplicitPublicFields(): void
    {
        $this->plan(['reset_price' => 333, 'capacity_limit' => 9, 'speed_limit' => 500, 'device_limit' => 4]);
        $payload = $this->payload();
        $this->assertSame(['data', 'currency', 'currency_symbol'], array_keys($payload));
        $fields = array_merge(['id', 'name', 'content', 'transfer_enable', 'speed_limit', 'device_limit'], self::PERIODS, ['available']);
        $this->assertSame($fields, array_keys($payload['data'][0]));
        $this->assertSame('<p>Configured description</p>', $payload['data'][0]['content']);
        $this->assertSame(100, $payload['data'][0]['transfer_enable']);
        $this->assertSame(500, $payload['data'][0]['speed_limit']);
        $this->assertSame(4, $payload['data'][0]['device_limit']);
        $this->assertStringNotContainsString('private', json_encode($payload));
    }

    public function testCapacityIsExposedOnlyAsAvailabilityAndUsesExistingActiveUserRules(): void
    {
        $unlimited = $this->plan(['capacity_limit' => null]);
        $empty = $this->plan(['capacity_limit' => 0]);
        $soldOut = $this->plan(['capacity_limit' => 2]);
        $expiredOnly = $this->plan(['capacity_limit' => 1]);
        $remaining = $this->plan(['capacity_limit' => 2]);
        $users = $this->database->getConnection()->table('v2_user');
        $users->insert([
            ['plan_id' => $soldOut, 'expired_at' => null],
            ['plan_id' => $soldOut, 'expired_at' => time() + 3600],
            ['plan_id' => $soldOut, 'expired_at' => time() - 3600],
            ['plan_id' => $expiredOnly, 'expired_at' => time() - 3600],
            ['plan_id' => $remaining, 'expired_at' => null],
            ['plan_id' => null, 'expired_at' => null],
        ]);
        $plans = array_column($this->payload()['data'], null, 'id');
        $this->assertTrue($plans[$unlimited]['available']);
        $this->assertFalse($plans[$empty]['available']);
        $this->assertFalse($plans[$soldOut]['available']);
        $this->assertTrue($plans[$expiredOnly]['available']);
        $this->assertTrue($plans[$remaining]['available']);
        foreach ($plans as $plan) {
            $this->assertArrayNotHasKey('capacity_limit', $plan);
            $this->assertArrayNotHasKey('count', $plan);
        }
    }

    public function testCurrencyTracksConfigurationAndEmptyCatalogueHasTheSameContract(): void
    {
        $this->assertSame(['data' => [], 'currency' => 'CNY', 'currency_symbol' => '¥'], $this->payload());
        $this->app['config']->set('v2board.currency', 'USD');
        $this->app['config']->set('v2board.currency_symbol', '$');
        $id = $this->plan();
        $payload = $this->payload();
        $this->assertSame('USD', $payload['currency']);
        $this->assertSame('$', $payload['currency_symbol']);
        $this->database->getConnection()->table('v2_plan')->where('id', $id)->update(['month_price' => 2700, 'name' => 'Updated plan']);
        $this->assertSame(2700, $this->payload()['data'][0]['month_price']);
        $this->assertSame('Updated plan', $this->payload()['data'][0]['name']);
    }

    public function testFetchOnlyReadsDatabaseAndDoesNotQueryUsersForUnlimitedPlans(): void
    {
        $this->plan();
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $this->payload();
        $queries = $connection->getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^select /i', $queries[0]['query']);
        $this->assertStringNotContainsString('v2_user', $queries[0]['query']);
    }

    private function router(): Router
    {
        $router = new Router(new Dispatcher($this->app), $this->app);
        $router->group(['prefix' => 'api/v1', 'namespace' => 'App\Http\Controllers'], function ($router) {
            (new GuestRoute())->map($router);
        });
        return $router;
    }

    public function testGuestRouteWorksWithoutAuthenticationAndCannotSelectAHiddenPlan(): void
    {
        $shown = $this->plan();
        $hidden = $this->plan(['show' => 0]);
        $router = $this->router();
        $request = Request::create('/api/v1/guest/plan/fetch?id=' . $hidden, 'GET');
        $this->assertFalse($request->headers->has('Authorization'));
        $response = $router->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$shown], array_column(json_decode($response->getContent(), true)['data'], 'id'));
    }

    public function testPublicCatalogueDoesNotRegisterAnyWriteMethod(): void
    {
        $router = $this->router();
        $this->expectException(MethodNotAllowedHttpException::class);
        $router->dispatch(Request::create('/api/v1/guest/plan/fetch', 'POST'));
    }
}
