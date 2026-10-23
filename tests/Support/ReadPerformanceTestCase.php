<?php

namespace Tests\Support;

use App\Http\Controllers\V1\Admin\StatController;
use App\Http\Requests\Admin\OrderFetch;
use App\Services\ClientGateway\OnlineDeviceService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ReadPerformanceClock.php';

abstract class ReadPerformanceTestCase extends TestCase
{
    protected $app;
    protected $db;
    protected $redis;
    private $timezone;
    private const TABLES = ['v2_user', 'v2_order', 'v2_plan', 'v2_ticket', 'v2_ticket_message',
        'v2_stat_user', 'v2_commission_log', 'v2_coupon', 'v2_giftcard', 'v2_log'];

    abstract protected function connection(): ?array;

    protected function cacheStore()
    {
        return new ArrayStore();
    }

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        ReadPerformanceClock::$now = strtotime('2026-10-12 12:00:00');
        [$this->app, $this->db] = UserReadDatabase::boot($this->connection());
        $schema = $this->db->getSchemaBuilder();
        $schema->table('v2_user', function ($table) { $table->integer('t')->nullable(); });
        foreach (array_diff(self::TABLES, ['v2_user', 'v2_plan']) as $name) $schema->dropIfExists($name);
        $schema->create('v2_order', function ($table) {
            $table->increments('id');
            foreach (['user_id', 'plan_id', 'status', 'commission_status', 'invite_user_id', 'commission_balance'] as $key) $table->integer($key)->nullable();
            $table->bigInteger('total_amount')->nullable();
            $table->string('trade_no')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->default(0);
        });
        $schema->create('v2_ticket', function ($table) {
            $table->increments('id');
            foreach (['user_id', 'status', 'reply_status', 'created_at', 'updated_at'] as $key) $table->integer($key)->nullable();
            $table->text('subject')->nullable();
        });
        $schema->create('v2_ticket_message', function ($table) {
            $table->increments('id');
            $table->integer('ticket_id'); $table->integer('user_id'); $table->text('message');
        });
        $schema->create('v2_stat_user', function ($table) {
            $table->increments('id'); $table->integer('user_id');
            $table->decimal('server_rate', 12, 4)->default(1);
            $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0);
            $table->integer('record_at'); $table->string('record_type')->default('d');
        });
        $schema->create('v2_commission_log', function ($table) {
            $table->increments('id'); $table->integer('invite_user_id')->nullable();
            $table->string('trade_no')->nullable(); $table->bigInteger('order_amount')->nullable();
            $table->bigInteger('get_amount')->nullable(); $table->integer('created_at')->nullable();
        });
        foreach (['v2_giftcard', 'v2_coupon'] as $name) $schema->create($name, function ($table) {
            $table->increments('id'); $table->string('name'); $table->string('code');
            $table->integer('created_at'); $table->integer('updated_at')->default(0);
        });
        $schema->create('v2_log', function ($table) {
            $table->increments('id'); $table->string('level'); $table->text('message');
            $table->integer('created_at'); $table->integer('updated_at')->default(0);
        });
        $this->app->instance('validator', new Factory($this->app['translator'], $this->app));
        Request::macro('validate', function ($rules) {
            return app('validator')->make($this->all(), $rules)->validate();
        });
        $devices = \Mockery::mock(OnlineDeviceService::class);
        $devices->shouldReceive('snapshot')->andReturn(['count' => 7, 'window_seconds' => 420]);
        $this->app->instance(OnlineDeviceService::class, $devices);
    }

    protected function tearDown(): void
    {
        if ($this->redis) $this->redis->connection()->disconnect();
        if ($this->db) $this->db->disconnect();
        ReadPerformanceClock::$now = null;
        date_default_timezone_set($this->timezone);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        \Mockery::close();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) $rows[$table] = json_encode($this->db->table($table)->orderBy('id')->get());
        return $rows;
    }

    private function seedLists(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1, 'email' => 'fixture@example.invalid']);
        $this->db->table('v2_plan')->insert(['id' => 1, 'name' => 'Plan A']);
        $data = [];
        for ($id = 1; $id <= 320; $id++) {
            $data['v2_order'][] = ['id' => $id, 'user_id' => 1, 'plan_id' => 1, 'trade_no' => 'fixture-' . $id,
                'status' => $id % 4, 'created_at' => $id, 'total_amount' => 100];
            $data['v2_ticket'][] = ['id' => $id, 'user_id' => 1, 'status' => $id % 2, 'reply_status' => $id % 2,
                'subject' => 'fixture', 'created_at' => $id, 'updated_at' => 1000 - $id];
            $data['v2_stat_user'][] = ['id' => $id, 'user_id' => 1, 'record_at' => $id];
            $data['v2_commission_log'][] = ['id' => $id, 'invite_user_id' => 1, 'trade_no' => 'fixture-' . $id,
                'order_amount' => 100, 'get_amount' => 10, 'created_at' => $id];
            $data['v2_log'][] = ['id' => $id, 'level' => $id % 2 ? 'info' : 'error', 'message' => 'fixture', 'created_at' => $id];
            foreach (['v2_giftcard', 'v2_coupon'] as $name) {
                $data[$name][] = ['id' => $id, 'name' => 'fixture-' . $id, 'code' => 'fixture-' . $id, 'created_at' => $id];
            }
        }
        foreach ($data as $name => $rows) foreach (array_chunk($rows, 50) as $batch) $this->db->table($name)->insert($batch);
    }

    private function fetch(string $endpoint, array $input): array
    {
        $map = [
            'order' => ['Admin\\OrderController', 'fetch'], 'ticket' => ['Admin\\TicketController', 'fetch'],
            'staff_ticket' => ['Staff\\TicketController', 'fetch'], 'coupon' => ['Admin\\CouponController', 'fetch'],
            'giftcard' => ['Admin\\GiftcardController', 'fetch'], 'log' => ['Admin\\SystemController', 'getSystemLog'],
            'stat' => ['Admin\\StatController', 'getStatUser'], 'invite' => ['User\\InviteController', 'details'],
        ];
        $requestClass = $endpoint === 'order' ? OrderFetch::class : Request::class;
        $request = new $requestClass(array_merge(['user' => ['id' => 1], 'user_id' => 1], $input));
        $this->app->instance('request', $request);
        [$suffix, $method] = $map[$endpoint];
        $class = 'App\\Http\\Controllers\\V1\\' . $suffix;
        $result = (new $class())->$method($request);
        return is_array($result) ? json_decode(json_encode($result), true) : json_decode($result->getContent(), true);
    }

    public static function endpoints(): array
    {
        return array_map(fn($x) => [$x], ['order', 'ticket', 'staff_ticket', 'coupon', 'giftcard', 'log', 'stat', 'invite']);
    }

    /** @dataProvider endpoints */
    public function testPaginationIsBoundedAndKeepsNormalPages(string $endpoint): void
    {
        $this->seedLists();
        $before = $this->snapshot();
        $key = in_array($endpoint, ['log', 'invite']) ? 'page_size' : 'pageSize';
        $ids = $endpoint === 'ticket' ? range(1, 320) : range(320, 1);
        foreach ([null, 0, -10, 10, 20, 50, 100, 150, 151, 999999999, '10000000000000000000000', 'not-a-number', [], 10.5] as $size) {
            $expectedSize = min(150, max(10, (int) $size));
            $result = $this->fetch($endpoint, [$key => $size]);
            $this->assertSame(320, $result['total']);
            $this->assertSame(array_slice($ids, 0, $expectedSize), array_column($result['data'], 'id'));
        }
        foreach ([1, 2, 3, 4] as $page) {
            $result = $this->fetch($endpoint, [$key => 150, 'current' => $page]);
            $this->assertSame(320, $result['total']);
            $this->assertSame(array_slice($ids, ($page - 1) * 150, 150), array_column($result['data'], 'id'));
        }
        $this->assertSame($before, $this->snapshot());
        Bus::assertNothingDispatched();
    }

    public function testExistingFiltersSortingAndTicketDetailRemainIntact(): void
    {
        $this->seedLists();
        $this->assertSame(80, $this->fetch('order', ['filter' => [['key' => 'status', 'condition' => '=', 'value' => 1]]])['total']);
        $this->assertSame(160, $this->fetch('ticket', ['status' => 0, 'reply_status' => [0]])['total']);
        $this->assertSame(160, $this->fetch('staff_ticket', ['status' => 1])['total']);
        $this->assertSame(160, $this->fetch('log', ['filter' => [['key' => 'level', 'condition' => 'is', 'value' => 'error']]])['total']);
        foreach (['giftcard', 'coupon'] as $endpoint) {
            $this->assertSame(range(1, 10), array_column($this->fetch($endpoint, ['sort' => 'id', 'sort_type' => 'ASC'])['data'], 'id'));
        }
        $this->assertSame(0, $this->fetch('stat', ['user_id' => 999])['total']);
        $this->assertSame(0, $this->fetch('invite', ['user' => ['id' => 999]])['total']);
        $this->db->table('v2_ticket_message')->insert([
            ['id' => 1, 'ticket_id' => 1, 'user_id' => 1, 'message' => 'fixture question'],
            ['id' => 2, 'ticket_id' => 1, 'user_id' => 2, 'message' => 'fixture answer'],
        ]);
        $before = $this->snapshot();
        foreach (['ticket', 'staff_ticket'] as $endpoint) {
            $detail = $this->fetch($endpoint, ['id' => 1])['data'];
            $this->assertSame(1, $detail['id']);
            $this->assertSame([false, true], array_column($detail['message'], 'is_me'));
        }
        $this->assertSame($before, $this->snapshot());
        Bus::assertNothingDispatched();
    }

    public static function dates(): array
    {
        return [['2026-10-12 12:00:00'], ['2026-10-01 00:00:00'], ['2026-01-01 00:00:00'],
            ['2028-03-01 00:00:01'], ['2026-12-31 23:59:59']];
    }

    /** @dataProvider dates */
    public function testDashboardExactlyMatchesLegacyAtTimeBoundaries(string $date): void
    {
        $now = ReadPerformanceClock::$now = strtotime($date);
        $month = strtotime(date('Y-m-1', $now)); $day = strtotime(date('Y-m-d', $now));
        $last = strtotime('-1 month', $month);
        $points = [$last - 1, $last, $month - 1, $month, $day - 1, $day, $now - 601, $now - 600, $now - 1, $now, $now + 1];
        $id = 0;
        foreach ($points as $point) {
            $this->db->table('v2_user')->insert(['id' => ++$id, 'created_at' => $point, 't' => $point]);
            foreach ([null, 0, 1, 2, 3, 4] as $status) {
                foreach ([null, 0, 99, -99, 1900000000] as $amount) {
                    $this->db->table('v2_order')->insert(['created_at' => $point, 'status' => $status, 'total_amount' => $amount,
                        'commission_status' => $id % 3, 'invite_user_id' => $id % 2 ? null : 0, 'commission_balance' => $amount]);
                    $this->db->table('v2_commission_log')->insert(['created_at' => $point, 'get_amount' => $amount]);
                }
            }
        }
        foreach ([null, 0, 1] as $status) foreach ([null, 0, 1] as $reply) {
            $this->db->table('v2_ticket')->insert(['status' => $status, 'reply_status' => $reply]);
        }
        $this->assertOverviewParity();
    }

    private function assertOverviewParity(): void
    {
        $before = $this->snapshot();
        $devices = ['count' => 7, 'window_seconds' => 420];
        $expected = LegacyReadQueries::overview(ReadPerformanceClock::$now, $devices);
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        $actual = (new StatController())->getOverride(new Request());
        $queries = $this->db->getQueryLog(); $this->db->disableQueryLog();
        $this->assertSame($expected, $actual);
        $this->assertSame(json_encode($expected), json_encode($actual));
        $this->assertCount(4, $queries);
        $this->assertSame($before, $this->snapshot());
        Bus::assertNothingDispatched();
    }

    public function testEmptyDashboardAndZeroSumTypesRemainUnchanged(): void
    {
        $this->assertOverviewParity();
        foreach ([-100, 100] as $amount) {
            $this->db->table('v2_order')->insert(['created_at' => ReadPerformanceClock::$now - 1, 'status' => 3, 'total_amount' => $amount]);
            $this->db->table('v2_commission_log')->insert(['created_at' => ReadPerformanceClock::$now - 1, 'get_amount' => $amount]);
        }
        $this->assertOverviewParity();
    }

    public static function rankCases(): array { return [[false, false], [true, false], [false, true], [true, true]]; }

    /** @dataProvider rankCases */
    public function testRankingMatchesLegacyIncludingRatesMissingUsersAndTies(bool $yesterday, bool $empty): void
    {
        $now = ReadPerformanceClock::$now;
        $end = $yesterday ? strtotime(date('Y-m-d', $now)) : $now;
        $start = $yesterday ? strtotime('-1 day', $end) : strtotime(date('Y-m-d', $now));
        if (!$empty) {
            for ($id = 1; $id <= 20; $id++) $this->db->table('v2_user')->insert(['id' => $id, 'email' => $id === 2 ? null : 'fixture' . $id . '@example.invalid']);
            for ($i = 1; $i <= 40; $i++) {
                $this->db->table('v2_stat_user')->insert(['user_id' => $i % 23 + 1, 'u' => intdiv($i, 2) * 1073741824,
                    'd' => 1073741824, 'server_rate' => [0, .25, 1, 2.5][$i % 4], 'record_at' => $start]);
            }
            foreach ([$start - 1, $end, $end + 1] as $point) $this->db->table('v2_stat_user')->insert(['user_id' => 999, 'u' => 999999999999, 'record_at' => $point]);
            $this->db->table('v2_stat_user')->insert(['user_id' => 999, 'u' => 999999999999, 'record_at' => $start, 'record_type' => 'm']);
        }
        $before = $this->snapshot();
        $expected = LegacyReadQueries::userRank($start, $end);
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        $controller = new StatController();
        $actual = $yesterday ? $controller->getUserLastRank() : $controller->getUserTodayRank();
        $queries = $this->db->getQueryLog(); $this->db->disableQueryLog();
        $this->assertSame($expected, $actual);
        $this->assertCount($empty ? 1 : 2, $queries);
        if (!$empty) {
            $sql = str_replace(['`', '"'], '', $queries[1]['query']);
            $this->assertStringContainsString('select email, id from v2_user where id in', $sql);
            $this->assertLessThanOrEqual(30, count($queries[1]['bindings']));
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function nodeCases(): array
    {
        return [[null, null, null], [0, -300, -1], [5, -299, -300], [12, -299, -299], [false, 1, 1], ['0', 0, 0]];
    }

    /** @dataProvider nodeCases */
    public function testNodeStatusMatchesLegacyAndReadsUniqueKeysOnce($online, $check, $push): void
    {
        $cache = new class($this->cacheStore()) extends Repository {
            public $gets = 0;
            public $batches = [];
            public function get($key, $default = null) { $this->gets++; return parent::get($key, $default); }
            public function many($keys) { $this->batches[] = $keys; return parent::many($keys); }
        };
        Cache::swap($cache);
        $now = ReadPerformanceClock::$now;
        $servers = [];
        $keys = [];
        foreach (['vmess', 'shadowsocks', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            foreach ([['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => 1], ['id' => 3, 'parent_id' => 1], ['id' => 4, 'parent_id' => 0]] as $node) {
                $servers[] = $node + ['type' => $type, 'sort' => 1, 'name' => 'fixture', 'port' => 443];
            }
            foreach ([1, 0] as $id) foreach (['ONLINE_USER' => $online, 'LAST_CHECK_AT' => $check === null ? null : $now + $check,
                'LAST_PUSH_AT' => $push === null ? null : $now + $push] as $suffix => $value) {
                $key = CacheKey::get('SERVER_' . strtoupper($type) . '_' . $suffix, $id);
                $keys[] = $key;
                if ($value !== null) $cache->put($key, $value, 30);
            }
        }
        $expected = LegacyReadQueries::serverStatus($servers, $now);
        $this->assertSame(96, $cache->gets);
        $cache->gets = 0;
        $method = new \ReflectionMethod(ServerService::class, 'mergeData'); $method->setAccessible(true);
        $args = [&$servers]; $method->invokeArgs(new ServerService(), $args);
        $this->assertSame($expected, $servers);
        $this->assertSame(0, $cache->gets);
        $this->assertCount(1, $cache->batches);
        $this->assertCount(48, $cache->batches[0]);
        $this->assertCount(48, array_unique($cache->batches[0]));
        foreach ($keys as $key) $cache->forget($key);
    }

    public function testEmptyNodesDoNotReadCache(): void
    {
        Cache::shouldReceive('get')->never(); Cache::shouldReceive('many')->never();
        $servers = [];
        $method = new \ReflectionMethod(ServerService::class, 'mergeData'); $method->setAccessible(true);
        $args = [&$servers]; $method->invokeArgs(new ServerService(), $args);
        $this->assertSame([], $servers);
    }
}
