<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommunityIdentitiesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('community_identities')) return;
        Schema::create('community_identities', function (Blueprint $table) {
            $table->string('site', 32);
            $table->unsignedBigInteger('user_id');
            $table->string('chat_id', 66);
            $table->primary(['site', 'user_id'], 'community_identity_user');
            $table->unique('chat_id', 'community_identity_chat');
        });
    }

    public function down()
    {
        Schema::dropIfExists('community_identities');
    }
}
