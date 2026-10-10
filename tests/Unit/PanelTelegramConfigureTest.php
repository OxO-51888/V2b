<?php

namespace Tests\Unit;

use App\Services\PanelTelegramService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PanelTelegramConfigureTest extends TestCase
{
    private $dir;
    private $db;
    private $history = [];
    private const OLD_TOKEN = '111111111:fixture_old_token_abcdefghijklmnopqrstuvwxyz';
    private const NEW_TOKEN = '222222222:fixture_new_token_abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tg-configure-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/.env', 'TELEGRAM_BOT_TOKEN=' . self::OLD_TOKEN);
        file_put_contents($this->dir . '/panel-sites.json', '[{"id":"pianyi","chat_ids":[-123]},{"id":"ovo","chat_ids":[-456]}]');
        $app = new Application($this->dir);
        $app->instance('config', new Repository(['panel_modules' => ['site' => 'pianyi', 'telegram' =>
            ['directory' => $this->dir, 'username' => 'old_fixture_bot', 'chat_id' => '-123']]]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new \PDO('sqlite:' . $this->dir . '/bot_admin.sqlite3');
        $this->db->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
            CREATE TABLE groups(chat_id INTEGER PRIMARY KEY,title TEXT,enabled INTEGER,verify_join INTEGER,verify_message INTEGER,created_at INTEGER,updated_at INTEGER);
            CREATE TABLE pending_joins(chat_id INTEGER,expires_at INTEGER);
            CREATE TABLE events(id INTEGER PRIMARY KEY,event_type TEXT,chat_id INTEGER,chat_title TEXT,action TEXT,reason TEXT,created_at INTEGER);
            INSERT INTO groups VALUES(-123,"Pianyi",1,1,1,1,1),(-456,"OvO",1,1,1,1,1)');
    }

    protected function tearDown(): void
    {
        $this->db = null;
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        foreach (glob($this->dir . '/*') as $file) unlink($file);
        unlink($this->dir . '/.env'); rmdir($this->dir);
    }

    private function service(array $results = []): PanelTelegramService
    {
        $this->history = [];
        $responses = array_map(fn($result) => new Response(200, [], json_encode(['ok' => true, 'result' => $result])), $results);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new PanelTelegramService(new Client(['handler' => $stack]));
    }

    private function valid(string $id = '222222222', int $chatId = -789): array
    {
        return [['id' => (int) $id, 'is_bot' => true, 'username' => 'fixture_bot'], ['url' => ''],
            ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Fixture group'],
            ['status' => 'administrator', 'can_invite_users' => true, 'can_delete_messages' => true, 'can_restrict_members' => true]];
    }

    private function payload(PanelTelegramService $service, array $extra = []): array
    {
        return $extra + ['token' => self::NEW_TOKEN, 'chat_id' => '-789', 'enabled' => true, 'verify_join' => true,
            'verify_message' => true, 'revision' => $service->request('GET', '/v1/state', [])['group']['revision']];
    }

    private function unchanged(): void
    {
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    private function rejects(callable $callback, int $status = 422): void
    {
        try { $callback(); $this->fail('Invalid configuration was accepted'); }
        catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertStringNotContainsString(self::NEW_TOKEN, $e->getMessage());
        }
    }

    public function testIndependentTokenAndGroupPersistOnlyForOwnSite(): void
    {
        $service = $this->service($this->valid());
        $result = $service->request('POST', '/v1/configure', $this->payload($service));
        $this->assertTrue($result['configured']); $this->assertSame(-789, $result['group']['chat_id']);
        $keys = $this->db->query('SELECT key FROM settings')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['panel.connection.pianyi'], $keys);
        $this->assertSame(1, (int) $this->db->query('SELECT verify_join FROM groups WHERE chat_id=-456')->fetchColumn());
        $state = (new PanelTelegramService())->request('GET', '/v1/state', []);
        $this->assertSame(-789, $state['group']['chat_id']);
        $this->assertStringNotContainsString('fixture_new_token', json_encode($state));
        $this->assertFalse($state['connection_applied']);
        file_put_contents($this->dir . '/receiver-status.json', json_encode(['updated_at' => time(), 'sites' => ['pianyi' =>
            ['ready' => true, 'fingerprint' => hash('sha256', self::NEW_TOKEN . "\n-789")]]]));
        $this->assertTrue($service->request('GET', '/v1/state', [])['connection_applied']);
        $this->assertCount(4, $this->history);
        foreach ($this->history as $entry) {
            $this->assertSame('api.telegram.org', $entry['request']->getUri()->getHost());
            $this->assertFalse($entry['options']['allow_redirects']);
            $this->assertSame('v4', $entry['options']['force_ip_resolve']);
        }
    }

    public function testSameBotCanBeConfiguredWithoutChangingOtherSiteOrSendingMessages(): void
    {
        $service = $this->service($this->valid('111111111', -123));
        $result = $service->request('POST', '/v1/configure', $this->payload($service, ['token' => '', 'chat_id' => '-123']));
        $this->assertFalse($result['connection_changed']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM settings WHERE key="panel.connection.ovo"')->fetchColumn());
        $this->assertSame(['getMe', 'getWebhookInfo', 'getChat', 'getChatMember'],
            array_map(fn($entry) => basename($entry['request']->getUri()->getPath()), $this->history));
    }

    public function testGroupCollisionAndSharedBotKeyRotationAreRejectedBeforeNetwork(): void
    {
        foreach ([['chat_id' => '-456'], ['token' => '111111111:different_fixture_key_abcdefghijkl'], ['token' => '111111111:************************']] as $extra) {
            $service = $this->service();
            $this->rejects(fn() => $service->request('POST', '/v1/configure', $this->payload($service, $extra)));
            $this->assertCount(0, $this->history); $this->unchanged();
        }
    }

    public function testPermissionFailureWebhookConflictAndNetworkErrorPreserveOldState(): void
    {
        $cases = [$this->valid(), $this->valid(), []];
        $cases[0][3]['can_restrict_members'] = false;
        $cases[1][1]['url'] = 'https://other.example.invalid/hook';
        foreach ($cases as $results) {
            $service = $this->service($results);
            $this->rejects(fn() => $service->request('POST', '/v1/configure', $this->payload($service)));
            $this->unchanged();
        }
    }

    public function testFailureDuringCommitRollsBackConnectionAndGroup(): void
    {
        $this->db->exec("CREATE TRIGGER fail_event BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT,'fixture'); END");
        $service = $this->service($this->valid());
        try { $service->request('POST', '/v1/configure', $this->payload($service)); $this->fail('Expected rollback'); }
        catch (\PDOException $e) { $this->unchanged(); }
        $this->assertSame(-123, $service->request('GET', '/v1/state', [])['group']['chat_id']);
    }

    public function testStaleConfigurationAndForeignFieldsAreRejected(): void
    {
        $service = $this->service(); $payload = $this->payload($service);
        $this->db->exec('UPDATE groups SET updated_at=3 WHERE chat_id=-123');
        $this->rejects(fn() => $service->request('POST', '/v1/configure', $payload), 409);
        $this->rejects(fn() => $service->request('POST', '/v1/configure', $this->payload($service, ['site' => 'ovo'])));
        $this->assertCount(0, $this->history);
    }
}
