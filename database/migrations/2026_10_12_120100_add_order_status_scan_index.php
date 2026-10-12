<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddOrderStatusScanIndex extends Migration
{
    private function exists(): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return (bool) DB::select("SHOW INDEX FROM v2_order WHERE Key_name = 'idx_status_id'");
        }
        foreach (DB::select('PRAGMA index_list(v2_order)') as $index) {
            if ($index->name === 'idx_status_id') return true;
        }
        return false;
    }

    public function up()
    {
        if (!Schema::hasTable('v2_order') || $this->exists()) return;
        Schema::table('v2_order', function (Blueprint $table) { $table->index(['status', 'id'], 'idx_status_id'); });
    }

    public function down()
    {
        if (!Schema::hasTable('v2_order') || !$this->exists()) return;
        Schema::table('v2_order', function (Blueprint $table) { $table->dropIndex('idx_status_id'); });
    }
}
