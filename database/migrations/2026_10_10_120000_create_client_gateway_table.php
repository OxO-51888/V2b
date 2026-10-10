<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientGatewayTable extends Migration
{
    public function up()
    {
        Schema::create('v2_client_gateway', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('panel_id', 32);
            $table->string('record_type', 24);
            $key = $table->string('record_key', 180);
            if (Schema::getConnection()->getDriverName() === 'mysql') $key->collation('utf8mb4_bin');
            $table->longText('data');
            $table->string('expires_at', 24)->nullable();
            $table->unsignedInteger('updated_at');
            $table->unique(['panel_id', 'record_type', 'record_key'], 'client_gateway_record');
            $table->index(['panel_id', 'record_type', 'expires_at'], 'client_gateway_expiry');
            $table->index(['panel_id', 'record_type', 'id'], 'client_gateway_list');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_client_gateway');
    }
}
