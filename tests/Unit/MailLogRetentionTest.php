<?php

namespace Tests\Unit;

use App\Modules\System\MailLogRetention;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderDatabase;

class MailLogRetentionTest extends TestCase
{
    private $db;
    private const CUTOFF = 1700000000;

    protected function setUp(): void
    {
        [, $this->db] = OrderDatabase::boot();
        $this->db->getSchemaBuilder()->create('v2_mail_log', function ($t) {
            $t->increments('id');
            $t->integer('created_at')->nullable();
            $t->text('error')->nullable();
        });
        $this->db->getSchemaBuilder()->create('failed_jobs', function ($t) {
            $t->increments('id');
            $t->text('payload');
        });
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
    }

    private function service(): MailLogRetention
    {
        return new class(self::CUTOFF) extends MailLogRetention {
            public $pauses = 0;
            public $afterBatch;
            public function __construct(private int $timestamp) {}
            protected function cutoff(): int { return $this->timestamp; }
            protected function pause(): void {
                $this->pauses++;
                if ($this->afterBatch) ($this->afterBatch)();
            }
        };
    }

    public function testDefaultPolicyUsesNinetyDaysAndSmallBatches(): void
    {
        $this->assertSame(90, MailLogRetention::KEEP_DAYS);
        $this->assertSame(500, MailLogRetention::BATCH_SIZE);
        $before = time() - 90 * 86400;
        $method = new \ReflectionMethod(MailLogRetention::class, 'cutoff');
        $cutoff = $method->invoke(new MailLogRetention());
        $this->assertGreaterThanOrEqual($before, $cutoff);
        $this->assertLessThanOrEqual(time() - 90 * 86400, $cutoff);
    }

    public function testBoundaryRecentFutureAndUnknownDatesAreRetained(): void
    {
        foreach ([self::CUTOFF-1, self::CUTOFF, self::CUTOFF+1, null, 0, -1] as $date) {
            $this->db->table('v2_mail_log')->insert(['created_at'=>$date]);
        }
        $result = $this->service()->prune();
        $this->assertSame(1, $result['deleted']);
        $this->assertSame([2,3,4,5,6], $this->db->table('v2_mail_log')->orderBy('id')->pluck('id')->all());
    }

    public function testEmptyTableAndRerunAreSafe(): void
    {
        $this->assertSame(0, $this->service()->prune()['deleted']);
        $this->db->table('v2_mail_log')->insert(['created_at'=>1]);
        $this->assertSame(1, $this->service()->prune()['deleted']);
        $this->assertSame(0, $this->service()->prune()['deleted']);
    }

    public function testMultipleBatchesDoNotSkipOldRowsAfterRecentRows(): void
    {
        $rows = [];
        for ($i=0; $i<1201; $i++) $rows[]=['created_at'=>$i%2 ? self::CUTOFF+1 : 1];
        foreach (array_chunk($rows,100) as $chunk) $this->db->table('v2_mail_log')->insert($chunk);
        $service=$this->service();
        $result=$service->prune();
        $this->assertSame(601,$result['deleted']);
        $this->assertSame(1201,$result['scanned']);
        $this->assertSame(2,$service->pauses);
        $this->assertSame(600,$this->db->table('v2_mail_log')->count());
    }

    public function testNewRowsBeyondInitialHighWaterMarkSurvive(): void
    {
        $this->db->table('v2_mail_log')->insert(array_fill(0,501,['created_at'=>1]));
        $service=$this->service();
        $service->afterBatch=function () { $this->db->table('v2_mail_log')->insert(['created_at'=>1]); };
        $result=$service->prune();
        $this->assertSame(501,$result['deleted']);
        $this->assertSame([502],$this->db->table('v2_mail_log')->pluck('id')->all());
    }

    public function testDateChangedAfterSelectionIsRecheckedBeforeDelete(): void
    {
        $this->db->table('v2_mail_log')->insert(['created_at'=>1]);
        $changed=false;
        $this->db->listen(function ($query) use (&$changed) {
            if (!$changed && str_starts_with($query->sql,'select "id", "created_at"')) {
                $changed=true;
                $this->db->table('v2_mail_log')->where('id',1)->update(['created_at'=>self::CUTOFF]);
            }
        });
        $this->assertSame(0,$this->service()->prune()['deleted']);
        $this->assertTrue($changed);
        $this->assertSame(1,$this->db->table('v2_mail_log')->count());
    }

    public function testFailureCanResumeWithoutTouchingFailedJobs(): void
    {
        $this->db->table('failed_jobs')->insert(['payload'=>'order must remain']);
        $this->db->table('v2_mail_log')->insert(array_fill(0,501,['created_at'=>1]));
        $this->db->unprepared("CREATE TRIGGER reject_mail_delete BEFORE DELETE ON v2_mail_log WHEN OLD.id=501 BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try { $this->service()->prune(); $this->fail('Expected failure'); }
        catch (\Illuminate\Database\QueryException $e) {}
        $this->assertSame(1,$this->db->table('v2_mail_log')->count());
        $this->db->unprepared('DROP TRIGGER reject_mail_delete');
        $this->assertSame(1,$this->service()->prune()['deleted']);
        $this->assertSame('order must remain',$this->db->table('failed_jobs')->value('payload'));
    }
}
