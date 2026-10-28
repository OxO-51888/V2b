<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_hy2_report_cursor', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->char('digest', 64)->default('');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_hy2_online', function (Blueprint $table) {
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('user_id');
            $table->text('ips');
            $table->unsignedInteger('expires_at')->index();
            $table->primary(['node_id', 'user_id']);
            $table->index('user_id');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_hy2_online');
        Schema::dropIfExists('v2_hy2_report_cursor');
    }
};
