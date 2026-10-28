<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hy2_app(bool $fresh = true)
{
    $root = dirname(__DIR__, 2);
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = getenv('HY2_TEST_DB') ? json_decode(getenv('HY2_TEST_DB'), true, 16, JSON_THROW_ON_ERROR)
        : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    if ($connection['driver'] === 'mysql') {
        if (!str_starts_with($connection['unix_socket'] ?? '', '/tmp/hy2-contract-')
            || ($connection['database'] ?? '') !== 'hy2_contract_test' || ($connection['host'] ?? '') !== '') {
            throw new RuntimeException('Only a private test database is allowed');
        }
    }
    config(['database.default' => 'hy2_test', 'database.connections.hy2_test' => $connection,
        'v2board.server_token' => 'fixture-token', 'v2board.force_https' => false,
        'cache.default' => 'array', 'queue.default' => 'sync']);
    DB::purge('hy2_test');
    if ($fresh) hy2_schema($root);
    return $app;
}

function hy2_schema(string $root): void
{
    foreach (['insert','delete','update'] as $event) DB::unprepared('DROP TRIGGER IF EXISTS hy2_user_'.$event);
    foreach (['v2_hy2_user_revision','v2_hy2_online','v2_hy2_report_cursor','v2_stat_user','v2_stat_server','v2_server_v2node','v2_user','v2_subscription_rule'] as $table) Schema::dropIfExists($table);
    Schema::create('v2_user', function ($t) {
        $t->increments('id');
        $t->string('uuid')->default('user-1');
        foreach (['u','d','transfer_enable'] as $name) $t->bigInteger($name)->default(0);
        foreach (['group_id','banned','speed_limit','device_limit','t','created_at','updated_at'] as $name) $t->integer($name)->default(0);
        $t->integer('expired_at')->nullable();
    });
    Schema::create('v2_server_v2node', function ($t) {
        $t->increments('id');
        foreach (['name','host','listen_ip','protocol','obfs','obfs_password'] as $name) $t->string($name)->default('');
        foreach (['group_id','route_id','tls_settings'] as $name) $t->text($name)->nullable();
        foreach (['parent_id','show','server_port','tls','up_mbps','down_mbps','created_at','updated_at'] as $name) $t->integer($name)->default(0);
        $t->decimal('rate',10,2)->default(1);
    });
    Schema::create('v2_stat_user', function ($t) {
        $t->increments('id'); $t->integer('user_id'); $t->decimal('server_rate',10,2);
        $t->bigInteger('u'); $t->bigInteger('d'); $t->string('record_type');
        foreach (['record_at','created_at','updated_at'] as $name) $t->integer($name);
        $t->unique(['user_id','server_rate','record_at']);
    });
    Schema::create('v2_stat_server', function ($t) {
        $t->increments('id'); $t->integer('server_id'); $t->string('server_type');
        $t->bigInteger('u'); $t->bigInteger('d'); $t->string('record_type');
        foreach (['record_at','created_at','updated_at'] as $name) $t->integer($name);
        $t->unique(['server_id','server_type','record_at']);
    });
    Schema::create('v2_subscription_rule', function ($t) {
        $t->increments('id'); $t->string('type'); $t->integer('enabled')->default(0); $t->integer('sort')->default(0);
    });
    (require $root . '/database/migrations/2026_10_25_000001_create_hy2_control_state.php')->up();
    (require $root . '/database/migrations/2026_10_25_000002_hy2_snapshot_revision.php')->up();
    DB::table('v2_user')->insert(['id'=>1,'uuid'=>'user-1','group_id'=>1,'transfer_enable'=>1000,'device_limit'=>2]);
    for ($id=1;$id<=8;$id++) DB::table('v2_server_v2node')->insert([
        'id'=>$id,'name'=>'fixture','host'=>'192.0.2.1','listen_ip'=>'::','protocol'=>'hysteria2',
        'group_id'=>'[1]','tls_settings'=>'{"cert_mode":"file","server_name":"fixture.invalid"}',
        'server_port'=>51800+$id,'tls'=>1,'up_mbps'=>8000,'down_mbps'=>8000,
    ]);
}
