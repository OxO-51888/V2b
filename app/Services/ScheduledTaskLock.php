<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class ScheduledTaskLock
{
    private $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('framework');
    }

    public function run(string $command, callable $task)
    {
        if (!in_array($command, ['traffic:update', 'check:order'], true)) {
            throw new InvalidArgumentException('Unsupported scheduled task');
        }
        $path = $this->directory . '/schedule-' . str_replace(':', '-', $command) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open scheduled task lock: ' . $path);
        }
        try {
            $wouldBlock = 0;
            if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock) return 0;
                throw new RuntimeException('Cannot acquire scheduled task lock: ' . $path);
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
