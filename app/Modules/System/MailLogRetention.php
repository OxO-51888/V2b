<?php

namespace App\Modules\System;

use Illuminate\Support\Facades\DB;

class MailLogRetention
{
    public const KEEP_DAYS = 90;
    public const BATCH_SIZE = 500;

    public function prune(): array
    {
        $cutoff = $this->cutoff();
        $upperId = (int) DB::table('v2_mail_log')->max('id');
        $cursor = 0;
        $scanned = 0;
        $deleted = 0;

        // Walk the primary key once; do not repeatedly scan retained recent rows.
        while ($cursor < $upperId) {
            $rows = DB::table('v2_mail_log')
                ->where('id', '>', $cursor)
                ->where('id', '<=', $upperId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get(['id', 'created_at']);
            if ($rows->isEmpty()) break;

            $expiredIds = $rows->filter(fn ($row) => $row->created_at !== null
                && (int) $row->created_at > 0 && (int) $row->created_at < $cutoff)
                ->pluck('id')->all();
            if ($expiredIds) {
                $deleted += DB::table('v2_mail_log')->whereIn('id', $expiredIds)
                    ->where('created_at', '>', 0)
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            }
            $scanned += $rows->count();
            $cursor = (int) $rows->last()->id;
            if ($cursor < $upperId) $this->pause();
        }

        return compact('cutoff', 'upperId', 'scanned', 'deleted');
    }

    protected function cutoff(): int
    {
        return time() - self::KEEP_DAYS * 86400;
    }

    protected function pause(): void
    {
        usleep(100000);
    }
}
