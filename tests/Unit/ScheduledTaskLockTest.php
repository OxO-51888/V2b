<?php

namespace Tests\Unit;

use App\Console\Commands\CheckOrder;
use App\Console\Commands\TrafficUpdate;
use App\Services\ScheduledTaskLock;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ScheduledTaskLockTest extends TestCase
{
    private $directory;
    private $processes = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/schedule-lock-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        foreach (glob($this->directory . '/*') as $file) unlink($file);
        rmdir($this->directory);
        Container::setInstance(null);
    }

    private function holder(string $command = 'traffic:update'): array
    {
        $source = (new \ReflectionClass(ScheduledTaskLock::class))->getFileName();
        $code = 'require ' . var_export($source, true) . '; (new App\\Services\\ScheduledTaskLock('
            . var_export($this->directory, true) . '))->run(' . var_export($command, true)
            . ', function () { echo "locked\\n"; fflush(STDOUT); fgets(STDIN); });';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->processes[] = $process;
        stream_set_timeout($pipes[1], 5);
        $this->assertSame("locked\n", fgets($pipes[1]));
        return [$process, $pipes];
    }

    public function testNormalCompletionKeepsFileButReleasesLock(): void
    {
        $lock = new ScheduledTaskLock($this->directory);
        $this->assertSame(42, $lock->run('traffic:update', fn() => 42));
        $this->assertFileExists($this->directory . '/schedule-traffic-update.lock');
        $this->assertSame(43, $lock->run('traffic:update', fn() => 43));
    }

    public function testExceptionReleasesLock(): void
    {
        $lock = new ScheduledTaskLock($this->directory);
        try {
            $lock->run('traffic:update', function () { throw new RuntimeException('fixture'); });
            $this->fail('Exception must propagate');
        } catch (RuntimeException $error) {
            $this->assertSame('fixture', $error->getMessage());
        }
        $this->assertTrue($lock->run('traffic:update', fn() => true));
    }

    public function testLiveProcessIsNeverOverlappedEvenWithOldFileTimestamp(): void
    {
        $this->holder();
        touch($this->directory . '/schedule-traffic-update.lock', time() - 172800);
        $called = false;
        $result = (new ScheduledTaskLock($this->directory))->run('traffic:update', function () use (&$called) { $called = true; });
        $this->assertSame(0, $result);
        $this->assertFalse($called);
    }

    public function testKilledProcessReleasesLockWithoutDeletingFileOrWaitingForTtl(): void
    {
        [$process, $pipes] = $this->holder();
        $this->assertTrue(proc_terminate($process, 9));
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        $this->assertFileExists($this->directory . '/schedule-traffic-update.lock');
        $this->assertTrue((new ScheduledTaskLock($this->directory))->run('traffic:update', fn() => true));
    }

    public function testDifferentTasksAndSitesRemainIndependent(): void
    {
        $this->holder();
        $this->assertTrue((new ScheduledTaskLock($this->directory))->run('check:order', fn() => true));
        $other = $this->directory . '-other';
        mkdir($other, 0700);
        try {
            $this->assertTrue((new ScheduledTaskLock($other))->run('traffic:update', fn() => true));
        } finally {
            unlink($other . '/schedule-traffic-update.lock');
            rmdir($other);
        }
    }

    public function testLockFailureNeverRunsUnprotectedTask(): void
    {
        $called = false;
        try {
            (new ScheduledTaskLock($this->directory . '/missing'))->run('traffic:update', function () use (&$called) { $called = true; });
            $this->fail('Must fail closed');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Cannot open', $error->getMessage());
        }
        $this->assertFalse($called);
    }

    public function testTrafficAndOrderCommandsCannotBypassLockWhenRunDirectly(): void
    {
        $app = new Container;
        Container::setInstance($app);
        $app->instance(ScheduledTaskLock::class, new ScheduledTaskLock($this->directory));
        $traffic = new class extends TrafficUpdate {
            public $calls = 0;
            protected function runTask() { return ++$this->calls; }
        };
        $order = new class extends CheckOrder {
            public $calls = 0;
            protected function runTask() { return ++$this->calls; }
        };
        $this->assertSame(1, $traffic->handle());
        $this->assertSame(1, $order->handle());
        $this->holder();
        $this->holder('check:order');
        $this->assertSame(0, $traffic->handle());
        $this->assertSame(0, $order->handle());
        $this->assertSame(1, $traffic->calls);
        $this->assertSame(1, $order->calls);
    }
}
