<?php

namespace App\Modules\System;

use App\Modules\ModuleCatalog;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

class RuntimeTelemetry
{
    public const COMMANDS = ['traffic:update', 'check:order', 'check:ticket', 'check:commission',
        'horizon:snapshot', 'reset:traffic', 'reset:log', 'v2board:statistics', 'send:remindMail', 'check:renewal'];
    private $redis;
    private array $runs = [];
    private bool $unavailable = false;

    protected function connection()
    {
        if ($this->unavailable) throw new \RuntimeException('Telemetry unavailable');
        if ($this->redis) return $this->redis;
        // A separate bounded connection must not change business Redis settings.
        $config = (new ConfigurationUrlParser)->parseConfiguration(config('database.redis.cache', []));
        unset($config['options']);
        $config = array_replace($config, ['timeout' => .03, 'read_timeout' => .03, 'retry_interval' => 0,
            'max_retries' => 0, 'persistent' => false, 'prefix' => 'panel-runtime:v1:' . substr(hash('sha256', base_path()), 0, 20) . ':']);
        return $this->redis = (new PhpRedisConnector)->connect($config, [])->client();
    }

    public static function httpModule(string $action, string $path, int $status): ?string
    {
        if (str_contains($action, 'SystemController@') || in_array($path, ['health', 'api/public-key'], true)) return null;
        if (!str_starts_with($path, 'api/')) return null;
        if (preg_match('/PanelGateway|ClientGateway/', $action)) return 'client_gateway';
        if (preg_match('/Telegram|PanelModuleController@(?:telegram|configureTelegram)/i', $action)) return 'telegram';
        if (preg_match('/PanelModuleController@gateway/i', $action)) return 'client_gateway';
        if (preg_match('/Ticket|Community/', $action)) return 'support';
        if (preg_match('/AuthController|Passport|SubscriptionRule/', $action) || in_array($status, [401, 403, 429], true)) return 'security';
        if (preg_match('/ConfigController|NoticeController|PanelModuleController/', $action)) return 'system';
        return str_contains($action, 'Controller@') ? 'core' : null;
    }

    public function record(string $module, int $status, float $durationMs, ?int $now = null): void
    {
        if (!isset(ModuleCatalog::NAMES[$module])) return;
        try {
            $now ??= time();
            $duration = (int) max(0, min(3600000, $durationMs));
            $kind = $status >= 500 ? 'errors' : ($status >= 400 ? 'rejected' : 'success');
            $this->connection()->eval(<<<'LUA'
redis.call('HINCRBY', KEYS[1], 'total', 1)
redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
redis.call('HINCRBY', KEYS[1], 'duration', ARGV[2])
redis.call('EXPIRE', KEYS[1], 420)
local previous = tonumber(redis.call('HGET', KEYS[2], 'at') or '0')
if tonumber(ARGV[3]) >= previous then
  redis.call('HSET', KEYS[2], 'at', ARGV[3], 'kind', ARGV[1])
end
redis.call('EXPIRE', KEYS[2], 93600)
return 1
LUA, ['http:' . $module . ':' . intdiv($now, 60), 'last:' . $module, $kind, $duration, $now], 2);
        } catch (Throwable $e) { $this->unavailable = true; }
    }

    public function commandStarted(string $command): void
    {
        if (!in_array($command, self::COMMANDS, true)) return;
        try {
            $run = bin2hex(random_bytes(8));
            $this->runs[$command] = [$run, hrtime(true)];
            $this->connection()->eval(<<<'LUA'
redis.call('HSET', KEYS[1], 'run', ARGV[1], 'started_at', ARGV[2], 'state', 'running')
redis.call('EXPIRE', KEYS[1], 93600)
return 1
LUA, ['task:' . $command, $run, time()], 1);
        } catch (Throwable $e) { $this->unavailable = true; }
    }

    public function commandFinished(string $command, int $exitCode): void
    {
        if (!isset($this->runs[$command])) return;
        [$run, $started] = $this->runs[$command];
        unset($this->runs[$command]);
        try {
            $this->connection()->eval(<<<'LUA'
if redis.call('HGET', KEYS[1], 'run') ~= ARGV[1] then return 0 end
redis.call('HSET', KEYS[1], 'finished_at', ARGV[2], 'duration_ms', ARGV[3], 'exit_code', ARGV[4], 'state', ARGV[5])
redis.call('EXPIRE', KEYS[1], 93600)
return 1
LUA, ['task:' . $command, $run, time(), (int) ((hrtime(true) - $started) / 1000000), $exitCode, $exitCode === 0 ? 'ok' : 'error'], 1);
        } catch (Throwable $e) { $this->unavailable = true; }
    }

    public function snapshot(?int $now = null): array
    {
        $now ??= time();
        $redis = $this->connection();
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach (ModuleCatalog::NAMES as $id => $name) {
            // Five whole minute buckets plus the current partial minute.
            for ($i = 5; $i >= 0; $i--) $pipe->hGetAll('http:' . $id . ':' . (intdiv($now, 60) - $i));
            $pipe->hGetAll('last:' . $id);
        }
        foreach (self::COMMANDS as $command) $pipe->hGetAll('task:' . $command);
        $values = $pipe->exec();
        if (!is_array($values) || count($values) !== 52) throw new \RuntimeException('Incomplete telemetry');
        $offset = 0; $modules = []; $tasks = [];
        foreach (ModuleCatalog::NAMES as $id => $name) {
            $sum = ['total' => 0, 'errors' => 0, 'rejected' => 0, 'duration' => 0];
            for ($i = 0; $i < 6; $i++) {
                $row = $values[$offset++];
                foreach ($sum as $key => &$value) $value += (int) ($row[$key] ?? 0);
                unset($value);
            }
            $last = $values[$offset++];
            $modules[] = ['id' => $id, 'name' => $name, 'requests' => $sum['total'], 'errors' => $sum['errors'],
                'rejected' => $sum['rejected'], 'avg_ms' => $sum['total'] ? round($sum['duration'] / $sum['total'], 1) : null,
                'last_at' => isset($last['at']) ? (int) $last['at'] : null];
        }
        foreach (self::COMMANDS as $command) {
            $row = $values[$offset++];
            $state = $row['state'] ?? 'unknown';
            if ($state === 'running' && $now - (int) ($row['started_at'] ?? 0) > 900) $state = 'unknown';
            // Never return internal run IDs or unexpected Redis fields.
            $tasks[$command] = ['state' => $state, 'started_at' => isset($row['started_at']) ? (int) $row['started_at'] : null,
                'finished_at' => $state === 'running' ? null : (isset($row['finished_at']) ? (int) $row['finished_at'] : null),
                'duration_ms' => $state === 'running' ? null : (isset($row['duration_ms']) ? (int) $row['duration_ms'] : null)];
        }
        return ['sampled_at' => $now, 'window_start' => (intdiv($now, 60) - 5) * 60, 'modules' => $modules, 'tasks' => $tasks];
    }
}
