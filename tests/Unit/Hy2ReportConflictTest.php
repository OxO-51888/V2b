<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../Support/Hy2Fixture.php';

final class Hy2ReportConflictTest extends TestCase
{
    private $app;
    protected function setUp(): void { $this->app=hy2_app(); }
    protected function tearDown(): void
    {
        DB::disconnect();Facade::clearResolvedInstances();restore_error_handler();restore_exception_handler();parent::tearDown();
    }
    private function send(string $action,?array $data=null,int $node=1): object
    {
        $request=Request::create('/api/v2/server/hy2/'.$action,$data===null?'GET':'POST',[],[],[],[
            'HTTP_AUTHORIZATION'=>'Bearer fixture-token','HTTP_X_NODE_ID'=>(string)$node,
            'HTTP_X_V2NODE_CONTRACT'=>'hy2-1','HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json','HTTP_USER_AGENT'=>'V2node-HY2/fixture','REMOTE_ADDR'=>'192.0.2.2',
        ],$data===null?null:json_encode($data));
        return $this->app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    }
    public function testConflictProofAndRetryBillEachDistinctBatchExactlyOnce(): void
    {
        $old=['sequence'=>1,'rows'=>[['uid'=>1,'u'=>7,'d'=>11]]];
        $new=['sequence'=>1,'rows'=>[['uid'=>1,'u'=>400,'d'=>600]]];
        $this->assertSame(200,$this->send('traffic',$old)->getStatusCode());
        $response=$this->send('traffic',$new);
        $this->assertSame(409,$response->getStatusCode());
        $proof=json_decode($response->getContent(),true);
        $this->assertSame('traffic_sequence_conflict',$proof['error']);
        $this->assertSame(1,$proof['sequence']);$this->assertSame(2,$proof['next']);
        $this->assertSame(1,$proof['request_sequence']);
        $this->assertSame(hash('sha256',json_encode($old)),$proof['digest']);
        $this->assertSame(hash('sha256',json_encode($new)),$proof['request_digest']);
        $this->assertSame('hy2-1',$response->headers->get('X-V2node-Contract'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(7,(int)DB::table('v2_user')->value('u'));
        $new['sequence']=2;
        for ($i=0;$i<10;$i++) {
            $accepted=$this->send('traffic',$new);
            $this->assertSame(200,$accepted->getStatusCode());
            $this->assertSame([1],json_decode($accepted->getContent(),true)['revoked']);
        }
        $this->assertSame(407,(int)DB::table('v2_user')->value('u'));
        $this->assertSame(611,(int)DB::table('v2_user')->value('d'));
        $this->assertSame(407,(int)DB::table('v2_stat_user')->sum('u'));
        $this->assertSame(611,(int)DB::table('v2_stat_server')->sum('d'));
    }
    public function testStateIsNodeScopedAndNotCacheable(): void
    {
        $this->assertSame(200,$this->send('traffic',['sequence'=>1,'rows'=>[['uid'=>1,'u'=>3,'d'=>4]]])->getStatusCode());
        foreach ([1=>2,2=>1] as $node=>$next) {
            $response=$this->send('state',null,$node);
            $state=json_decode($response->getContent(),true);
            $this->assertSame($next,$state['next']);
            $this->assertSame($next-1,$state['sequence']);
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertContains('X-Node-ID',$response->getVary());
        }
    }
}
