<?php

use App\Http\Controllers\V1\Admin\NoticeController as AdminNotice;
use App\Http\Controllers\V1\User\NoticeController as UserNotice;
use App\Models\Notice;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NoticeCommunityTest extends TestCase
{
    private $app;
    private $db;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->app->instance('config', new Repository());
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver'=>'sqlite','database'=>':memory:']);
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $factory = Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('make')->andReturnUsing(function ($data, $status, $headers) {
            return new Response($data, $status, $headers);
        });
        $this->app->instance(ResponseFactory::class, $factory);
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        Request::macro('validate', function ($rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        $this->db->getConnection()->getSchemaBuilder()->create('v2_notice', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('content');
            $table->integer('show')->default(0);
            $table->integer('community_show')->default(0);
            $table->string('img_url')->nullable();
            $table->text('tags')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    protected function tearDown(): void
    {
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        Request::flushMacros();
        Mockery::close();
    }

    private function notice(int $show=1, int $community=0): Notice
    {
        return Notice::create(['title'=>'Fixture title','content'=>'<p>Full body</p> **Markdown**',
            'show'=>$show,'community_show'=>$community,'created_at'=>100,'updated_at'=>100])->fresh();
    }

    private function fetch(array $query=[]): array
    {
        return (new UserNotice())->fetch(Request::create('/notice/fetch','GET',$query))->getOriginalContent();
    }

    private function toggle(array $params): void
    {
        $response=(new AdminNotice())->communityShow(Request::create('/notice/communityShow','POST',$params));
        $this->assertSame(['data'=>true], $response->getOriginalContent());
    }

    public function testNewNoticeDefaultsToDisabled(): void
    {
        $notice=Notice::create(['title'=>'new','content'=>'body'])->fresh();
        $this->assertSame(0,$notice->community_show);
    }

    public function testToggleIsIdempotentAndDoesNotChangeContentOrDashboardSwitch(): void
    {
        $notice=$this->notice(); $other=$this->notice(1,1);
        $original=$notice->getAttributes(); $otherOriginal=$other->getAttributes();
        foreach([1,1,0,0] as $value) {
            $this->toggle(['id'=>$notice->id,'community_show'=>$value]);
            $notice->refresh();
            $this->assertSame($value,$notice->community_show);
            foreach(['title','content','show','created_at'] as $key) $this->assertSame($original[$key],$notice->$key);
            $this->assertSame($otherOriginal,$other->fresh()->getAttributes());
        }
    }

    public function testNormalListAndCommunityListHaveIndependentSwitches(): void
    {
        $plain=$this->notice(1,0); $shared=$this->notice(1,1);
        $hidden=$this->notice(0,1); $off=$this->notice(0,0);
        $normal=$this->fetch(); $community=$this->fetch(['community'=>1]);
        $this->assertSame(2,$normal['total']);
        $this->assertSame([$shared->id,$plain->id],$normal['data']->pluck('id')->all());
        $this->assertSame(2,$community['total']);
        $this->assertSame([$hidden->id,$shared->id],$community['data']->pluck('id')->all());
        $this->assertSame($shared->content,$community['data'][0]->content);
    }

    public function testCommunityDetailCannotBypassSwitches(): void
    {
        foreach([[1,0],[0,0]] as [$show,$community]) {
            $notice=$this->notice($show,$community);
            $response=(new UserNotice())->fetch(Request::create('/notice/fetch','GET',['community'=>1,'id'=>$notice->id]));
            $this->assertSame(404,$response->getStatusCode());
        }
        foreach([0,1] as $show){
            $notice=$this->notice($show,1);
            $this->assertSame($notice->id,$this->fetch(['community'=>1,'id'=>$notice->id])['data']->id);
        }
    }

    public function testCountAndPaginationOnlyIncludeSharedNotices(): void
    {
        $ids=[];
        for($i=0;$i<7;$i++) { $ids[]=$this->notice($i%2,1)->id; $this->notice(1,0); }
        $expected=array_reverse($ids); $seen=[];
        for($page=1;$page<=3;$page++) {
            $result=$this->fetch(['community'=>1,'current'=>$page,'pageSize'=>3]);
            $this->assertSame(7,$result['total']);
            $seen=array_merge($seen,$result['data']->pluck('id')->all());
        }
        $this->assertSame($expected,$seen);
    }

    public function testDashboardHideDoesNotAffectCommunityAndDeleteRemovesBoth(): void
    {
        $notice=$this->notice(1,1);
        (new AdminNotice())->show(Request::create('/notice/show','POST',['id'=>$notice->id]));
        $this->assertSame(1,$this->fetch(['community'=>1])['total']);
        $this->assertSame(0,$this->fetch()['total']);
        $this->assertSame(1,$notice->fresh()->community_show);
        (new AdminNotice())->drop(Request::create('/notice/drop','POST',['id'=>$notice->id]));
        $this->assertSame(0,$this->fetch(['community'=>1])['total']);
    }

    /** @dataProvider switchCombinations */
    public function testAllSwitchCombinationsForListAndDetail(int $show,int $community): void
    {
        $notice=$this->notice($show,$community);
        foreach([0=>$show,1=>$community] as $scope=>$enabled){
            $list=$this->fetch(['community'=>$scope]);
            $this->assertSame($enabled,$list['total']);
            $this->assertSame($enabled, $list['data']->count());
            $response=(new UserNotice())->fetch(Request::create('/notice/fetch','GET',['community'=>$scope,'id'=>$notice->id]));
            $this->assertSame($enabled?200:404,$response->getStatusCode());
            if($enabled)$this->assertSame($notice->content,$response->getOriginalContent()['data']->content);
        }
    }

    public static function switchCombinations(): array
    {
        return [[0,0],[1,0],[0,1],[1,1]];
    }

    public function testMissingIdReturns404(): void
    {
        try { $this->toggle(['id'=>987,'community_show'=>1]); $this->fail('Missing ID accepted'); }
        catch(HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
    }

    /** @dataProvider invalidRequests */
    public function testInvalidRequestsCannotWrite(array $params): void
    {
        $notice=$this->notice(); $before=$notice->getAttributes();
        try { $this->toggle($params); $this->fail('Invalid request accepted'); }
        catch(\Illuminate\Validation\ValidationException $e) { $this->assertSame($before,$notice->fresh()->getAttributes()); }
    }

    public static function invalidRequests(): array
    {
        return [[[]],[['id'=>1]],[['id'=>1,'community_show'=>2]],[['id'=>1,'community_show'=>'false']],
            [['id'=>0,'community_show'=>1]],[['id'=>[],'community_show'=>1]],[['id'=>1,'community_show'=>[]]]];
    }
}
