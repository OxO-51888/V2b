<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingReceipt
{
    public function assertReady(): void
    {
        DB::table('v2_accounting_receipt')->where('id', '__schema_check__')->exists();
    }

    public function claim(string $id, string $digest): bool
    {
        if (DB::transactionLevel() < 1) throw new RuntimeException('Accounting receipts require a transaction');
        $inserted = DB::table('v2_accounting_receipt')->insertOrIgnore([
            'id' => $id, 'digest' => $digest, 'created_at' => time(),
        ]);
        if ($inserted) return true;
        $stored = DB::table('v2_accounting_receipt')->where('id', $id)->lockForUpdate()->value('digest');
        if (!is_string($stored) || !hash_equals($stored, $digest)) {
            throw new RuntimeException('Accounting receipt payload mismatch');
        }
        return false;
    }

    public function once(string $id, string $digest, callable $apply): bool
    {
        return DB::transaction(function () use ($id, $digest, $apply) {
            if (!$this->claim($id, $digest)) return false;
            $apply();
            return true;
        }, 3);
    }
}
