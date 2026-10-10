<?php

namespace Tests\Unit;

use App\Services\ClientGateway\Crypto;
use App\Services\ClientGateway\Gateway;
use App\Services\ClientGateway\GatewayException;
use App\Services\ClientGateway\PanelProxy;
use App\Services\ClientGateway\Store;
use App\Services\PanelTelegramService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PanelNativeModuleTest extends TestCase
{
    private $dir;
    private $gateway;
    private $store;
    private $rsa;
    private $key;
    private $panel;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/native-modules-' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/data', 0700, true);
        $app = new Application($this->dir);
        $app->instance('config', new Repository(['panel_modules' => ['enabled' => true, 'site' => 'pianyi',
            'gateway' => ['panel_id' => 'panel_06', 'directory' => $this->dir],
            'telegram' => ['driver' => 'local', 'directory' => $this->dir, 'chat_id' => '-123', 'username' => 'fixturebot']]]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->panel = ['id' => 'panel_06', 'name' => '便宜机场', 'status' => 'active', 'allowedClientNames' => ['便宜机场'],
            'panelUrl' => 'https://panel.example.invalid', 'gatewayTarget' => '127.0.0.1:443',
            'apiSign' => ['clientId' => 'windows', 'clientVersion' => '1.0.0', 'signSecret' => 'fixture-secret']];
        file_put_contents($this->dir . '/panels.json', Crypto::json(['panels' => [$this->panel]]));
        file_put_contents($this->dir . '/settings.json', '{}');
        $seed = random_bytes(32); $pair = sodium_crypto_sign_seed_keypair($seed);
        $pem = fn($der, $label) => "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
        file_put_contents($this->dir . '/data/license-private.pem', $pem(hex2bin('302e020100300506032b657004220420') . $seed, 'PRIVATE KEY'));
        file_put_contents($this->dir . '/data/license-public.pem', $pem(hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($pair), 'PUBLIC KEY'));
        config(['panel_modules.gateway.client_key_hash' => hash('sha256', trim(file_get_contents($this->dir . '/data/license-public.pem')))]);
        $this->key = sodium_crypto_sign_publickey($pair);
        $db = new \PDO('sqlite:' . $this->dir . '/data/gateway.sqlite3');
        $db->exec('CREATE TABLE devices(id TEXT PRIMARY KEY,data TEXT NOT NULL);
            CREATE TABLE sessions(id TEXT PRIMARY KEY,device_id TEXT NOT NULL,expires_at TEXT NOT NULL,data TEXT NOT NULL);
            CREATE TABLE panel_overrides(id TEXT PRIMARY KEY,data TEXT NOT NULL);
            CREATE TABLE audit_log(id INTEGER PRIMARY KEY,event TEXT NOT NULL,payload TEXT NOT NULL,created_at TEXT NOT NULL)');
        $db = null;
        $this->rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->store = new Store($this->dir . '/data/gateway.sqlite3');
        $this->gateway = new Gateway($this->dir);
    }

    protected function tearDown(): void
    {
        $this->store = null; $this->gateway = null;
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->dir);
    }

    private function body(array $extra = []): array
    {
        return array_replace(['clientId' => 'panel_06', 'clientName' => '便宜机场', 'runtimeName' => '便宜机场',
            'version' => '1.0.0', 'deviceId' => 'synthetic-device-123', 'secure' => ['publicKey' => openssl_pkey_get_details($this->rsa)['key']]], $extra);
    }

    private function call(string $endpoint, array $body = [], string $token = '', string $method = 'POST'): array
    {
        $r = Request::create('/', $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : ''], Crypto::json($body));
        return $this->gateway->handle($r, $endpoint);
    }

    private function open(array $envelope): array
    {
        $decode = fn($v) => base64_decode(strtr($v, '-_', '+/'));
        $signature = $decode($envelope['signature']); unset($envelope['signature']);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, Crypto::canonical($envelope), $this->key));
        openssl_pkey_export($this->rsa, $private);
        $p = proc_open(['/usr/bin/openssl', 'pkeyutl', '-decrypt', '-inkey', '/dev/fd/3', '-pkeyopt', 'rsa_padding_mode:oaep',
            '-pkeyopt', 'rsa_oaep_md:sha256', '-pkeyopt', 'rsa_mgf1_md:sha256'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']], $pipes);
        fwrite($pipes[3], $private); fclose($pipes[3]); fwrite($pipes[0], $decode($envelope['key'])); fclose($pipes[0]);
        $key = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($p));
        $plain = openssl_decrypt($decode($envelope['payload']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $decode($envelope['iv']), $decode($envelope['tag']), $decode($envelope['aad']));
        $this->assertIsString($plain);
        $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        $claim = $payload['data']['license'] ?? $payload['data']['grant'];
        $this->assertTrue(sodium_crypto_sign_verify_detached($decode($payload['data']['signature']), Crypto::canonical($claim), $this->key));
        return $payload['data'];
    }

    public function testInstallerGrantIsEncryptedSignedAndCreatesNoDevice(): void
    {
        [$status, $body] = $this->call('api/client/verify', $this->body(['requestType' => 'installer']));
        $this->assertSame(200, $status); $data = $this->open($body);
        $this->assertSame('installer', $data['requestType']); $this->assertSame('panel_06', $data['panelId']);
        $this->assertSame(0, $this->store->devices(0)['total']);
        $this->assertSame([], $this->store->query('SELECT * FROM sessions'));
    }

    public function testSessionHeartbeatAndLogout(): void
    {
        [$status, $body] = $this->call('api/client/verify', $this->body());
        $this->assertSame(200, $status); $data = $this->open($body);
        $token = $data['sessionToken'];
        $this->assertSame(200, $this->call('api/client/heartbeat', [], $token)[0]);
        $this->assertSame(200, $this->call('api/client/deactivate', [], $token)[0]);
        $this->assertSame(401, $this->call('api/client/heartbeat', [], $token)[0]);
    }

    public function testPanelDatabaseStorageKeepsAuthorizationAndAdminContracts(): void
    {
        $app = Container::getInstance();
        $capsule = new \Illuminate\Database\Capsule\Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance('db', $capsule->getDatabaseManager());
        require_once __DIR__ . '/../../database/migrations/2026_10_10_120000_create_client_gateway_table.php';
        (new \CreateClientGatewayTable())->up();
        config(['panel_modules.gateway.storage' => 'database']);
        $this->gateway = new Gateway($this->dir);
        $dbStore = new \App\Services\ClientGateway\PanelStore($capsule->getConnection(), 'panel_06');
        [$status, $body] = $this->call('api/client/verify', $this->body());
        $this->assertSame(200, $status); $token = $this->open($body)['sessionToken'];
        $this->assertSame(1, $dbStore->devices(0)['total']);
        $this->assertSame(0, $this->store->devices(0)['total']);
        $this->assertSame(200, $this->call('api/client/heartbeat', [], $token)[0]);
        $panel = $this->gateway->admin('GET', '/api/admin/panels', [])['panels'][0];
        $this->gateway->admin('POST', '/api/admin/panels/update', ['panelId' => 'panel_06', 'name' => $panel['name'],
            'clientName' => $panel['clientName'], 'status' => 'disabled', 'revision' => $panel['revision']]);
        $this->assertSame(403, $this->call('api/client/heartbeat', [], $token)[0]);
        $this->assertSame(200, $this->call('api/client/deactivate', [], $token)[0]);
        $this->assertSame(401, $this->call('api/client/heartbeat', [], $token)[0]);
        $capsule->getConnection()->disconnect();
    }

    public function testMalformedForeignAndUnencryptedVerifyNeverCreateDevice(): void
    {
        foreach ([['clientId' => 'panel_01'], ['clientName' => 'Other'], ['runtimeName' => 'Other'],
            ['version' => '0.0.0'], ['deviceId' => '../bad'], ['secure' => []], ['secure' => ['publicKey' => 'invalid']]] as $extra) {
            $this->assertGreaterThanOrEqual(400, $this->call('api/client/verify', $this->body($extra))[0]);
        }
        $this->assertSame(0, $this->store->devices(0)['total']);
    }

    public function testNoopSaveStaleSaveDisableAndHeartbeatNameTampering(): void
    {
        $panel = $this->gateway->admin('GET', '/api/admin/panels', [])['panels'][0];
        $save = ['panelId' => 'panel_06', 'name' => $panel['name'], 'clientName' => $panel['clientName'], 'status' => 'active', 'revision' => $panel['revision']];
        $this->gateway->admin('POST', '/api/admin/panels/update', $save);
        $this->assertSame([], $this->store->query('SELECT * FROM audit_log'));
        [, $envelope] = $this->call('api/client/verify', $this->body()); $token = $this->open($envelope)['sessionToken'];
        $this->assertSame(403, $this->call('api/client/heartbeat', ['clientName' => 'Other'], $token)[0]);
        $this->gateway->admin('POST', '/api/admin/panels/update', array_replace($save, ['status' => 'disabled']));
        $this->assertSame(403, $this->call('api/client/heartbeat', [], $token)[0]);
        $this->assertSame(403, $this->call('api/client/verify', $this->body())[0]);
        $this->expectException(HttpException::class); $this->gateway->admin('POST', '/api/admin/panels/update', $save);
    }

    public function testExpiredAndForeignSessionCannotAuthorize(): void
    {
        [, $envelope] = $this->call('api/client/verify', $this->body()); $token = $this->open($envelope)['sessionToken'];
        $id = hash('sha256', $token); $session = $this->store->get('sessions', $id);
        $session['panelId'] = 'panel_01'; $this->store->put('sessions', $id, $session);
        $this->assertSame(403, $this->call('api/client/heartbeat', [], $token)[0]);
        $session['panelId'] = 'panel_06'; $session['expiresAt'] = '2000-01-01T00:00:00.000Z'; $this->store->put('sessions', $id, $session);
        $this->assertSame(401, $this->call('api/client/heartbeat', [], $token)[0]);
    }

    public function testPublicPathsNeverExposeAdminOrUnknownBackend(): void
    {
        foreach (['api/admin/panels', 'api/admin/devices', 'api/bot/verify-user', 'api/gateway/panel/admin/config', 'api/gateway/panel/user/transfer'] as $endpoint) {
            $this->assertContains($this->call($endpoint, [], '', 'GET')[0], [403, 404]);
        }
    }

    public function testTransactionRollsBackAndMissingStoreNeverAutoCreates(): void
    {
        try { $this->store->transaction(function () { $this->store->put('devices', 'fixture', ['deviceId' => 'fixture']); throw new \RuntimeException('fixture'); }); }
        catch (\RuntimeException $e) { $this->assertSame('fixture', $e->getMessage()); }
        $this->assertSame(0, $this->store->devices(0)['total']);
        try { new Store($this->dir . '/missing.sqlite'); $this->fail('must refuse'); } catch (\RuntimeException $e) {}
        $this->assertFileDoesNotExist($this->dir . '/missing.sqlite');
    }

    public function testInvalidStorageCannotFallBackToASeparateDatabase(): void
    {
        config(['panel_modules.gateway.storage' => 'databse']);
        $this->expectException(\RuntimeException::class);
        new Gateway($this->dir);
    }

    public function testNativeProxyPinsDestinationAndSanitizesPayload(): void
    {
        $history = []; $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":{}}')]));
        $stack->push(Middleware::history($history)); $proxy = new PanelProxy(new Client(['handler' => $stack]));
        $request = Request::create('/', 'POST', [], [], [], ['HTTP_X_PANEL_AUTHORIZATION' => 'fixture-panel-token'],
            Crypto::json(['email' => 'test@example.invalid', 'password' => 'fixture', 'is_admin' => 1, 'site' => 'ovo']));
        [$status, $data] = $proxy->handle($request, '/passport/auth/login', $this->panel);
        $this->assertSame(200, $status); $this->assertInstanceOf(\stdClass::class, $data->data);
        $sent = $history[0]['request'];
        $this->assertSame('https://panel.example.invalid/api/v1/passport/auth/login', (string) $sent->getUri());
        $this->assertSame(['email' => 'test@example.invalid', 'password' => 'fixture'], json_decode((string) $sent->getBody(), true));
        $this->assertSame('fixture-panel-token', $sent->getHeaderLine('Authorization'));
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertNotEmpty($sent->getHeaderLine('X-Xiao-Sign'));
    }

    public function testProxyRejectsHtmlRedirectAndUnboundPayment(): void
    {
        foreach ([new Response(200, [], '<html>error</html>'), new Response(302, ['Location' => 'https://other.example'])] as $response) {
            $proxy = new PanelProxy(new Client(['handler' => new MockHandler([$response])]));
            try { $proxy->handle(Request::create('/', 'GET'), '/user/info', $this->panel); $this->fail('must reject'); }
            catch (GatewayException $e) { $this->assertSame(502, $e->status); }
        }
        $proxy = new PanelProxy(new Client(['handler' => new MockHandler([new Response(200, [], '{"data":{"payment_id":2}}')])]));
        $r = Request::create('/', 'POST', [], [], [], [], '{"trade_no":"123456","method":3}');
        $this->expectException(GatewayException::class); $proxy->handle($r, '/user/order/checkout', $this->panel);
    }

    private function telegram(): array
    {
        file_put_contents($this->dir . '/panel-sites.json', '[{"id":"pianyi","chat_ids":[-123]},{"id":"ovo","chat_ids":[-456]}]');
        $db = new \PDO('sqlite:' . $this->dir . '/bot_admin.sqlite3');
        $db->exec('CREATE TABLE groups(chat_id INTEGER PRIMARY KEY,title TEXT,enabled INTEGER,verify_join INTEGER,verify_message INTEGER,created_at INTEGER,updated_at INTEGER);
            CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
            CREATE TABLE pending_joins(chat_id INTEGER,expires_at INTEGER);
            CREATE TABLE events(id INTEGER PRIMARY KEY,event_type TEXT,chat_id INTEGER,chat_title TEXT,telegram_id INTEGER,username TEXT,display_name TEXT,action TEXT,reason TEXT,created_at INTEGER);
            INSERT INTO groups VALUES(-123,"Pianyi",1,1,1,1,1),(-456,"OvO",1,1,1,1,1);
            INSERT INTO events(id,chat_id,event_type) VALUES(1,-123,"own"),(2,-456,"foreign")');
        return [new PanelTelegramService(), $db];
    }

    public function testTelegramReadAndNoopSaveAreSiteScoped(): void
    {
        [$service, $db] = $this->telegram();
        $state = $service->request('GET', '/v1/state', []); $this->assertSame(-123, $state['group']['chat_id']);
        $items = $service->request('GET', '/v1/events', [])['items']; $this->assertCount(1, $items); $this->assertSame('own', $items[0]['event_type']);
        $save = ['enabled' => true, 'verify_join' => true, 'verify_message' => true, 'revision' => $state['group']['revision']];
        $this->assertFalse($service->request('POST', '/v1/group', $save)['changed']);
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn());
        $save['verify_join'] = false; $this->assertTrue($service->request('POST', '/v1/group', $save)['changed']);
        $this->assertSame(1, (int) $db->query('SELECT verify_join FROM groups WHERE chat_id=-456')->fetchColumn());
        $this->expectException(HttpException::class); $service->request('POST', '/v1/group', $save);
    }

    public function testTelegramRejectsForeignMappingAndMutationFields(): void
    {
        [$service] = $this->telegram();
        foreach (['chat_id', 'site', 'telegram_id', 'transfer_enable', 'token', 'bot_token_masked', 'telegram_bot_enable'] as $field) {
            try { $service->request('POST', '/v1/group', [$field => -456]); $this->fail('must reject'); }
            catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
        config(['panel_modules.telegram.chat_id' => '-456']);
        $this->expectException(HttpException::class); new PanelTelegramService();
    }

    public function testTelegramStateMasksSharedTokenWithoutLoadingItIntoEnvironment(): void
    {
        [$service, $db] = $this->telegram();
        $token = '1234567890:fixture_secret_that_must_stay_on_server';
        file_put_contents($this->dir . '/.env', "TELEGRAM_BOT_TOKEN=\"$token\"\nUNRELATED_SECRET=fixture-private\n");
        $envBefore = getenv('TELEGRAM_BOT_TOKEN');
        $state = $service->request('GET', '/v1/state', []);
        $this->assertSame('1234567890:' . str_repeat('*', 24), $state['bot_token_masked']);
        $this->assertSame(-123, $state['group']['chat_id']);
        $this->assertStringNotContainsString('fixture_secret', json_encode($state));
        $this->assertStringNotContainsString('fixture-private', json_encode($state));
        $this->assertSame($envBefore, getenv('TELEGRAM_BOT_TOKEN'));
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testTelegramStateDoesNotInventAMissingToken(): void
    {
        [$service] = $this->telegram();
        $this->assertSame('', $service->request('GET', '/v1/state', [])['bot_token_masked']);
        file_put_contents($this->dir . '/.env', "TELEGRAM_BOT_TOKEN=\n");
        $this->assertSame('', $service->request('GET', '/v1/state', [])['bot_token_masked']);
    }

    public function testTelegramMalformedEnvironmentDoesNotExposeSecretInError(): void
    {
        [$service] = $this->telegram();
        file_put_contents($this->dir . '/.env', "TELEGRAM_BOT_TOKEN=fixture_secret invalid_whitespace\n");
        try {
            $service->request('GET', '/v1/state', []);
            $this->fail('must reject malformed environment');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('fixture_secret', $e->getMessage());
            $this->assertTrue($e->getPrevious() === null, 'Configuration exceptions must not include the parser exception');
        }
    }

    public function testNodeInteroperabilityFixture(): void
    {
        $publicPath = getenv('NATIVE_INTEROP_PUBLIC');
        if (!$publicPath) { $this->markTestSkipped('Independent Node probe not requested'); }
        $body = $this->body(['secure' => ['publicKey' => file_get_contents($publicPath)]]);
        [$status, $envelope] = $this->call('api/client/verify', $body);
        $this->assertSame(200, $status);
        file_put_contents(getenv('NATIVE_INTEROP_OUTPUT'), Crypto::json(['envelope' => $envelope,
            'publicKey' => file_get_contents($this->dir . '/data/license-public.pem')]));
    }

    public function testEntryDomainConfigurationIsVerifiedAndPreservedByNormalSettings(): void
    {
        $history = [];
        $gateway = $this->gateway;
        $handler = new MockHandler([function ($request) use ($gateway) {
            parse_str($request->getUri()->getQuery(), $query);
            return new Response(200, [], Crypto::json($gateway->entryProbe($query['challenge'])));
        }]);
        $stack = HandlerStack::create($handler); $stack->push(Middleware::history($history));
        $entry = new class(new Client(['handler' => $stack])) extends \App\Services\ClientGateway\Entry {
            protected function provision(string $domain): void {}
            protected function addresses(string $domain): array { return ['8.8.8.8']; }
        };
        $before = $gateway->entryPanel();
        $saved = $entry->configure($gateway, 'https://entry.example.com/', $before['revision']);
        $this->assertSame('entry.example.com', $saved['entryDomain']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertTrue($history[0]['options']['verify']);
        $this->assertSame(['entry.example.com:443:8.8.8.8'], $history[0]['options']['curl'][CURLOPT_RESOLVE]);
        $updated = $gateway->admin('POST', '/api/admin/panels/update', ['panelId' => 'panel_06', 'name' => 'Renamed',
            'clientName' => '便宜机场', 'status' => 'active', 'revision' => $saved['revision']]);
        $this->assertSame('entry.example.com', $updated['entryDomain']);
    }

    public function testFailedEntryValidationAndStaleRevisionNeverSave(): void
    {
        $entry = new class(new Client(['handler' => new MockHandler([new Response(200, [], '{"claim":{"panelId":"panel_01"}}')])])) extends \App\Services\ClientGateway\Entry {
            protected function provision(string $domain): void {}
            protected function addresses(string $domain): array { return ['8.8.8.8']; }
        };
        $before = $this->gateway->entryPanel();
        try { $entry->configure($this->gateway, 'entry.example.com', $before['revision']); $this->fail('must reject forged probe'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame($before, $this->gateway->entryPanel());
        $this->assertSame([], $this->store->query('SELECT * FROM audit_log'));
        $this->expectException(HttpException::class);
        $entry->configure($this->gateway, 'entry.example.com', str_repeat('0', 64));
    }

    public function testSharedEntryRoutesNewAndMigratedSessions(): void
    {
        $gateway = $this->gateway; $entry = new \App\Services\ClientGateway\EntryRouter();
        $policy = ['sites' => [['panel_id' => 'panel_06'], ['panel_id' => 'panel_01']]];
        $gateway->configureEntry('entry.example.com', $gateway->entryPanel()['revision'], function () {});
        $request = Request::create('https://entry.example.com/api/client/verify', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], Crypto::json($this->body()));
        $this->assertSame('panel_06', $entry->target($request, 'api/client/verify', $policy));
        $request->attributes->set('native_gateway_entry', true);
        [$status, $envelope] = $gateway->handle($request, 'api/client/verify');
        $this->assertSame(200, $status); $data = $this->open($envelope);
        $this->assertStringStartsWith('panel_06.', $data['sessionToken']);
        $this->assertSame('/api/gateway/panel', $data['gateway']['panelBaseUrl']);
        $heartbeat = Request::create('/', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $data['sessionToken'], 'CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame('panel_06', $entry->target($heartbeat, 'api/client/heartbeat', $policy));
        $this->assertSame(200, $gateway->handle($heartbeat, 'api/client/heartbeat')[0]);
        foreach (['legacy-token', 'panel_01.foreign-token'] as $token) {
            $heartbeat->headers->set('Authorization', 'Bearer ' . $token);
            $this->assertSame('panel_01', $entry->target($heartbeat, 'api/client/heartbeat', $policy, [hash('sha256', 'legacy-token') => 'panel_01']));
            $this->assertSame(401, $gateway->handle($heartbeat, 'api/client/heartbeat')[0]);
        }
        $foreign = Request::create('https://entry.example.com/api/client/verify', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], Crypto::json($this->body(['clientId' => 'panel_01'])));
        $this->assertSame('panel_01', $entry->target($foreign, 'api/client/verify', $policy));
    }

    public function testImportedOpaqueSessionCanRenewAndDeactivateWithoutOldGateway(): void
    {
        $token = 'fixture-opaque-old-token'; $hash = hash('sha256', $token);
        $this->store->put('devices', 'synthetic-imported-device', ['deviceId' => 'synthetic-imported-device',
            'panelId' => 'panel_06', 'status' => 'approved', 'clientName' => '便宜机场', 'runtimeName' => '便宜机场']);
        $this->store->put('sessions', $hash, ['tokenHash' => $hash, 'panelId' => 'panel_06',
            'deviceId' => 'synthetic-imported-device', 'expiresAt' => Crypto::now(600)]);
        $this->assertSame(200, $this->call('api/client/heartbeat', [], $token)[0]);
        $this->assertSame(200, $this->call('api/client/deactivate', [], $token)[0]);
        $this->assertSame(401, $this->call('api/client/heartbeat', [], $token)[0]);
    }

    public function testEntryRejectsUnsafeDomains(): void
    {
        foreach (['http://example.com', '127.0.0.1', 'https://example.com/path', 'https://user@example.com',
            'example.com:443', 'example.com?x=1', '*.example.com', 'example.local', "example.com\ninclude bad"] as $value) {
            try { \App\Services\ClientGateway\Entry::domain($value); $this->fail('must reject ' . $value); }
            catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
    }
}
