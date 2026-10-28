<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentAttemptsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_payment_attempt')) return;
        Schema::create('v2_payment_attempt', function (Blueprint $table) {
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

    public function down()
    {
        Schema::dropIfExists('v2_payment_attempt');
    }
}
