<?php

namespace Tests\Support;

use Illuminate\Bus\Dispatcher;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;

class OrderDatabase
{
    public static function boot(?array $connection = null): array
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['v2board' => []]));
        $capsule = new Manager($app);
        $capsule->addConnection($connection ?? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setEventDispatcher(new EventDispatcher($app));
        $capsule->bootEloquent();
        $app->instance('db', $capsule->getDatabaseManager());
        $bus = new Dispatcher($app);
        $app->instance('bus', $bus);
        $app->instance(DispatcherContract::class, $bus);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Bus::fake();
        return [$app, $capsule->getConnection()];
    }

    public static function schema($db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('v2_user', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            foreach (['balance', 'u', 'd', 'transfer_enable'] as $field) $table->bigInteger($field)->default(0);
            foreach (['plan_id', 'group_id', 'expired_at', 'device_limit', 'speed_limit'] as $field) $table->integer($field)->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        $schema->create('v2_plan', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('transfer_enable')->default(100);
            foreach (['group_id', 'device_limit', 'speed_limit'] as $field) $table->integer($field)->default(1);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        $schema->create('v2_order', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->string('trade_no')->unique();
            $table->string('period')->default('onetime_price');
            $table->integer('type')->default(1);
            $table->integer('status')->default(0);
            foreach (['total_amount', 'balance_amount', 'refund_amount'] as $field) $table->integer($field)->default(0);
            $table->text('surplus_order_ids')->nullable();
            $table->integer('paid_at')->nullable();
            $table->string('callback_no')->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
    }
}
