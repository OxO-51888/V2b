<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class ConfigurationSaveService
{
    public function save(array $configuration): void
    {
        $source = base_path('config/v2board.php');
        $cache = app()->getCachedConfigPath();
        $lock = @fopen(dirname($source) . '/.config-save.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) fclose($lock);
            abort(500, '暂时无法保存设置，请重试');
        }

        $originals = $contents = $values = $candidates = [];
        $committing = false;
        try {
            $originals[$source] = $this->read($source);
            $values[$source] = $configuration;
            if (is_file($cache)) {
                $originals[$cache] = $this->read($cache);
                // A unique filename bypasses stale OPcache entries without resetting other sites.
                $snapshot = $this->candidate($cache, $originals[$cache]);
                try {
                    $cached = $this->load($snapshot);
                } finally {
                    $this->removeCandidate($snapshot);
                }
                if (($cached['v2board'] ?? []) !== config('v2board', [])) {
                    abort(409, '设置已被更新，请刷新后重试');
                }
                $cached['v2board'] = $configuration;
                $values[$cache] = $cached;
            } elseif ($originals[$source] !== null) {
                $snapshot = $this->candidate($source, $originals[$source]);
                try {
                    if ($this->load($snapshot) !== config('v2board', [])) {
                        abort(409, '设置已被更新，请刷新后重试');
                    }
                } finally {
                    $this->removeCandidate($snapshot);
                }
            }

            foreach ($values as $path => $value) {
                $contents[$path] = '<?php return ' . var_export($value, true) . ';' . PHP_EOL;
                $candidates[$path] = $this->candidate($path, $contents[$path]);
                if ($this->load($candidates[$path]) !== $value) {
                    throw new RuntimeException('Configuration serialization failed');
                }
            }
            foreach ($originals as $path => $original) {
                if ($this->read($path) !== $original) {
                    abort(409, '设置已被更新，请刷新后重试');
                }
            }

            $committing = true;
            foreach ($candidates as $path => $candidate) {
                if ($this->read($path) !== $originals[$path]) {
                    throw new RuntimeException('Configuration changed during commit');
                }
                $this->replace($candidate, $path);
                unset($candidates[$path]);
                $this->invalidate($path);
            }
            foreach ($values as $path => $value) {
                if ($this->read($path) !== $contents[$path] || $this->load($path) !== $value) {
                    throw new RuntimeException('Saved configuration did not become effective');
                }
            }
            config(['v2board' => $configuration]);
        } catch (Throwable $error) {
            $restored = true;
            if ($committing) {
                foreach (array_reverse($contents, true) as $path => $written) {
                    try {
                        $current = $this->read($path);
                        if ($current === $originals[$path]) continue;
                        if ($current !== $written) {
                            $restored = false;
                            continue;
                        }
                        if ($originals[$path] === null) {
                            if (!@unlink($path)) throw new RuntimeException('Unable to restore missing file');
                        } else {
                            $restore = $this->candidate($path, $originals[$path]);
                            try {
                                $this->replace($restore, $path);
                            } finally {
                                $this->removeCandidate($restore);
                            }
                        }
                        $this->invalidate($path);
                    } catch (Throwable $rollbackError) {
                        $restored = false;
                    }
                }
            }
            if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException && !$committing) {
                throw $error;
            }
            if (app()->bound('log')) {
                app('log')->warning('Configuration save failed', [
                    'reason' => $error->getMessage(), 'rollback_complete' => $restored,
                ]);
            }
            abort(500, $restored ? '保存失败，原设置未改变，请重试' : '保存失败，请刷新并检查当前设置');
        } finally {
            foreach ($candidates as $candidate) $this->removeCandidate($candidate);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function read(string $path): ?string
    {
        if (!is_file($path)) return null;
        $contents = @file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Unable to read configuration');
        return $contents;
    }

    protected function candidate(string $path, string $contents): string
    {
        $temporary = @tempnam(dirname($path), '.config-save-');
        if ($temporary === false) throw new RuntimeException('Unable to prepare configuration');
        try {
            if (realpath(dirname($temporary)) !== realpath(dirname($path))) {
                throw new RuntimeException('Configuration directory is not writable');
            }
            if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException('Unable to write configuration');
            }
            $mode = is_file($path) ? fileperms($path) & 0777 : 0640;
            if (!@chmod($temporary, $mode)) throw new RuntimeException('Unable to preserve configuration permissions');
            if (PHP_OS_FAMILY !== 'Windows' && is_file($path)) {
                if (fileowner($temporary) !== fileowner($path) && !@chown($temporary, fileowner($path))) {
                    throw new RuntimeException('Unable to preserve configuration owner');
                }
                if (filegroup($temporary) !== filegroup($path) && !@chgrp($temporary, filegroup($path))) {
                    throw new RuntimeException('Unable to preserve configuration group');
                }
            }
            return $temporary;
        } catch (Throwable $error) {
            $this->removeCandidate($temporary);
            throw $error;
        }
    }

    protected function load(string $path): array
    {
        $configuration = (static function ($file) { return require $file; })($path);
        if (!is_array($configuration)) throw new RuntimeException('Invalid configuration');
        return $configuration;
    }

    protected function replace(string $candidate, string $path): void
    {
        if (!@rename($candidate, $path)) throw new RuntimeException('Unable to replace configuration');
        clearstatcache(true, $path);
    }

    protected function invalidate(string $path): void
    {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        // false is normal when OPcache is disabled; the actual values are verified after writing.
        clearstatcache(true, $path);
    }

    protected function removeCandidate(string $path): void
    {
        if (is_file($path)) {
            $this->invalidate($path);
            @unlink($path);
        }
    }
}
