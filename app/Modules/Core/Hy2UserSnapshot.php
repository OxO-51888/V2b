<?php
namespace App\Modules\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Hy2UserSnapshot
{
    public function response(Request $request, array $groups)
    {
        $groups=array_values(array_unique(array_map('intval',$groups))); sort($groups);
        $connection=DB::connection();
        $key='hy2:users:'.hash('sha256',json_encode([$connection->getName(),$connection->getDatabaseName(),$groups]));
        for ($attempt=0;$attempt<3;$attempt++) {
            $now=now()->timestamp;
            $revision=(string) DB::table('v2_hy2_user_revision')->where('id',1)->value('revision');
            if ($revision==='') abort(503,'HY2 snapshot revision missing');
            $saved=Cache::get($key);
            if (is_array($saved) && $saved['revision']===$revision && $saved['until']>$now) return $this->emit($request,$saved);
            $users=DB::table('v2_user')->whereIn('group_id',$groups)->whereRaw('u + d < transfer_enable')->where('banned',0)
                ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at','>=',$now))
                ->orderBy('id')->limit(100001)->get(['id','uuid','speed_limit','device_limit','expired_at']);
            if ($users->count()>100000) abort(413,'User snapshot exceeds contract bound');
            $until=$now+600; $rows=[];
            foreach ($users as $u) {
                if ($u->expired_at!==null) $until=min($until,(int)$u->expired_at+1);
                $rows[]=['id'=>(int)$u->id,'uuid'=>$u->uuid,'speed_limit'=>(int)$u->speed_limit,'device_limit'=>(int)$u->device_limit];
            }
            if ($revision!==(string)DB::table('v2_hy2_user_revision')->where('id',1)->value('revision')) continue;
            $body=json_encode(['users'=>$rows],JSON_THROW_ON_ERROR);
            $saved=['revision'=>$revision,'until'=>$until,'body'=>$body,'etag'=>hash('sha256',$body)];
            Cache::put($key,$saved,max(1,$until-$now));
            return $this->emit($request,$saved);
        }
        abort(503,'User snapshot changing; retry');
    }

    private function emit(Request $request, array $saved)
    {
        $response=response($saved['body'],200,['Content-Type'=>'application/json'])->setEtag($saved['etag']);
        $response->isNotModified($request);
        return $response;
    }
}
