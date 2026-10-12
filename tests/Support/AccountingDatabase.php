<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

class AccountingDatabase extends OrderDatabase
{
    public static function schema($db): void
    {
        parent::schema($db);
        $schema = $db->getSchemaBuilder();
        $schema->table('v2_user', function ($t) { $t->integer('t')->default(0); });
        $schema->table('v2_plan', function ($t) { $t->integer('reset_traffic_method')->nullable(); });
        Log::swap(new NullLogger());
        require_once dirname(__DIR__, 2) . '/database/migrations/2026_10_12_120000_create_accounting_receipts.php';
        (new \CreateAccountingReceipts())->up();
        $schema->create('v2_stat_user', function ($t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->decimal('server_rate', 10, 2);
            $t->bigInteger('u');
            $t->bigInteger('d');
            $t->string('record_type');
            $t->integer('record_at');
            $t->integer('created_at');
            $t->integer('updated_at');
            $t->unique(['server_rate', 'user_id', 'record_at']);
        });
        $schema->create('v2_log', function ($t) {
            $t->increments('id');
            foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context'] as $key) $t->text($key)->nullable();
            $t->integer('created_at');
            $t->integer('updated_at');
        });
    }
}
