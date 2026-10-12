<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingReceipts extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_accounting_receipt')) return;
        Schema::create('v2_accounting_receipt', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->string('id', 96)->primary();
            $table->char('digest', 64);
            $table->unsignedInteger('created_at')->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_accounting_receipt');
    }
}
