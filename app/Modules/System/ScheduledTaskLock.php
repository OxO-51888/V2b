<?php

namespace App\Modules\System;

use InvalidArgumentException;
use RuntimeException;

class ScheduledTaskLock
{
    private $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('framework');
    }

    public function run(string $command, callable $task, int $waitSeconds = 0)
    {
        if (!in_array($command, ['traffic:update', 'check:order', 'reset:traffic'], true)) {
            throw new InvalidArgumentException('Unsupported scheduled task');
        }
        $path = $this->directory . '/schedule-' . str_replace(':', '-', $command) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open scheduled task lock: ' . $path);
        }
        try {
            $deadline = microtime(true) + max(0, $waitSeconds);
            while (true) {
                $wouldBlock = 0;
                if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) break;
                if (!$wouldBlock) throw new RuntimeException('Cannot acquire scheduled task lock: ' . $path);
                if ($waitSeconds === 0) return 0;
                if (microtime(true) >= $deadline) throw new RuntimeException('Timed out waiting for scheduled task: ' . $command);
                usleep(100000);
            }
            // Keep the inode in place. The OS releases the lock even after SIGKILL or reboot.
            try {
                return $task();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
