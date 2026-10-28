<?php
use App\Modules\Core\Hy2Accounting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Support/Hy2Fixture.php';

class Hy2ContractTest extends TestCase
{
    private $app;
    protected function setUp(): void { $this->app=hy2_app(); }
    protected function tearDown(): void
    {
        Illuminate\Support\Carbon::setTestNow();
        DB::disconnect();
        Facade::clearResolvedInstances();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function send(string $action, string $method='GET', ?array $data=null, array $headers=[])
    {
        $request=Request::create('/api/v2/server/hy2/'.$action,$method,[],[],[],[
            'HTTP_AUTHORIZATION'=>'Bearer fixture-token','HTTP_X_NODE_ID'=>'1',
            'HTTP_X_V2NODE_CONTRACT'=>'hy2-1','HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json','HTTP_USER_AGENT'=>'V2node-HY2/fixture','REMOTE_ADDR'=>'192.0.2.2',
        ], $data===null ? null : json_encode($data,JSON_FORCE_OBJECT & 0));
        foreach ($headers as $key=>$value) $request->headers->set($key,$value);
        return $this->app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    }

    private function batch(int $sequence=1,int $u=400,int $d=600): array
    { return ['sequence'=>$sequence,'rows'=>[['uid'=>1,'u'=>$u,'d'=>$d]]]; }

    public function testOnlyExplicitHy2RoutesRemain(): void
    {
        $this->assertSame(200,$this->send('config')->getStatusCode());
        foreach (['/api/v1/server/UniProxy/push','/api/v1/server/UniProxy/user','/api/v2/server/config',
            '/api/v1/server/Deepbwork/config','/api/v1/server/TrojanTidalab/config'] as $path) {
            $request=Request::create($path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_USER_AGENT'=>'fixture']);
            $response=$this->app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $this->assertSame(404,$response->getStatusCode(),$path.' '.$response->getContent());
        }
        $this->assertSame(405,$this->send('traffic')->getStatusCode());
        $this->assertSame(401,$this->send('config','GET',null,['Authorization'=>'Bearer wrong'])->getStatusCode());
        $this->assertSame(426,$this->send('config','GET',null,['X-V2node-Contract'=>'old'])->getStatusCode());
        $this->assertSame(404,$this->send('config','GET',null,['X-Node-ID'=>'999'])->getStatusCode());
        DB::table('v2_server_v2node')->where('id',1)->update(['protocol'=>'vless']);
        $this->assertSame(409,$this->send('config')->getStatusCode());
    }

    public function testConfigAndUsersUseRealConditionalResponses(): void
    {
        $response=$this->send('config');
        $this->assertSame(200,$response->getStatusCode(),$response->getContent());
        $this->assertSame('hy2-1',$response->headers->get('X-V2node-Contract'));
        $etag=$response->headers->get('ETag');
        foreach ([$etag,'W/'.$etag,'"unrelated", '.$etag] as $value) {
            $cached=$this->send('config','GET',null,['If-None-Match'=>$value]);
            $this->assertSame(304,$cached->getStatusCode()); $this->assertSame('',$cached->getContent());
        }
        $users=$this->send('users');
        $this->assertSame(200,$users->getStatusCode(),$users->getContent());
        $this->assertCount(1,json_decode($users->getContent(),true)['users']);
        DB::table('v2_user')->update(['banned'=>1]);
        $empty=$this->send('users','GET',null,['If-None-Match'=>$users->headers->get('ETag')]);
        $this->assertSame(200,$empty->getStatusCode());
        $this->assertSame([],json_decode($empty->getContent(),true)['users']);
    }

    public function testDuplicateBillingStatisticsAndQuotaAreAtomic(): void
    {
        for ($i=0;$i<10;$i++) {
            $r=$this->send('traffic','POST',$this->batch());
            $this->assertSame(200,$r->getStatusCode(),$r->getContent());
            $ack=json_decode($r->getContent(),true);
            $this->assertTrue($ack['accepted']);$this->assertSame([1],$ack['revoked']);
            $this->assertSame(hash('sha256',json_encode($this->batch())),$ack['digest']);
        }
        $this->assertSame(400,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(600,(int)DB::table('v2_user')->value('d'));
        $this->assertSame(400,(int)DB::table('v2_stat_user')->sum('u'));
        $this->assertSame(600,(int)DB::table('v2_stat_server')->sum('d'));
        $this->assertSame([],json_decode($this->send('users')->getContent(),true)['users']);
        $this->assertSame(1,DB::table('v2_hy2_report_cursor')->count());
    }

    public function testChangedPayloadAndOutOfOrderReportsAreRejected(): void
    {
        $this->assertSame(200,$this->send('traffic','POST',$this->batch())->getStatusCode());
        foreach ([$this->batch(1,500),$this->batch(3)] as $batch) $this->assertSame(409,$this->send('traffic','POST',$batch)->getStatusCode());
        $this->assertSame(400,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(422,$this->send('traffic','POST',$this->batch(2,-1))->getStatusCode());
        $this->assertSame(422,$this->send('traffic','POST',['sequence'=>2,'rows'=>[$this->batch()['rows'][0],$this->batch()['rows'][0]]])->getStatusCode());
        $this->assertSame(1,(int)DB::table('v2_hy2_report_cursor')->value('sequence'));
    }

    public function testFailureAfterCounterUpdateRollsBackEverything(): void
    {
        if (DB::connection()->getDriverName()==='sqlite') DB::unprepared("CREATE TRIGGER break_stats BEFORE INSERT ON v2_stat_user BEGIN SELECT RAISE(ABORT, 'injected'); END");
        else DB::unprepared("CREATE TRIGGER break_stats BEFORE INSERT ON v2_stat_user FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
        $r=$this->send('traffic','POST',$this->batch());
        $this->assertSame(500,$r->getStatusCode());
        $this->assertSame(0,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(0,DB::table('v2_hy2_report_cursor')->count());
        $this->assertSame(0,DB::table('v2_stat_server')->count());
        DB::unprepared('DROP TRIGGER break_stats');
        $this->assertSame(200,$this->send('traffic','POST',$this->batch())->getStatusCode());
        $this->assertSame(400,(int)DB::table('v2_user')->value('u'));
    }

    public function testOnlineReplaceDeduplicatesAndEmptyHeartbeatClears(): void
    {
        $data=['1'=>['192.0.2.55','::ffff:192.0.2.55']];
        $r=$this->send('alive','POST',$data);$this->assertSame(200,$r->getStatusCode(),$r->getContent());
        $r=$this->send('alive','POST',$data,['X-Node-ID'=>'2']);$this->assertSame(200,$r->getStatusCode(),$r->getContent());
        $this->assertSame(['1'=>1],json_decode($this->send('alive')->getContent(),true)['alive']);
        $this->assertSame(200,$this->send('alive','POST',[])->getStatusCode());
        $this->assertSame(1,DB::table('v2_hy2_online')->count());
        $this->assertSame(200,$this->send('alive','POST',[],['X-Node-ID'=>'2'])->getStatusCode());
        $this->assertSame([],json_decode($this->send('alive')->getContent(),true)['alive']);
    }

    public function testFractionalRateDoesNotChangeStatsOrReplayCharge(): void
    {
        DB::table('v2_server_v2node')->where('id',1)->update(['rate'=>1.5]);
        $this->assertSame(200,$this->send('traffic','POST',$this->batch(1,10,20))->getStatusCode());
        $this->assertSame(15,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(30,(int)DB::table('v2_user')->value('d'));
        DB::table('v2_server_v2node')->where('id',1)->update(['rate'=>2]);
        $this->assertSame(200,$this->send('traffic','POST',$this->batch(1,10,20))->getStatusCode());
        $this->assertSame(15,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(10,(int)DB::table('v2_stat_user')->sum('u'));
    }

    public function testSnapshotCacheAvoidsUserQueryAndRawWritesInvalidate(): void
    {
        $first=$this->send('users'); $etag=$first->headers->get('ETag');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(304,$this->send('users','GET',null,['If-None-Match'=>$etag])->getStatusCode());
        $queries=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
        $this->assertSame([],array_values(array_filter($queries,fn ($q) => preg_match('/from [`"]?v2_user[`"]?\s/i',$q))));
        foreach ([['uuid'=>'changed'],['speed_limit'=>9],['device_limit'=>4],['banned'=>1],['banned'=>0],
            ['transfer_enable'=>0],['transfer_enable'=>1000],['group_id'=>2],['group_id'=>1],['expired_at'=>time()-1],['expired_at'=>null]] as $change) {
            DB::table('v2_user')->where('id',1)->update($change);
            $r=$this->send('users','GET',null,['If-None-Match'=>$etag]);
            $this->assertSame(200,$r->getStatusCode(),json_encode($change)); $etag=$r->headers->get('ETag');
        }
        $revision=DB::table('v2_hy2_user_revision')->value('revision');
        DB::table('v2_user')->where('id',1)->update(['u'=>1]);
        $this->assertSame($revision,DB::table('v2_hy2_user_revision')->value('revision'),'small traffic invalidated all snapshots');
        DB::beginTransaction(); DB::table('v2_user')->update(['banned'=>1]); DB::rollBack();
        $this->assertSame($revision,DB::table('v2_hy2_user_revision')->value('revision'));
        DB::table('v2_user')->where('id',1)->update(['id'=>2]);
        $this->assertSame(2,json_decode($this->send('users')->getContent(),true)['users'][0]['id']);
        DB::table('v2_user')->delete();
        $this->assertSame([],json_decode($this->send('users')->getContent(),true)['users']);
    }

    public function testClockExpiryAndLongOnlineInterval(): void
    {
        $now=time(); Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestamp($now));
        DB::table('v2_user')->update(['expired_at'=>$now+10]);
        $first=$this->send('users');
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestamp($now+11));
        $r=$this->send('users','GET',null,['If-None-Match'=>$first->headers->get('ETag')]);
        $this->assertSame(200,$r->getStatusCode());$this->assertSame([],json_decode($r->getContent(),true)['users']);
        config(['v2board.server_push_interval'=>300]);
        $this->assertSame(200,$this->send('alive','POST',['1'=>['192.0.2.3']])->getStatusCode());
        $row=DB::table('v2_hy2_online')->first();
        $this->assertSame(930,(int)$row->expires_at-(int)$row->observed_at);
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestamp($now+400));
        $state=(new App\Modules\Core\Hy2Online())->states([1]);
        $this->assertSame(1,$state[1]['alive_ip']);$this->assertSame($now+11,(int)$state[1]['v2node1']['lastupdateAt']);
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestamp($now+1000));
        $this->assertSame(0,(new App\Modules\Core\Hy2Online())->states([1])[1]['alive_ip']);
    }

    public function testOnlineRulePathUsesBatchedStateReads(): void
    {
        DB::table('v2_subscription_rule')->insert(['type'=>'node_alive_ip_over_limit','enabled'=>1,'sort'=>0]);
        $data=[];for ($id=1;$id<=1200;$id++) $data[$id]=['192.0.2.55'];
        DB::enableQueryLog();DB::flushQueryLog();
        $r=$this->send('alive','POST',$data);
        $this->assertSame(200,$r->getStatusCode(),$r->getContent());
        $queries=array_column(DB::getQueryLog(),'query');DB::disableQueryLog();
        $reads=array_values(array_filter($queries,fn ($q) => preg_match('/^select .*from [`"]?v2_hy2_online/i',$q)));
        $ruleReads=array_values(array_filter($queries,fn ($q) => preg_match('/^select .*from [`"]?v2_subscription_rule/i',$q)));
        $this->assertCount(4,$reads,'three batches plus bounded expired-row cleanup');
        $this->assertCount(2,$ruleReads,'exists plus one cached rule list, not one list per user');
        $this->assertSame(1200,DB::table('v2_hy2_online')->count());
    }
}
