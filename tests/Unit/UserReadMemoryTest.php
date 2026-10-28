<?php

namespace Tests\Unit;

use App\Console\Commands\SendRemindMail;
use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserSendMail;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\MailService;
use App\Services\UserBatchService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\UserReadDatabase;

class UserReadMemoryTest extends TestCase
{
    private $app;
    private $db;

    protected function setUp(): void
    {
        [$this->app, $this->db] = UserReadDatabase::boot();
        UserReadDatabase::seed($this->db, 1203);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        \Mockery::close();
    }

    private function jobs(): array
    {
        return Bus::dispatched(SendEmailJob::class)->map(function ($job) {
            $property = new \ReflectionProperty($job, 'params');
            return ['queue' => $job->queue, 'params' => $property->getValue($job)];
        })->all();
    }

    private function csv(array $filters = []): string
    {
        $response = (new UserController())->dumpCSV(new Request(['filter' => $filters]));
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        ob_start();
        try { $response->sendContent(); return ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    public function testBatchesAreBoundedWithNarrowColumnsAndNoDuplicates(): void
    {
        $this->db->enableQueryLog();
        $ids = [];
        UserBatchService::each(User::query(), ['id', 'email'], function ($user) use (&$ids) {
            $this->assertSame(['id', 'email'], array_keys($user->getAttributes()));
            $ids[] = $user->id;
        });
        $this->assertSame(range(1, 1203), $ids);
        $queries = $this->db->getQueryLog();
        $this->assertCount(3, $queries);
        foreach ($queries as $query) $this->assertStringContainsString('limit 500', $query['query']);
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function testCallbackFailureIsNotRetriedAndTransactionIsReleased(): void
    {
        $count = 0;
        try {
            UserBatchService::each(User::query(), ['id'], function () use (&$count) {
                if (++$count === 2) throw new \RuntimeException('fixture failure');
            });
            $this->fail('Expected callback failure');
        } catch (\RuntimeException $e) { $this->assertSame('fixture failure', $e->getMessage()); }
        $this->assertSame(2, $count);
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public static function filters(): array
    {
        return [
            'all' => [[], []],
            'null plan' => [[['key' => 'plan_id', 'condition' => '=', 'value' => 'null']], ['plan_id', 'null']],
            'fuzzy' => [[['key' => 'email', 'condition' => '模糊', 'value' => 'fixture11']], ['email', 'like', '%fixture11%']],
            'traffic GB' => [[['key' => 'd', 'condition' => '>=', 'value' => 8]], ['d', '>=', 8 * 1073741824]],
            'quota GB' => [[['key' => 'transfer_enable', 'condition' => '=', 'value' => 10]], ['transfer_enable', '=', 10 * 1073741824]],
            'inviter' => [[['key' => 'invite_by_email', 'condition' => '=', 'value' => 'fixture1@example.invalid']], ['invite_user_id', '=', 1]],
            'missing inviter' => [[['key' => 'invite_by_email', 'condition' => '=', 'value' => 'none@example.invalid']], ['invite_user_id', '=', 0]],
            'banned' => [[['key' => 'banned', 'condition' => '=', 'value' => 1]], ['banned', '=', 1]],
            'no match' => [[['key' => 'id', 'condition' => '>', 'value' => 9999]], ['id', '>', 9999]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filters')]
    public function testFiltersHaveIdenticalRecipientsListTotalsAndCsvRows(array $filter, array $where): void
    {
        $query = User::query();
        if ($where) {
            if (($where[1] ?? '') === 'null') $query->whereNull($where[0]);
            else $query->where(...$where);
        }
        $expected = (clone $query)->orderBy('id')->get();
        $controller = new UserController();
        $list = json_decode($controller->fetch(new UserFetch(['filter' => $filter, 'pageSize' => 150, 'sort' => 'id', 'sort_type' => 'ASC']))->getContent(), true);
        $this->assertSame($expected->count(), $list['total']);
        $this->assertSame($expected->take(150)->pluck('id')->all(), array_column($list['data'], 'id'));
        $controller->sendMail(new UserSendMail(['filter' => $filter, 'sort' => 'id', 'sort_type' => 'ASC', 'subject' => 'Fixture', 'content' => '<p>Fixture</p>']));
        $jobs = $this->jobs();
        $this->assertSame($expected->pluck('email')->all(), array_column(array_column($jobs, 'params'), 'email'));
        foreach ($jobs as $job) {
            $this->assertSame('send_email_mass', $job['queue']);
            $this->assertSame('notify', $job['params']['template_name']);
            $this->assertSame('<p>Fixture</p>', $job['params']['template_value']['content']);
        }
        $csv = $this->csv($filter);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = explode("\r\n", $csv);
        $this->assertSame('', array_pop($rows));
        array_shift($rows);
        $this->assertSame($expected->pluck('email')->all(), array_map(function ($row) { return explode(',', $row)[0]; }, $rows));
    }

    public function testCsvRetainsLegacyBytesIncludingNegativeTrafficAndEmptyDeviceColumn(): void
    {
        $csv = $this->csv([['key' => 'id', 'condition' => '=', 'value' => 11]]);
        $this->assertSame("\xEF\xBB\xBF邮箱,余额,推广佣金,总流量,设备数限制,剩余流量,套餐到期时间,订阅计划,订阅地址\r\n"
            . "fixture11@example.invalid,0.11,0.1,10, , -2,长期有效,Plan B,https://fixture.invalid/api/v1/client/subscribe?token=token-11\r\n", $csv);
    }

    public function testMailRetainsRequestedSortAcrossMultipleBatches(): void
    {
        $expected = User::orderBy('created_at', 'DESC')->orderBy('id')->pluck('email')->all();
        (new UserController())->sendMail(new UserSendMail(['sort' => 'created_at', 'sort_type' => 'DESC']));
        $this->assertSame($expected, array_column(array_column($this->jobs(), 'params'), 'email'));
    }

    public static function pageSizes(): array
    {
        return [[null, 10], [1, 10], [-20, 10], [10, 10], [50, 50], [100, 100], [150, 150], [1000000, 150]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageSizes')]
    public function testPageSizePreservesUiChoicesAndRejectsUnboundedReads($input, int $expected): void
    {
        $result = (new UserController())->fetch(new UserFetch(['pageSize' => $input, 'current' => -1]));
        $this->assertCount($expected, json_decode($result->getContent(), true)['data']);
    }

    public function testOnlineDataUsesOneBulkReadWithDistinctIpsAndNoStaleCache(): void
    {
        $now = now()->timestamp;
        $this->db->table('v2_hy2_online')->insert([
            ['node_id' => 1, 'user_id' => 1, 'ips' => '["192.0.2.1","2001:db8::1"]', 'observed_at' => $now, 'expires_at' => $now + 120],
            ['node_id' => 2, 'user_id' => 1, 'ips' => '["192.0.2.1"]', 'observed_at' => $now, 'expires_at' => $now + 120],
            ['node_id' => 3, 'user_id' => 2, 'ips' => '["192.0.2.2"]', 'observed_at' => $now - 200, 'expires_at' => $now - 1],
        ]);
        Cache::put('ALIVE_IP_USER_1', ['alive_ip' => 99]);
        $queries = [];
        $this->db->listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'v2_hy2_online') && str_starts_with($query->sql, 'select')) $queries[] = $query->sql;
        });
        $controller = new UserController();
        $request = new UserFetch(['sort' => 'id', 'sort_type' => 'ASC']);
        $data = json_decode($controller->fetch($request)->getContent(), true)['data'];
        $this->assertCount(1, $queries);
        $this->assertSame(2, $data[0]['alive_ip']);
        $this->assertEqualsCanonicalizing(['192.0.2.1_v2node1', '2001:db8::1_v2node1', '192.0.2.1_v2node2'], explode(', ', $data[0]['ips']));
        $this->assertSame('Plan A', $data[0]['plan_name']);
        $this->assertArrayNotHasKey('plan_name', $data[2]);
        $this->assertSame(0, $data[1]['alive_ip']);
        $this->assertSame('', $data[1]['ips']);
        $this->db->table('v2_hy2_online')->update(['expires_at' => $now - 1]);
        $again = json_decode($controller->fetch($request)->getContent(), true)['data'];
        $this->assertSame(0, $again[0]['alive_ip']);
        $this->assertCount(2, $queries);
    }

    public function testReminderRecipientsAndThrottleMatchOriginalWithoutUnlimitedMemory(): void
    {
        $this->db->table('v2_user')->update(['remind_expire' => 1, 'remind_traffic' => 1]);
        $this->db->table('v2_user')->where('id', '<=', 5)->update(['expired_at' => time() + 3600]);
        $this->db->table('v2_user')->where('id', '>', 5)->where('id', '<=', 10)->update(['expired_at' => time() - 60]);
        $this->db->table('v2_user')->where('id', '<=', 12)->update(['transfer_enable' => 1000, 'u' => 960, 'd' => 0]);
        $service = new MailService();
        foreach (User::all() as $user) {
            if ($user->remind_expire) $service->remindExpire($user);
            if (!($user->expired_at !== null && $user->expired_at < time()) && $user->remind_traffic) $service->remindTraffic($user);
        }
        $expected = $this->jobs();
        Cache::flush(); Bus::fake();
        $limit = ini_get('memory_limit');
        (new SendRemindMail())->handle();
        $this->assertSame($limit, ini_get('memory_limit'));
        $this->assertSame($expected, $this->jobs());
        $this->assertNotEmpty($expected);
        Bus::fake();
        (new SendRemindMail())->handle();
        $this->assertCount(5, $this->jobs());
        foreach ($this->jobs() as $job) {
            $this->assertSame('send_email', $job['queue']);
            $this->assertSame('remindExpire', $job['params']['template_name']);
        }
    }
}
