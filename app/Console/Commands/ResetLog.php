<?php

namespace App\Console\Commands;

use App\Models\Log;
use App\Models\Plan;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\SubscriptionRuleLog;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\TrafficBuffer;

class ResetLog extends Command
{
    private const SUBSCRIPTION_RULE_LOG_KEEP = 5000;

    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->prune(StatUser::where('record_at', '<', strtotime('-2 month', time())));
        $this->prune(StatServer::where('record_at', '<', strtotime('-2 month', time())));
        $this->prune(Log::where('created_at', '<', strtotime('-1 month', time())));
        $this->pruneSubscriptionRuleLogs();
        $pending = json_decode(Redis::get(TrafficBuffer::PENDING) ?? 'null', true);
        $receipts = DB::table('v2_accounting_receipt')->where('created_at', '<', strtotime('-3 month'));
        if (isset($pending['id'])) $receipts->where('id', '!=', 'traffic:' . $pending['id']);
        $this->prune($receipts);
    }

    private function prune($query): void
    {
        do {
            $ids = (clone $query)->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) break;
            (clone $query)->whereIn('id', $ids)->delete();
        } while (true);
    }

    private function pruneSubscriptionRuleLogs()
    {
        $cutoffId = SubscriptionRuleLog::orderBy('id', 'DESC')
            ->skip(self::SUBSCRIPTION_RULE_LOG_KEEP)
            ->value('id');

        if (!$cutoffId) {
            return;
        }

        $this->prune(SubscriptionRuleLog::where('id', '<=', $cutoffId));
    }
}
