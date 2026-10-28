<?php

namespace App\Modules\System;

use App\Modules\ModuleCatalog;

use App\Services\PanelTelegramConnection;

use App\Models\Payment;
use App\Services\ClientGateway\Gateway;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use PDO;
use Throwable;

class RuntimeMonitorService
{
    private function row(string $id, string $name, string $status, string $detail, ?int $last = null): array
    {
        return compact('id', 'name', 'status', 'detail', 'last');
    }

    private function check(string $id, string $name, callable $read): array
    {
        try {
            return $read();
        } catch (Throwable $e) {
            // Exceptions may contain connection credentials or customer data.
            return $this->row($id, $name, 'error', '状态读取失败，请检查该模块日志');
        }
    }

    public function snapshot(): array
    {
        $modules = [];
        $modules[] = $this->row('runtime', '面板 / PHP', 'ok', 'Laravel ' . app()->version() . ' / PHP ' . PHP_VERSION);
        $modules[] = $this->check('database', '面板数据库', function () {
            DB::select('SELECT 1');
            return $this->row('database', '面板数据库', 'ok', '只读连接检查通过');
        });
        $last = null;
        $modules[] = $this->check('cache', '缓存', function () use (&$last) {
            $last = Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
            $last = is_numeric($last) ? (int) $last : null;
            return $this->row('cache', '缓存', 'ok', '缓存读取正常');
        });
        $schedule = $last !== null && $last >= time() - 120 && $last <= time() + 30;
        $modules[] = $this->row('scheduler', '定时调度', $schedule ? 'ok' : 'warning',
            $schedule ? '调度心跳正常' : '两分钟内未收到调度心跳', $last);
        $horizon = null;
        $modules[] = $this->check('horizon', '队列进程', function () use (&$horizon) {
            $masters = collect(app(MasterSupervisorRepository::class)->all());
            $paused = $masters->where('status', 'paused')->count();
            $horizon = $masters->isNotEmpty() && $paused === 0;
            return $this->row('horizon', '队列进程', $horizon ? 'ok' : 'warning',
                $masters->isEmpty() ? '未检测到 Horizon 主进程' : ($paused ? '存在已暂停的主进程' : 'Horizon 主进程在线'));
        });
        $modules[] = $this->check('storage', '运行目录', function () {
            $ok = is_writable(storage_path()) && is_writable(storage_path('framework')) && is_writable(storage_path('logs'));
            return $this->row('storage', '运行目录', $ok ? 'ok' : 'error', $ok ? '运行目录权限正常' : '部分运行目录不可写');
        });
        $modules[] = $this->check('gateway', '客户端网关', function () {
            if (!config('panel_modules.enabled')) return $this->row('gateway', '客户端网关', 'disabled', '模块未启用');
            $panel = app(Gateway::class)->entryPanel();
            return $this->row('gateway', '客户端网关', ($panel['status'] ?? '') === 'active' ? 'configured' : 'disabled',
                '面板内置授权；配置和授权表可读取，未测试外部入口');
        });
        $modules[] = $this->check('telegram', 'Telegram 机器人', fn() => $this->telegram());
        $modules[] = $this->check('mail', '邮件发送', function () {
            if (!config('outbound.enabled', true)) return $this->row('mail', '邮件发送', 'disabled', '对外邮件已禁止');
            $host = config('v2board.email_host') ?: config('mail.host');
            return $this->row('mail', '邮件发送', $host ? 'configured' : 'unconfigured',
                $host ? '邮件服务已配置；发送和群发队列见下方，未发测试邮件' : '尚未配置邮件服务');
        });
        $modules[] = $this->check('payment', '支付', function () {
            $enabled = Payment::where('enable', 1)->exists();
            return $this->row('payment', '支付', $enabled ? 'configured' : 'disabled',
                $enabled ? '存在已启用渠道；未向支付平台发起交易' : '没有启用的支付渠道');
        });
        $modules[] = $this->check('community', '售后群聊', function () {
            if (!config('community.enabled')) return $this->row('community', '售后群聊', 'disabled', '模块未启用');
            $ready = config('community.url') && config('community.site') && strlen((string) config('community.secret')) >= 32;
            return $this->row('community', '售后群聊', $ready ? 'configured' : 'unconfigured',
                $ready ? '群聊连接已配置；未主动连接外部服务' : '群聊连接配置不完整');
        });
        $modules[] = $this->row('ticket_ai', '工单 AI', config('v2board.ticket_ai_auto_reply_enable') ? 'configured' : 'disabled',
            config('v2board.ticket_ai_auto_reply_enable') ? '自动回复已启用；未调用模型或发送回复' : '自动回复未启用');
        foreach ([
            ['core_services', '业务服务', [\App\Modules\Core\OrderService::class, \App\Modules\Core\PaymentAttemptService::class,
                \App\Modules\Core\PlanService::class, \App\Modules\Core\TrafficAccounting::class], '订单、支付、套餐和流量服务可加载；未执行购买或扣费'],
            ['support_services', '客服服务', [\App\Modules\Support\TicketService::class, \App\Modules\Support\TicketAiService::class],
                '工单和 AI 客服服务可加载；未发送客户消息'],
            ['security_services', '鉴权与风控服务', [\App\Modules\Security\AuthService::class, \App\Modules\Security\SubscriptionRuleService::class,
                \App\Modules\Security\AiRiskService::class], '现有鉴权与订阅风控服务可加载；不代表完整安全检测通过'],
        ] as [$id, $name, $classes, $detail]) {
            $modules[] = $this->check($id, $name, function () use ($id, $name, $classes, $detail) {
                foreach ($classes as $class) {
                    if (!class_exists($class)) throw new \RuntimeException('Module service unavailable');
                }
                return $this->row($id, $name, 'configured', $detail);
            });
        }
        return ['schedule' => $schedule, 'horizon' => $horizon, 'schedule_last_runtime' => $last,
            'checked_at' => time(), 'timezone' => config('app.timezone'), 'modules' => $modules,
            'module_groups' => ModuleCatalog::groupChecks($modules),
            'tasks' => $this->tasks()];
    }

