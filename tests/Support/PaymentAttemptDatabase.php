<?php

namespace Tests\Support;

class PaymentAttemptDatabase extends OrderDatabase
{
    public static function boot(?array $connection = null): array
    {
        [$app, $db] = parent::boot($connection);
        $app->instance('translator', new \Illuminate\Translation\Translator(
            new \Illuminate\Translation\ArrayLoader(), 'en'
        ));
        return [$app, $db];
    }

    public static function schema($db): void
    {
        parent::schema($db);
        $schema = $db->getSchemaBuilder();
        $schema->table('v2_order', function ($table) {
            $table->integer('payment_id')->nullable();
            $table->integer('handling_amount')->nullable();
        });
        $schema->create('v2_payment', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name')->default('fixture');
            $table->string('payment')->default('SGEpay');
            $table->integer('enable')->default(1);
            $table->integer('handling_fee_fixed')->nullable();
            $table->decimal('handling_fee_percent', 10, 2)->nullable();
            $table->string('uuid')->nullable();
            $table->string('notify_domain')->nullable();
            $table->text('config')->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        $schema->create('v2_payment_attempt', function ($table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('payment_id');
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('handling_amount');
            $table->unsignedBigInteger('expected_amount');
            $table->char('callback_hash', 64)->nullable()->unique('payment_attempt_receipt_unique');
            $table->unsignedInteger('created_at');
            $table->unique(['order_id', 'payment_id', 'total_amount', 'handling_amount'], 'payment_attempt_snapshot_unique');
        });
    }
}
