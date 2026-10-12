<?php

namespace Tests\Unit;

use App\Console\Commands\ResetTraffic;
use App\Jobs\StatUserJob;
use App\Services\AccountingReceipt;
use App\Services\TrafficAccounting;
use App\Services\TrafficBuffer;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountingDatabase;

class AccountingSafetyTest extends TestCase
{
    private $app;
    private $db;

    protected function setUp(): void
    {
        [$this->app, $this->db] = AccountingDatabase::boot();
        AccountingDatabase::schema($this->db);
        Redis::swap(new class {
            public $values = [];
            public function setex($key, $ttl, $value) { $this->values[$key] = $value; }
            public function del($key) { unset($this->values[$key]); }
        });
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
    }

    private function batch($id = 1, $u = 10, $d = 20): array
    {
        return ['id' => bin2hex(random_bytes(16)), 'rows' => [[(string) $id, (string) $u, (string) $d]]];
    }

    private function service($batch): array
    {
        $buffer = new class($batch) extends TrafficBuffer {
            public $pending;
            public $failAcknowledgement = false;
            public function __construct($batch) { $this->pending = $batch; }
            public function claim(): ?array { return $this->pending; }
            public function hasBufferedTraffic(): bool { return false; }
            public function acknowledge(string $id): void {
                if ($this->failAcknowledgement) throw new \RuntimeException('Simulated ACK loss');
                $this->pending = null;
            }
        };
        return [new TrafficAccounting($buffer, new AccountingReceipt()), $buffer];
    }

    private function usage(): array
    {
        $row = $this->db->table('v2_user')->where('id', 1)->first();
        return [(int) $row->u, (int) $row->d];
    }

    public function testAcknowledgementFailureDoesNotChargeTwice(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        [$service, $buffer] = $this->service($this->batch());
        $buffer->failAcknowledgement = true;
        try { $service->drain(); $this->fail('ACK failure must propagate'); } catch (\RuntimeException $e) {}
        $this->assertSame([10, 20], $this->usage());
        $this->assertNotNull($buffer->pending);
        $buffer->failAcknowledgement = false;
        $service->drain();
        $this->assertSame([10, 20], $this->usage());
        $this->assertNull($buffer->pending);
        $this->assertSame(1, $this->db->table('v2_accounting_receipt')->count());
    }

    public function testDatabaseFailureRetainsBatchAndRollsBackReceipt(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        [$service, $buffer] = $this->service($this->batch());
        $this->db->unprepared("CREATE TRIGGER reject_usage BEFORE UPDATE ON v2_user BEGIN SELECT RAISE(ABORT, 'simulated write failure'); END");
        try { $service->drain(); $this->fail('Write failure must propagate'); } catch (\Illuminate\Database\QueryException $e) {}
        $this->assertNotNull($buffer->pending);
        $this->assertSame([0, 0], $this->usage());
        $this->assertSame(0, $this->db->table('v2_accounting_receipt')->count());
        $this->db->unprepared('DROP TRIGGER reject_usage');
        $service->drain();
        $this->assertSame([10, 20], $this->usage());
    }

