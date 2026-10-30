<?php
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class SubscriptionHoppingTest extends TestCase
{
    private $previous;
    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository(['v2board' => []]));
        Container::setInstance($container);
    }
    protected function tearDown(): void { Container::setInstance($this->previous); parent::tearDown(); }
    private function server(string $ports): array
    {
        return ['name'=>'fixture','host'=>'node.example.test','port'=>$ports,
            'tls_settings'=>['server_name'=>'cert.example.test','allow_insecure'=>1],
            'obfs'=>'salamander','obfs_password'=>'fixture-obfs'];
    }
    public function testAllSixSitesAndMihomoExportersKeepCompletePortRanges(): void
    {
        foreach (['gm'=>'55001-60000','nnm'=>'50001-55000','ovo'=>'45001-50000',
            'yiyuan'=>'40001-45000','clash'=>'35001-40000','pianyi'=>'30001-35000'] as $site=>$ports) {
            foreach ([App\Protocols\ClashMeta::class,App\Protocols\ClashVerge::class,App\Protocols\ClashNyanpasu::class,App\Protocols\Stash::class] as $class) {
                $method=new ReflectionMethod($class,'buildHysteria2');
                $proxy=$method->invoke((new ReflectionClass($class))->newInstanceWithoutConstructor(),'fixture-password',$this->server($ports));
                $proxy=Symfony\Component\Yaml\Yaml::parse(Symfony\Component\Yaml\Yaml::dump($proxy));
                $this->assertSame($ports,$proxy['ports']??null,$site.' '.$class);
                $this->assertSame((int)explode('-',$ports)[0],$proxy['port']);
                $this->assertSame('fixture-password',$proxy['password']);
                $this->assertSame('fixture-obfs',$proxy['obfs-password']);
                $this->assertSame('cert.example.test',$proxy['sni']);
                $this->assertTrue($proxy['skip-cert-verify']);
            }
        }
    }
    public function testSinglePortAndMixedPortsArePreserved(): void
    {
        $method=new ReflectionMethod(App\Protocols\ClashMeta::class,'buildHysteria2');
        foreach (['443','30001-35000,40001,41000-42000','30001,30518'] as $ports) {
            $proxy=$method->invoke(new App\Protocols\ClashMeta([],[]),'fixture-password',$this->server($ports));
            if ($ports==='443') {
                $this->assertSame(443,$proxy['port']);$this->assertArrayNotHasKey('ports',$proxy);
            } else $this->assertSame($ports,$proxy['ports']);
        }
    }
    public function testSingboxDoesNotDiscardRangesOrStandalonePorts(): void
    {
        $method=new ReflectionMethod(App\Protocols\Singbox\Singbox::class,'buildHysteria2');
        foreach (['30001-35000'=>['30001:35000'],'30001-35000,40001,41000-42000'=>['30001:35000','40001:40001','41000:42000'],
            '30001,30518'=>['30001:30001','30518:30518']] as $ports=>$expected) {
            $proxy=$method->invoke(new App\Protocols\Singbox\Singbox([],[]),'fixture-password',$this->server($ports));
            $this->assertSame($expected,$proxy['server_ports']);
            $this->assertArrayNotHasKey('server_port',$proxy);
            $this->assertSame('fixture-password',$proxy['password']);
            $this->assertSame('cert.example.test',$proxy['tls']['server_name']);
        }
        $single=$method->invoke(new App\Protocols\Singbox\Singbox([],[]),'fixture-password',$this->server('443'));
        $this->assertSame(443,$single['server_port']);$this->assertArrayNotHasKey('server_ports',$single);
    }
}
