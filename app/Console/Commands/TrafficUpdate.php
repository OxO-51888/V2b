<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Services\ScheduledTaskLock;
use App\Services\TrafficAccounting;

class TrafficUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

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
        return app(ScheduledTaskLock::class)->run('traffic:update', function () {
            return $this->runTask();
        });
    }

    protected function runTask()
    {
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }
        app(TrafficAccounting::class)->drain();
        return 0;
    }
}
