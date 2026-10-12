<?php

namespace App\Jobs;

use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Services\AccountingReceipt;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;
    protected $reportedAt;
    protected $receiptId;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->reportedAt = time();
        $this->receiptId = bin2hex(random_bytes(16));
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d', $this->reportedAt ?? time()));
        if ($recordAt < strtotime('-2 month')) return;
        $this->receiptId = $this->receiptId ?? ($this->job ? $this->job->getJobId() : bin2hex(random_bytes(16)));
        $rows = [];
        $chunk = 0;
        foreach ($this->data as $userId => $trafficData) {
            $rows[] = ['user_id' => $userId, 'server_rate' => $this->server['rate'],
                'u' => $trafficData[0], 'd' => $trafficData[1],
                'record_type' => $this->recordType, 'record_at' => $recordAt];
            if (count($rows) === 250) {
                $this->writeChunk($rows, $chunk++);
                $rows = [];
            }
        }
        if ($rows) $this->writeChunk($rows, $chunk);
    }

    private function writeChunk(array $rows, int $chunk): void
    {
        usort($rows, function ($a, $b) { return $a['user_id'] <=> $b['user_id']; });
        app(AccountingReceipt::class)->once('stat:' . $this->receiptId . ':' . $chunk,
            hash('sha256', json_encode($rows)), function () use ($rows) {
                $mysql = DB::connection()->getDriverName() === 'mysql';
                StatUser::upsert($rows, ['user_id', 'server_rate', 'record_at'], [
                    'u' => DB::raw('v2_stat_user.u + ' . ($mysql ? 'VALUES(u)' : 'excluded.u')),
                    'd' => DB::raw('v2_stat_user.d + ' . ($mysql ? 'VALUES(d)' : 'excluded.d')),
                    'updated_at',
                ]);
            });
    }
}
