<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_hy2_online', fn (Blueprint $t) => $t->unsignedInteger('observed_at')->default(0));
        Schema::create('v2_hy2_user_revision', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); $t->unsignedBigInteger('revision')->default(1);
        });
        DB::table('v2_hy2_user_revision')->insert(['id'=>1,'revision'=>1]);
        $fields=['id','uuid','group_id','banned','speed_limit','device_limit','expired_at'];
        $sqlite=DB::connection()->getDriverName()==='sqlite';
        if (!$sqlite && DB::connection()->getDriverName()!=='mysql') throw new RuntimeException('HY2 revision requires MySQL or SQLite');
        $changes=array_map(fn ($f) => $sqlite ? "OLD.$f IS NOT NEW.$f" : "NOT (OLD.$f <=> NEW.$f)", $fields);
        $changes[]='((OLD.u + OLD.d < OLD.transfer_enable) <> (NEW.u + NEW.d < NEW.transfer_enable))';
        $condition=implode(' OR ', $changes);
        $bump='UPDATE v2_hy2_user_revision SET revision=revision+1 WHERE id=1;';
        foreach (['INSERT','DELETE'] as $event) {
            $name='hy2_user_'.strtolower($event);
            DB::unprepared($sqlite ? "CREATE TRIGGER $name AFTER $event ON v2_user BEGIN $bump END"
                : "CREATE TRIGGER $name AFTER $event ON v2_user FOR EACH ROW $bump");
        }
        DB::unprepared($sqlite ? "CREATE TRIGGER hy2_user_update AFTER UPDATE ON v2_user WHEN $condition BEGIN $bump END"
            : "CREATE TRIGGER hy2_user_update AFTER UPDATE ON v2_user FOR EACH ROW BEGIN IF $condition THEN $bump END IF; END");
    }

    public function down(): void
    {
        foreach (['insert','delete','update'] as $event) DB::unprepared('DROP TRIGGER IF EXISTS hy2_user_'.$event);
        Schema::dropIfExists('v2_hy2_user_revision');
        Schema::table('v2_hy2_online', fn (Blueprint $t) => $t->dropColumn('observed_at'));
    }
};
