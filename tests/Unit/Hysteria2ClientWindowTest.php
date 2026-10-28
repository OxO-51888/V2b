<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\ClashMeta;
use App\Protocols\ClashVerge;
use App\Protocols\ClashNyanpasu;
use App\Protocols\Singbox\Singbox;
use App\Utils\Hysteria2ClientWindows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class Hysteria2ClientWindowTest extends TestCase
{
    private $previousContainer;
    private $config;
    private $host = 'backup.example.test';

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $this->config = new Repository(['v2board' => []]);
        $container->instance('config', $this->config);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function configure($settings): void
    {
        $this->config->set('v2board.hysteria2_client_windows', $settings);
    }

    private function server(string $host = ''): array
    {
        return ['name' => 'Fixture', 'host' => $host ?: $this->host, 'port' => '30001-35000',
            'tls_settings' => ['server_name' => 'cert.example.test', 'allow_insecure' => 1],
            'obfs' => 'salamander', 'obfs_password' => 'fixture-obfs'];
    }

    private function build(array $server): array
    {
        $method = new ReflectionMethod(ClashMeta::class, 'buildHysteria2');
        return $method->invoke(new ClashMeta([], []), 'fixture-password', $server);
    }

    private function windows(): array
    {
        return ['initial-stream-receive-window' => 33554432, 'max-stream-receive-window' => 33554432,
            'initial-connection-receive-window' => 67108864, 'max-connection-receive-window' => 67108864];
    }

    public function test_exact_host_adds_only_four_window_fields(): void
    {
        $before = $this->build($this->server());
        $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
        $after = $this->build($this->server());
        $this->assertSame($before + $this->windows(), $after);
        $this->assertSame($before, array_diff_key($after, $this->windows()));
    }

    public function test_unselected_hosts_and_similar_names_keep_defaults(): void
    {
        foreach (['other.example.test', 'backup.example.test.attacker.test', 'notbackup.example.test', '1.1.1.1'] as $host) {
            $this->configure([]);
            $before = $this->build($this->server($host));
            $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
            $this->assertSame($before, $this->build($this->server($host)));
        }
    }

    public function test_hostname_case_and_trailing_root_dot_are_normalized(): void
    {
        $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
        $proxy = $this->build($this->server(strtoupper($this->host) . '.'));
        $this->assertSame($this->windows(), array_intersect_key($proxy, $this->windows()));
    }

    public function test_invalid_settings_are_ignored_without_breaking_subscription(): void
    {
        $baseline = $this->build($this->server());
        foreach ([null, 'bad', 1, true, [], [$this->host => 'bad'], [$this->host => []]] as $settings) {
            $this->configure($settings);
            $this->assertSame($baseline, $this->build($this->server()));
        }
        foreach ([null, true, false, [], '33554432', 0, -1, 65535, 33554432.5, 67108865, PHP_INT_MAX] as $stream) {
            $this->configure([$this->host => ['stream' => $stream, 'connection' => 67108864]]);
            $this->assertSame($baseline, $this->build($this->server()));
        }
        foreach ([null, true, [], '67108864', 0, -1, 33554431, 67108864.5, 268435457, PHP_INT_MAX] as $connection) {
            $this->configure([$this->host => ['stream' => 33554432, 'connection' => $connection]]);
            $this->assertSame($baseline, $this->build($this->server()));
        }
    }

    public function test_legacy_hysteria2_keeps_port_hopping_and_hysteria1_is_unchanged(): void
    {
        $server = $this->server() + ['version' => 2, 'insecure' => 1, 'server_name' => 'cert.example.test',
            'up_mbps' => 1000, 'down_mbps' => 1000];
        $before = ClashMeta::buildHysteria('fixture-password', $server);
        $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
        $after = ClashMeta::buildHysteria('fixture-password', $server);
        $this->assertSame($before + $this->windows(), $after);
        $this->assertSame('30001-35000', $after['ports']);
        $server['version'] = 1;
        $after = ClashMeta::buildHysteria('fixture-password', $server);
        $this->configure([]);
        $this->assertSame(ClashMeta::buildHysteria('fixture-password', $server), $after);
    }

    public function test_disabling_override_restores_identical_profile(): void
    {
        $before = $this->build($this->server());
        $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
        $this->assertNotSame($before, $this->build($this->server()));
        $this->configure([]);
        $this->assertSame($before, $this->build($this->server()));
    }

    public function test_clash_party_default_user_agent_selects_mihomo_subscription(): void
    {
        $method = new ReflectionMethod(ClientController::class, 'normalizeClientFlag');
        foreach (['mihomo.party/v1.8.7 (clash.meta)', 'mihomo.party/v2.0.0 (clash.meta)', 'mihomo/1.19.27'] as $agent) {
            $this->assertSame('meta', $method->invoke(new ClientController(), 'clash', $agent));
        }
        foreach (['sing-box/1.14.0-beta.1', 'sfa/1.14.0-beta.1', 'sfi/1.14.0-beta.1'] as $agent) {
            $this->assertSame('sing-box 1.14.0-beta.1', $method->invoke(new ClientController(), '', $agent));
        }
    }

    public function test_all_mihomo_exporters_share_window_settings(): void
    {
        foreach ([ClashMeta::class, ClashVerge::class, ClashNyanpasu::class] as $class) {
            $method = new ReflectionMethod($class, 'buildHysteria2');
            $instance = new $class([], []);
            $this->configure([]);
            $before = $method->invoke($instance, 'fixture-password', $this->server());
            $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
            $this->assertSame($before + $this->windows(), $method->invoke($instance, 'fixture-password', $this->server()));
            $other = $method->invoke($instance, 'fixture-password', $this->server('hongkong.example.test'));
            $this->assertSame([], array_intersect_key($other, $this->windows()));
            $legacy = $this->server() + ['version' => 2, 'insecure' => 1, 'server_name' => 'cert.example.test'];
            $this->assertSame($this->windows(), array_intersect_key($class::buildHysteria('fixture-password', $legacy), $this->windows()));
        }
    }

    public function test_singbox_windows_require_supported_core_version(): void
    {
        $method = new ReflectionMethod(Singbox::class, 'buildHysteria2');
        $before = $method->invoke(new Singbox([], []), 'fixture-password', $this->server());
        $this->configure([$this->host => ['stream' => 33554432, 'connection' => 67108864]]);
        foreach ([null, '', '1.11.15', '1.12.0', '1.13.9', '1.14.0-beta.1', 'invalid'] as $version) {
            $instance = new Singbox([], [], ['client_version' => $version]);
            $this->assertSame($before, $method->invoke($instance, 'fixture-password', $this->server()));
        }
        foreach (['1.14.0', '1.14.1', '1.15.0', '2.0.0'] as $version) {
            $instance = new Singbox([], [], ['client_version' => $version]);
            $after = $method->invoke($instance, 'fixture-password', $this->server());
            $this->assertSame($before + ['stream_receive_window' => 33554432, 'connection_receive_window' => 67108864], $after);
        }
        $proxy = ['type' => 'hysteria2', 'server' => 'hongkong.example.test'];
        $this->assertSame($proxy, Hysteria2ClientWindows::singbox($proxy, '1.14.1'));
        $proxy = ['type' => 'tuic', 'server' => $this->host];
        $this->assertSame($proxy, Hysteria2ClientWindows::mihomo($proxy));
        $this->assertSame($proxy, Hysteria2ClientWindows::singbox($proxy, '1.14.1'));
    }
}
