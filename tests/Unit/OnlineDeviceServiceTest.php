<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\StatController;
use App\Services\ClientGateway\OnlineDeviceService;
use App\Services\ClientGateway\PanelStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../database/migrations/2026_10_10_120000_create_client_gateway_table.php';

class OnlineDeviceServiceTest extends TestCase
{
    private $db;
    private $app;
    private $directory;
    private $now = 1791600000;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/online-device-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/settings.json', '{"heartbeatAfterSeconds":300}');
        $this->app = new Application(sys_get_temp_dir());
        $this->app->instance('config', new Repository(['panel_modules' => ['enabled' => true, 'gateway' => [
            'panel_id' => 'panel_06', 'storage' => 'database', 'directory' => $this->directory,
        ]]]));
        $this->app->instance('log', new NullLogger());
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->bootEloquent();
        $this->db = $capsule->getConnection();
        $this->app->instance('db', $capsule->getDatabaseManager());
        $this->app->bind('db.schema', fn () => $this->db->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        (new \CreateClientGatewayTable())->up();
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        if (is_file($this->directory . '/settings.json')) unlink($this->directory . '/settings.json');
        rmdir($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function date(int $timestamp): string { return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z'; }

    private function device(string $id, int $age, string $site = 'panel_06', string $status = 'approved'): void
    {
        (new PanelStore($this->db, $site))->put('devices', $id, [
            'deviceId' => $id, 'panelId' => $site, 'status' => $status, 'lastSeenAt' => $this->date($this->now - $age),
        ]);
    }

    private function session(string $id, string $device, int $expires = 600, string $site = 'panel_06'): void
    {
        (new PanelStore($this->db, $site))->put('sessions', $id, [
            'deviceId' => $device, 'panelId' => $site, 'expiresAt' => $this->date($this->now + $expires),
        ]);
    }

    private function snapshot(): array { return (new OnlineDeviceService())->snapshot($this->now); }

    public function testCountsRecentDeviceOnlyOnceAcrossMultipleSessions(): void
    {
        $this->device('active', 300);
        $this->session('one', 'active');
        $this->session('two', 'active');
        $this->assertSame(['count' => 1, 'window_seconds' => 420], $this->snapshot());
    }

    public function testHistoricalAndFutureRecordsDoNotBecomeOnlineFromMigrationTime(): void
    {
        foreach (['historical' => 86400, 'stale' => 421, 'future' => -1] as $id => $age) {
            $this->device($id, $age); $this->session($id, $id);
        }
        $this->assertSame(0, $this->snapshot()['count']);
    }

    public function testWindowBoundaryAndFreshAuthorizationCount(): void
    {
        foreach (['boundary' => 420, 'fresh' => 0] as $id => $age) {
            $this->device($id, $age); $this->session($id, $id);
        }
        $this->assertSame(2, $this->snapshot()['count']);
    }

    public function testExpiredLoggedOutAndUnapprovedDevicesAreExcluded(): void
    {
        $this->device('expired', 0); $this->session('expired', 'expired', 0);
        $this->device('logged-out', 0);
        $this->device('disabled', 0, 'panel_06', 'disabled'); $this->session('disabled', 'disabled');
        $this->assertSame(0, $this->snapshot()['count']);
    }

    public function testDevicesAndSessionsAreBothScopedToThisSite(): void
    {
        $this->device('same-id', 0); $this->session('foreign', 'same-id', 600, 'panel_01');
        $this->device('foreign-device', 0, 'panel_01'); $this->session('own', 'foreign-device');
        $this->assertSame(0, $this->snapshot()['count']);
        $this->session('valid', 'same-id');
        $this->assertSame(1, $this->snapshot()['count']);
    }

    public function testSnapshotMakesOnlyReadQueriesAndDoesNotPruneRecords(): void
    {
        $this->device('one', 0); $this->session('expired', 'one', -10); $this->session('valid', 'one');
        $before = $this->db->table(PanelStore::TABLE)->orderBy('id')->get()->toJson();
        $this->db->enableQueryLog();
        $this->assertSame(1, $this->snapshot()['count']);
        foreach ($this->db->getQueryLog() as $query) $this->assertMatchesRegularExpression('/\Aselect /i', $query['query']);
        $this->assertSame($before, $this->db->table(PanelStore::TABLE)->orderBy('id')->get()->toJson());
    }

    public function testDisabledModuleDoesNotReadGatewaySettingsOrDatabase(): void
    {
        $this->app['config']->set('panel_modules.enabled', false);
        unlink($this->directory . '/settings.json');
        $this->db->getSchemaBuilder()->drop(PanelStore::TABLE);
        $this->assertSame(['count' => 0, 'window_seconds' => null], $this->snapshot());
    }

    public function testUnavailableSettingsAndDatabaseReturnUnknownNotZero(): void
    {
        file_put_contents($this->directory . '/settings.json', 'broken');
        $this->assertNull($this->snapshot()['count']);
        file_put_contents($this->directory . '/settings.json', '{}');
        $this->db->getSchemaBuilder()->drop(PanelStore::TABLE);
        $this->assertNull($this->snapshot()['count']);
    }

    public function testHeartbeatWindowFollowsSettingsAndHasFiveMinuteDefault(): void
    {
        file_put_contents($this->directory . '/settings.json', '{}');
        $this->assertSame(420, $this->snapshot()['window_seconds']);
        file_put_contents($this->directory . '/settings.json', '{"heartbeatAfterSeconds":3600}');
        $this->assertSame(3720, $this->snapshot()['window_seconds']);
    }

    public function testMissingGatewayDoesNotBreakOtherDashboardStatistics(): void
    {
        foreach (['v2_user' => ['t', 'created_at'], 'v2_order' => ['created_at', 'status', 'total_amount', 'commission_status', 'invite_user_id', 'commission_balance'],
            'v2_ticket' => ['status', 'reply_status'], 'v2_commission_log' => ['created_at', 'get_amount']] as $table => $columns) {
            $this->db->getSchemaBuilder()->create($table, function ($schema) use ($columns) {
                $schema->increments('id'); foreach ($columns as $column) $schema->integer($column)->default(0);
            });
        }
        $this->db->table('v2_user')->insert(['t' => time(), 'created_at' => time() - 10]);
        $this->db->getSchemaBuilder()->drop(PanelStore::TABLE);
        $data = (new StatController())->getOverride(new Request())['data'];
        $this->assertSame(1, $data['online_user']);
        $this->assertSame(0, $data['day_income']);
        $this->assertNull($data['online_device']);
    }
}