    private function telegram(): array
    {
        $id = 'telegram'; $name = 'Telegram 机器人';
        if (!config('panel_modules.enabled') || !config('outbound.enabled', true)) {
            return $this->row($id, $name, 'disabled', '机器人模块或对外消息未启用');
        }
        if (config('panel_modules.telegram.driver') !== 'local') {
            return $this->row($id, $name, 'unknown', '远程收消息进程；本站没有本地心跳');
        }
        $directory = config('panel_modules.telegram.directory');
        $path = $directory . '/bot_admin.sqlite3';
        if (!is_file($path)) return $this->row($id, $name, 'unconfigured', '本站机器人尚未配置');
        $db = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=500');
        $connection = new PanelTelegramConnection($directory, $db);
        $own = $connection->own();
        $query = $db->prepare('SELECT enabled,verify_join,verify_message FROM groups WHERE chat_id=?');
        $query->execute([$own['chat_id']]);
        $group = $query->fetch(PDO::FETCH_ASSOC);
        if (!$group) return $this->row($id, $name, 'unconfigured', '本站群组尚未接入');
        if (!$group['enabled']) return $this->row($id, $name, 'disabled', '本站群组处理已关闭');
        $file = $directory . '/receiver-status.json';
        $status = is_file($file) && filesize($file) < 262144 ? json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
        $last = is_numeric($status['updated_at'] ?? null) ? (int) $status['updated_at'] : null;
        $site = $status['sites'][config('panel_modules.site')] ?? [];
        $ok = $last && $last >= time() - 30 && $last <= time() + 30 && ($site['ready'] ?? false) === true
            && hash_equals(hash('sha256', $own['token'] . "\n" . $own['chat_id']), $site['fingerprint'] ?? '');
        $detail = $ok ? '本站收消息心跳正常' : '本站收消息心跳过期或配置未生效';
        $detail .= '；入群验证' . ($group['verify_join'] ? '开启' : '关闭') . '，发言验证' . ($group['verify_message'] ? '开启' : '关闭');
        return $this->row($id, $name, $ok ? 'ok' : 'warning', $detail, $last);
    }

