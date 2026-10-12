<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\AccountingReceipt;
use App\Services\ScheduledTaskLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ResetTraffic extends Command
{
    protected $signature = 'reset:traffic';
    protected $description = '流量清空';

    public function handle()
    {
        $locks = app(ScheduledTaskLock::class);
        return $locks->run('reset:traffic', function () use ($locks) {
            return $locks->run('traffic:update', function () { return $this->runTask(time()); }, 60);
        });
    }

    protected function runTask(int $now): int
    {
        $receipts = app(AccountingReceipt::class);
        $receipts->assertReady();
        $day = date('Y-m-d', $now);
        $dayKey = 'reset:day:' . $day;
        if (DB::table('v2_accounting_receipt')->where('id', $dayKey)->exists()) return 0;
        $previous = DB::table('v2_accounting_receipt')->where('id', 'like', 'reset:day:%')
            ->orderByDesc('id')->value('id');
        if ($previous && substr($previous, 10) < date('Y-m-d', strtotime('-1 day', $now))) {
            // Never erase newer usage to compensate for an unobserved historic reset.
            Log::warning('Scheduled traffic reset missed a day; historical usage requires review', ['last_day' => substr($previous, 10)]);
        }

        Redis::setex('traffic_reset_lock', 300, 1);
        try {
            User::whereNotNull('expired_at')->where('expired_at', '>', $now)->select('id')
                ->chunkById(200, function ($page) use ($now, $day, $receipts) {
                    Redis::setex('traffic_reset_lock', 300, 1);
                    DB::transaction(function () use ($page, $now, $day, $receipts) {
                        $users = User::whereIn('id', $page->pluck('id'))->orderBy('id')->lockForUpdate()
                            ->get(['id', 'plan_id', 'expired_at']);
                        $plans = Plan::whereIn('id', $users->pluck('plan_id')->unique())
                            ->get(['id', 'reset_traffic_method'])->keyBy('id');
                        $ids = [];
                        foreach ($users as $user) {
                            if ($user->expired_at === null || $user->expired_at <= $now || !isset($plans[$user->plan_id])) continue;
                            $method = $plans[$user->plan_id]->reset_traffic_method;
                            $method = $method === null ? (int) config('v2board.reset_traffic_method', 0) : (int) $method;
                            if (!$this->due($method, (int) $user->expired_at, $now)) continue;
                            if ($receipts->claim('reset:user:' . $user->id . ':' . $day, hash('sha256', 'scheduled-reset'))) {
                                $ids[] = $user->id;
                            }
                        }
                        if ($ids) User::whereIn('id', $ids)->update(['u' => 0, 'd' => 0]);
                    }, 3);
                });
            $receipts->once($dayKey, hash('sha256', 'scheduled-reset-completed'), function () {});
        } finally {
            Redis::del('traffic_reset_lock');
        }
        return 0;
    }

    private function due(int $method, int $expires, int $now): bool
    {
        switch ($method) {
            case 0:
                return date('d', $now) === '01';
            case 1:
                $today = date('d', $now);
                $last = date('t', $now);
                $expireDay = date('d', $expires);
                return ($expireDay === $today || ($today === $last && $expireDay >= $last))
                    && $now < $expires - 2160000;
            case 3:
                return date('md', $now) === '0101';
            case 4:
                return date('m-d', $expires) === date('m-d', $now);
            default:
                return false;
        }
    }
}
