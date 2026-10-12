<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Services\ScheduledTaskLock;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

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
        return app(ScheduledTaskLock::class)->run('check:order', function () {
            return $this->runTask();
        });
    }

    protected function runTask()
    {
        // Paid orders take priority; recent unpaid orders need no queued work.
        $expiredAt = time() - 3600 * 2;
        foreach ([1, 0] as $status) {
            $orders = Order::where('status', $status);
            if ($status === 0) $orders->where('created_at', '<=', $expiredAt);
            $orders->select(['id', 'trade_no'])->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    OrderHandleJob::dispatch($order->trade_no);
                }
            });
        }
    }
}