    public function workload(WorkloadRepository $repository): array
    {
        $live = []; $observed = true;
        try { $live = $repository->get(); } catch (Throwable $e) { $observed = false; }
        $running = null;
        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all());
            $running = $masters->isNotEmpty() && !$masters->contains(fn($master) => $master->status === 'paused');
        } catch (Throwable $e) { /* Unknown must not be shown as healthy. */ }
        $supervisors = config('horizon.environments.' . app()->environment(), config('horizon.environments.*', []));
        $queues = [];
        foreach ($supervisors as $supervisor) {
            $connection = $supervisor['connection'] ?? 'redis';
            $names = $supervisor['queue'] ?? ['default'];
            foreach (is_array($names) ? $names : explode(',', $names) as $name) {
                $queues[$connection . ':' . $name] = ['name' => $name, 'connection' => $connection,
                    'processes' => $observed ? 0 : null, 'length' => null, 'wait' => null, 'pending' => null, 'delayed' => null, 'reserved' => null];
            }
        }
        $connections = array_values(array_unique(array_column($queues, 'connection')));
        foreach ($live as $row) {
            // Horizon names do not carry the connection; resolve against this site's configuration.
            $matches = array_keys(array_filter($queues, fn($q) => $q['name'] === $row['name']));
            if (count($matches) === 1) $queues[$matches[0]] = array_replace($queues[$matches[0]], $row);
            elseif (!$matches) $queues['observed:' . $row['name']] = $row + ['connection' => count($connections) === 1 ? $connections[0] : null];
        }
        foreach ($queues as $key => &$row) {
            $row['key'] = $key;
            $row['module'] = ModuleCatalog::queueModule($row['name']);
            $row['module_name'] = ModuleCatalog::NAMES[$row['module']];
            try {
                if (!$row['connection']) throw new \RuntimeException('Unknown connection');
                $queue = Queue::connection($row['connection']);
                $row['pending'] = $queue->pendingSize($row['name']);
                $row['delayed'] = $queue->delayedSize($row['name']);
                $row['reserved'] = $queue->reservedSize($row['name']);
                $row['length'] = $row['pending'] + $row['delayed'] + $row['reserved'];
                $row['status'] = !$observed || $running === null ? 'unknown' : ($running && ($row['processes'] ?? 0) > 0 ? 'ok' : 'warning');
            } catch (Throwable $e) {
                $row['status'] = 'unknown';
            }
        }
        unset($row);
        return ModuleCatalog::sortRows(array_values($queues));
    }

    public function liveSignals(): array
    {
        $last = Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
        $last = is_numeric($last) ? (int) $last : null;
        return ['scheduler' => ['status' => $last && $last >= time() - 120 && $last <= time() + 30 ? 'ok' : 'warning', 'last' => $last],
            'telegram' => $this->check('telegram', 'Telegram', fn() => $this->telegram()), 'tasks' => $this->tasks()];
    }

    private function tasks(): array
    {
        // Mirror Kernel without resolving Schedule: doing so writes the scheduler heartbeat.
        $tasks = [
            ['name' => '在线人数 / 流量更新', 'command' => 'traffic:update', 'frequency' => '每分钟'],
            ['name' => '订单处理', 'command' => 'check:order', 'frequency' => '每分钟'],
            ['name' => '工单检查', 'command' => 'check:ticket', 'frequency' => '每分钟'],
            ['name' => '佣金处理', 'command' => 'check:commission', 'frequency' => '每 15 分钟'],
            ['name' => '队列统计快照', 'command' => 'horizon:snapshot', 'frequency' => '每 5 分钟'],
            ['name' => '流量重置', 'command' => 'reset:traffic', 'frequency' => '每日 00:00'],
            ['name' => '日志清理', 'command' => 'reset:log', 'frequency' => '每日 05:00'],
            ['name' => '数据统计', 'command' => 'v2board:statistics', 'frequency' => '每日 00:10'],
            ['name' => '到期提醒', 'command' => 'send:remindMail', 'frequency' => '每日 11:30'],
            ['name' => '续费检查', 'command' => 'check:renewal', 'frequency' => '每日 22:30'],
        ];
        foreach ($tasks as &$task) {
            $task['module'] = ModuleCatalog::taskModule($task['command']);
            $task['module_name'] = ModuleCatalog::NAMES[$task['module']];
        }
        unset($task);
        return ModuleCatalog::sortRows($tasks);
    }
}
