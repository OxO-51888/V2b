<?php

namespace Tests\Unit;

use App\Services\ClientGateway\PanelStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/2026_10_10_120000_create_client_gateway_table.php';

class PanelGatewayDatabaseTest extends TestCase
{
    private $db;
    private $own;
    private $other;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository());
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        (new \CreateClientGatewayTable())->up();
        $this->own = new PanelStore($this->db, 'panel_06');
        $this->other = new PanelStore($this->db, 'panel_01');
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
    }

    public function testMigrationAddsOnlyOneTableAndIndexes(): void
    {
        $tables = array_column($this->db->select("SELECT name FROM sqlite_master WHERE type='table' AND name<>'sqlite_sequence'"), 'name');
        $this->assertSame(['v2_client_gateway'], $tables);
        $indexes = array_column($this->db->select("PRAGMA index_list('v2_client_gateway')"), 'name');
        foreach (['client_gateway_record', 'client_gateway_expiry', 'client_gateway_list'] as $index) $this->assertContains($index, $indexes);
    }

    public function testRecordsAndPaginationAreSiteScopedAndRedacted(): void
    {
        foreach (range(1, 35) as $i) $this->own->put('devices', 'device-' . $i, ['deviceId' => 'device-' . $i, 'panelId' => 'panel_06', 'secret' => 'hidden']);
        $this->other->put('devices', 'device-1', ['deviceId' => 'foreign', 'panelId' => 'panel_01']);
        $this->assertSame(35, $this->own->devices(0)['total']);
        $this->assertCount(30, $this->own->devices(0)['items']);
        $this->assertCount(5, $this->own->devices(30)['items']);
        $this->assertSame('device-35', $this->own->devices(0)['items'][0]['deviceId']);
        $this->assertSame('device-1', $this->own->get('devices', 'device-1')['deviceId']);
        $this->assertStringNotContainsString('secret', json_encode($this->own->devices(0)));
    }

    public function testSessionPruningAndLogoutDoNotTouchOtherSite(): void
    {
        foreach ([[$this->own, 'panel_06'], [$this->other, 'panel_01']] as [$store, $panel]) {
            $store->put('sessions', 'expired', ['panelId' => $panel, 'expiresAt' => '2000-01-01T00:00:00.000Z']);
            $store->put('sessions', 'live', ['panelId' => $panel, 'expiresAt' => '2099-01-01T00:00:00.000Z']);
        }
        $this->own->pruneSessions('2026-10-10T00:00:00.000Z');
        $this->assertNull($this->own->get('sessions', 'expired'));
        $this->assertNotNull($this->other->get('sessions', 'expired'));
        $this->own->deleteSession('live');
        $this->assertNull($this->own->get('sessions', 'live'));
        $this->assertNotNull($this->other->get('sessions', 'live'));
    }

    public function testUpsertRollbackAndCaseSensitiveKeys(): void
    {
        $this->own->put('devices', 'Device', ['deviceId' => 'first']);
        $this->own->put('devices', 'device', ['deviceId' => 'second']);
        $this->own->put('devices', 'Device', ['deviceId' => 'updated']);
        $this->assertSame(2, $this->own->devices(0)['total']);
        $this->assertSame('updated', $this->own->get('devices', 'Device')['deviceId']);
        try {
            $this->own->transaction(function () { $this->own->put('devices', 'rolled-back', ['deviceId' => 'rollback']); throw new \RuntimeException('fixture'); });
            $this->fail('must throw');
        } catch (\RuntimeException $e) { $this->assertSame('fixture', $e->getMessage()); }
        $this->assertNull($this->own->get('devices', 'rolled-back'));
    }

    public function testForeignAndUnknownWritesAreRejected(): void
    {
        foreach ([['panel_overrides', 'panel_01', []], ['devices', 'x', ['panelId' => 'panel_01']],
            ['sessions', 'x', ['panelId' => 'panel_01']], ['v2_user', 'x', []]] as [$type, $key, $data]) {
            try { $this->own->put($type, $key, $data); $this->fail('must refuse'); }
            catch (\InvalidArgumentException $e) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, $this->db->table(PanelStore::TABLE)->count());
    }

    public function testAuditUsesOnlyTheAuthorizationTable(): void
    {
        $this->own->audit('test.fixture', ['panelId' => 'panel_06']);
        $row = $this->db->table(PanelStore::TABLE)->first();
        $this->assertSame('audit_log', $row->record_type);
        $this->assertSame('panel_06', $row->panel_id);
        $this->assertSame('test.fixture', json_decode($row->data, true)['event']);
        $this->assertSame(0, $this->own->devices(0)['total']);
    }
}
