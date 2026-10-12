<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TrafficAccounting
{
    private $buffer;
    private $receipts;

    public function __construct(TrafficBuffer $buffer, AccountingReceipt $receipts)
    {
        $this->buffer = $buffer;
        $this->receipts = $receipts;
    }

    public function drain(int $seconds = 40): int
    {
        // Check the schema before moving anything out of the legacy buffers.
        $this->receipts->assertReady();
        $deadline = microtime(true) + $seconds;
        $processed = 0;
        do {
            $batch = $this->buffer->claim();
            if ($batch === null) {
                // HSCAN may return an empty page before reaching the end of a nonempty hash.
                if (!$this->buffer->hasBufferedTraffic()) break;
                continue;
            }
            $this->apply($batch);
            $this->buffer->acknowledge($batch['id']);
            $processed += count($batch['rows']);
        } while (microtime(true) < $deadline);
        return $processed;
    }

    public function apply(array $batch): bool
    {
        $rows = $batch['rows'];
        usort($rows, function ($a, $b) { return (int) $a[0] <=> (int) $b[0]; });
        return $this->receipts->once('traffic:' . $batch['id'], hash('sha256', json_encode($rows)), function () use ($rows) {
            if (!$rows) return;
            $ids = array_map(function ($row) { return (int) $row[0]; }, $rows);
            DB::table('v2_user')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $uploads = $downloads = [];
            foreach ($rows as $row) {
                $uploads[] = 'WHEN ' . (int) $row[0] . ' THEN ' . (int) $row[1];
                $downloads[] = 'WHEN ' . (int) $row[0] . ' THEN ' . (int) $row[2];
            }
            // Increment the committed counters, never write a previously read absolute value.
            DB::table('v2_user')->whereIn('id', $ids)->update([
                'u' => DB::raw('u + CASE id ' . implode(' ', $uploads) . ' ELSE 0 END'),
                'd' => DB::raw('d + CASE id ' . implode(' ', $downloads) . ' ELSE 0 END'),
                't' => time(), 'updated_at' => time(),
            ]);
        });
    }
}