    public function testFreshCountersAreUsedAfterACompletedReset(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1, 'u' => 100, 'd' => 200]);
        [$service] = $this->service($this->batch());
        $injected = false;
        $this->db->listen(function ($query) use (&$injected) {
            if (!$injected && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'v2_user')) {
                $injected = true;
                $this->db->table('v2_user')->where('id', 1)->update(['u' => 0, 'd' => 0]);
            }
        });
        $service->drain();
        $this->assertTrue($injected);
        $this->assertSame([10, 20], $this->usage());
    }

    public function testUploadOnlyRowsAndDeletedUsersDoNotDropOtherUsers(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        $batch = $this->batch(1, 8, 0);
        $batch['rows'][] = ['999', '1', '2'];
        [$service, $buffer] = $this->service($batch);
        $service->drain();
        $this->assertSame([8, 0], $this->usage());
        $this->assertNull($buffer->pending);
    }

    public function testMissingSchemaDoesNotClaimRedisData(): void
    {
        $this->db->getSchemaBuilder()->drop('v2_accounting_receipt');
        $buffer = new class extends TrafficBuffer {
            public $called = false;
            public function claim(): ?array { $this->called = true; return null; }
        };
        try { (new TrafficAccounting($buffer, new AccountingReceipt()))->drain(); $this->fail('Missing schema'); }
        catch (\Illuminate\Database\QueryException $e) {}
        $this->assertFalse($buffer->called);
    }

    public function testEmptyScanPageDoesNotDelayRemainingCountersUntilNextMinute(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        $buffer = new class($this->batch()) extends TrafficBuffer {
            private $batch;
            public $calls = 0;
            public function __construct($batch) { $this->batch = $batch; }
            public function claim(): ?array { return ++$this->calls === 1 ? null : $this->batch; }
            public function hasBufferedTraffic(): bool { return $this->batch !== null; }
            public function acknowledge(string $id): void { $this->batch = null; }
        };
        (new TrafficAccounting($buffer, new AccountingReceipt()))->drain();
        $this->assertSame([10, 20], $this->usage());
        $this->assertSame(3, $buffer->calls);
    }

    public function testReceiptRejectsChangedPayload(): void
    {
        $this->db->table('v2_user')->insert(['id' => 1]);
        $batch = $this->batch();
        [$service] = $this->service($batch);
        $service->apply($batch);
        $batch['rows'][0][1] = '999';
        try { $service->apply($batch); $this->fail('Changed payload must be rejected'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('mismatch', $e->getMessage()); }
        $this->assertSame([10, 20], $this->usage());
    }

    public function testStatisticBatchesAccumulateAndRetriesAreIdempotent(): void
    {
        $first = new StatUserJob([1 => [10, 20]], ['rate' => 1], 'hysteria');
        $second = new StatUserJob([1 => [7, 8]], ['rate' => 1], 'hysteria');
        $first->handle();
        $second->handle();
        unserialize(serialize($first))->handle();
        $row = $this->db->table('v2_stat_user')->first();
        $this->assertSame(17, (int) $row->u);
        $this->assertSame(28, (int) $row->d);
        $this->assertSame(1, $this->db->table('v2_stat_user')->count());
    }

    public function testStatisticsKeepOriginalDayAcrossMidnight(): void
    {
        $job = new StatUserJob([1 => [10, 20]], ['rate' => 1], 'hysteria');
        $property = new \ReflectionProperty($job, 'reportedAt');
        $property->setAccessible(true);
        $yesterday = strtotime('yesterday 23:59:59');
        $property->setValue($job, $yesterday);
        $job->handle();
        $this->assertSame(strtotime('yesterday'), (int) $this->db->table('v2_stat_user')->value('record_at'));
    }

    public function testStatisticsRetryCompletedChunksWithoutDuplicating(): void
    {
        $data = array_fill(1, 501, [10, 20]);
        $job = new StatUserJob($data, ['rate' => 1], 'hysteria');
        $job->handle();
        $job->handle();
        $this->assertSame(501, $this->db->table('v2_stat_user')->count());
        $this->assertSame(5010, (int) $this->db->table('v2_stat_user')->sum('u'));
        $this->assertSame(3, $this->db->table('v2_accounting_receipt')->count());
    }

    public function testStatisticsRetryOnlyTheFailedAndRemainingChunks(): void
    {
        $job = new StatUserJob(array_fill(1, 501, [10, 20]), ['rate' => 1], 'hysteria');
        $this->db->unprepared("CREATE TRIGGER reject_second_chunk BEFORE INSERT ON v2_stat_user WHEN NEW.user_id > 250 BEGIN SELECT RAISE(ABORT, 'stat failure'); END");
        try { $job->handle(); $this->fail('Stat failure expected'); }
        catch (\Illuminate\Database\QueryException $e) {}
        $this->assertSame(250, $this->db->table('v2_stat_user')->count());
        $this->assertSame(1, $this->db->table('v2_accounting_receipt')->count());
        $this->db->unprepared('DROP TRIGGER reject_second_chunk');
        unserialize(serialize($job))->handle();
        $this->assertSame(501, $this->db->table('v2_stat_user')->count());
        $this->assertSame(5010, (int) $this->db->table('v2_stat_user')->sum('u'));
    }

    private function resetAt(int $time): void
    {
        $command = new class extends ResetTraffic {
            public function at($time) { return $this->runTask($time); }
        };
        $command->at($time);
    }

    public function testDailyResetIsIdempotentAndPreservesTotalQuota(): void
    {
        $this->db->table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 1]);
        $this->db->table('v2_user')->insert(['id' => 1, 'plan_id' => 1, 'expired_at' => strtotime('2027-01-12'),
            'u' => 100, 'd' => 200, 'transfer_enable' => 123456]);
        $this->resetAt(strtotime('2026-10-12'));
        $this->assertSame([0, 0], $this->usage());
        $this->db->table('v2_user')->where('id', 1)->update(['u' => 7, 'd' => 8]);
        $this->resetAt(strtotime('2026-10-12 12:00'));
        $this->assertSame([7, 8], $this->usage());
        $this->assertSame(123456, (int) $this->db->table('v2_user')->value('transfer_enable'));
        $this->assertSame([], Redis::getFacadeRoot()->values);
    }

    /** @dataProvider resetCases */
    public function testResetMethodsKeepExistingEligibility($method, $default, $now, $expires, $expected): void
    {
        $this->app['config']->set('v2board.reset_traffic_method', $default);
        $this->db->table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => $method]);
        $this->db->table('v2_user')->insert(['id' => 1, 'plan_id' => 1,
            'expired_at' => $expires === null ? null : strtotime($expires), 'u' => 100, 'd' => 200]);
        $this->resetAt(strtotime($now));
        $this->assertSame($expected ? [0, 0] : [100, 200], $this->usage());
    }

    public static function resetCases(): array
    {
        return [
            [0, 2, '2026-10-01', '2026-12-10', true],
            [0, 2, '2026-10-02', '2026-12-10', false],
            [1, 2, '2026-02-28', '2026-05-31', true],
            [1, 2, '2026-10-12', '2026-10-12 12:00', false],
            [1, 2, '2026-10-12', '2027-01-13', false],
            [2, 0, '2026-10-01', '2027-01-01', false],
            [3, 2, '2027-01-01', '2027-05-10', true],
            [null, 3, '2026-10-12', '2027-10-12', false],
            [4, 2, '2026-10-12', '2027-10-12', true],
            [null, 1, '2026-10-12', '2027-01-12', true],
            [0, 0, '2026-10-01', null, false],
            [0, 0, '2026-10-01', '2026-09-01', false],
        ];
    }

    public function testResetFailureRollsBackMarkersAndAlwaysReleasesLegacyFlag(): void
    {
        $this->db->table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 0]);
        $this->db->table('v2_user')->insert(['id' => 1, 'plan_id' => 1, 'expired_at' => strtotime('2027-01-01'), 'u' => 100]);
        $this->db->unprepared("CREATE TRIGGER reject_reset BEFORE UPDATE ON v2_user BEGIN SELECT RAISE(ABORT, 'reset failure'); END");
        try { $this->resetAt(strtotime('2026-10-01')); $this->fail('Reset failure expected'); }
        catch (\Illuminate\Database\QueryException $e) {}
        $this->assertSame([100, 0], $this->usage());
        $this->assertSame(0, $this->db->table('v2_accounting_receipt')->count());
        $this->assertSame([], Redis::getFacadeRoot()->values);
    }

    public function testResetResumesAfterPartialFailureWithoutClearingNewUsage(): void
    {
        $this->db->table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 0]);
        for ($id = 1; $id <= 201; $id++) $this->db->table('v2_user')->insert(['id' => $id, 'plan_id' => 1,
            'expired_at' => strtotime('2027-01-01'), 'u' => 100]);
        $this->db->unprepared("CREATE TRIGGER reject_last_reset BEFORE UPDATE ON v2_user WHEN NEW.id = 201 BEGIN SELECT RAISE(ABORT, 'reset failure'); END");
        try { $this->resetAt(strtotime('2026-10-01')); $this->fail('Reset failure expected'); }
        catch (\Illuminate\Database\QueryException $e) {}
        $this->db->unprepared('DROP TRIGGER reject_last_reset');
        $this->db->table('v2_user')->where('id', 1)->update(['u' => 7, 'd' => 8]);
        $this->resetAt(strtotime('2026-10-01'));
        $this->assertSame([7, 8], $this->usage());
        $this->assertSame(0, (int) $this->db->table('v2_user')->where('id', 201)->value('u'));
        $this->assertSame(202, $this->db->table('v2_accounting_receipt')->count());
    }

    public function testHistoricMissedResetDoesNotEraseCurrentUsage(): void
    {
        $this->db->table('v2_accounting_receipt')->insert(['id' => 'reset:day:2026-10-10', 'digest' => hash('sha256', 'fixture'), 'created_at' => time()]);
        $this->db->table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 1]);
        $this->db->table('v2_user')->insert(['id' => 1, 'plan_id' => 1, 'expired_at' => strtotime('2027-01-11'), 'u' => 100]);
        $this->resetAt(strtotime('2026-10-12'));
        $this->assertSame([100, 0], $this->usage());
    }

    public function testPendingTrafficReceiptSurvivesCleanup(): void
    {
        $this->db->getSchemaBuilder()->create('v2_stat_server', function ($t) { $t->increments('id'); $t->integer('record_at'); });
        $this->db->getSchemaBuilder()->create('v2_subscription_rule_log', function ($t) { $t->increments('id'); });
        $pendingId = str_repeat('a', 32);
        Redis::swap(new class($pendingId) {
            private $id;
            public function __construct($id) { $this->id = $id; }
            public function get($key) { return json_encode(['id' => $this->id, 'rows' => [['1', '10', '20']]]); }
        });
        foreach (['traffic:' . $pendingId, 'traffic:finished', 'stat:finished', 'reset:user:1:old'] as $key) {
            $this->db->table('v2_accounting_receipt')->insert(['id' => $key, 'digest' => hash('sha256', $key), 'created_at' => strtotime('-4 month')]);
        }
        $this->db->table('v2_accounting_receipt')->insert(['id' => 'traffic:recent', 'digest' => hash('sha256', 'recent'), 'created_at' => time()]);
        (new \App\Console\Commands\ResetLog())->handle();
        $this->assertSame(2, $this->db->table('v2_accounting_receipt')->count());
        $this->assertTrue($this->db->table('v2_accounting_receipt')->where('id', 'traffic:' . $pendingId)->exists());
    }

    public function testOldStatisticsOutsideRetentionCannotBeRecreatedByManualRetry(): void
    {
        $job = new StatUserJob([1 => [10, 20]], ['rate' => 1], 'hysteria');
        $property = new \ReflectionProperty($job, 'reportedAt');
        $property->setAccessible(true);
        $property->setValue($job, strtotime('-4 month'));
        $job->handle();
        $this->assertSame(0, $this->db->table('v2_stat_user')->count());
        $this->assertSame(0, $this->db->table('v2_accounting_receipt')->count());
    }
}
