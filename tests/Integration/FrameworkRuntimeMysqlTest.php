<?php

namespace Tests\Integration;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramBindingEmail;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountingReceipt;
use App\Services\ClientGateway\PanelStore;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccountingRedis;
use Tests\TestCase;

class FrameworkRuntimeMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = json_decode(getenv('ORDER_TEST_CONNECTION') ?: '', true);
        if (($connection['driver'] ?? '') !== 'mysql'
            || ($connection['database'] ?? '') !== 'order_safety_test'
            || ($connection['host'] ?? null) !== ''
            || !preg_match('#^/tmp/order-safety-test-[a-f0-9]+/test.sock$#D', $connection['unix_socket'] ?? '')) {
            throw new \RuntimeException('Refusing non-isolated runtime fixture');
        }
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'runtime_fixture', 'database.connections.runtime_fixture' => $connection,
            'queue.failed.database' => 'runtime_fixture', 'outbound.enabled' => false,
            'panel_modules.enabled' => false, 'mail.driver' => 'array', 'v2board' => [
                'app_name' => 'Fixture', 'app_url' => 'https://fixture.invalid',
                'subscribe_url' => 'https://fixture.invalid', 'email_template' => 'default',
                'password_limit_enable' => 1, 'password_limit_count' => 2, 'password_limit_expire' => 1,
                'email_verify' => 0, 'recaptcha_enable' => 0, 'stop_register' => 0,
            ]]);
        DB::purge('runtime_fixture');
        // The installer contains DROP statements: only the guarded, socket-only fixture may use it.
        DB::unprepared(file_get_contents(database_path('install.sql')));
        foreach (['v2_hy2_user_revision', 'v2_hy2_online', 'v2_hy2_report_cursor'] as $table) Schema::dropIfExists($table);
        (require database_path('migrations/2026_10_25_000001_create_hy2_control_state.php'))->up();
        (require database_path('migrations/2026_10_25_000002_hy2_snapshot_revision.php'))->up();
        Schema::dropIfExists('v2_subscription_rule_log');
        Schema::create('v2_subscription_rule_log', function ($table) {
            $table->increments('id'); $table->integer('user_id'); $table->integer('rule_id')->nullable();
        });
        AccountingRedis::connect($this->app);
        $redisConnection = ['host' => getenv('ACCOUNTING_TEST_REDIS'), 'port' => 0, 'database' => 0, 'timeout' => 3];
        Redis::swap(new \Illuminate\Redis\RedisManager($this->app, 'phpredis', [
            'default' => $redisConnection, 'horizon' => $redisConnection,
        ]));
        Redis::flushdb();
        config(['cache.default' => 'redis', 'cache.stores.redis.connection' => 'default']);
        $this->app['cache']->forgetDriver();
    }

    public function testNativeSodiumNodeKeysRemainValidAndExistingKeysArePreserved(): void
    {
        foreach (['Vless', 'V2node'] as $type) {
            $controller = app('App\\Http\\Controllers\\V1\\Admin\\Server\\' . $type . 'Controller');
            $model = 'App\\Models\\Server' . $type;
            $params = ['name' => 'native-key-fixture', 'group_id' => [1], 'host' => 'node.example.invalid',
                'port' => 443, 'server_port' => 443, 'network' => 'tcp', 'tls' => 2, 'rate' => 1,
                'protocol' => 'vless', 'disable_sni' => 0, 'zero_rtt_handshake' => 0,
                'encryption' => 'mlkem768x25519plus'];
            $response = $controller->save(\Illuminate\Http\Request::create('/', 'POST', $params));
            $this->assertSame(200, $response->getStatusCode());
            $node = $model::firstOrFail();
            $tls = $node->tls_settings;
            $encryption = $node->encryption_settings;
            foreach ([[$tls['private_key'], $tls['public_key']], [$encryption['private_key'], $encryption['password']]] as [$private, $public]) {
                $secret = base64_decode(strtr($private, '-_', '+/'), true);
                $this->assertSame(32, strlen($secret));
                $this->assertSame(sodium_crypto_scalarmult_base($secret), base64_decode(strtr($public, '-_', '+/'), true));
            }
            $params += ['id' => $node->id, 'tls_settings' => $tls, 'encryption_settings' => $encryption];
            $this->assertSame(200, $controller->save(\Illuminate\Http\Request::create('/', 'POST', $params))->getStatusCode());
            $this->assertSame(1, $model::count());
            $this->assertSame($tls, $node->fresh()->tls_settings);
            $this->assertSame($encryption, $node->fresh()->encryption_settings);
        }
    }

    private function member(): User
    {
        return User::create(['email' => 'member@example.invalid', 'password' => password_hash('fixture-password', PASSWORD_BCRYPT),
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => str_repeat('a', 32),
            'transfer_enable' => 107374182400, 'u' => 123, 'd' => 456, 'expired_at' => null]);
    }

    private function login(string $password = 'fixture-password'): string
    {
        return $this->postJson('/api/v1/passport/auth/login', ['email' => 'member@example.invalid', 'password' => $password])
            ->assertOk()->json('data.auth_data');
    }

    private function migration(string $name, string $class): object
    {
        require_once database_path('migrations/' . $name . '.php');
        return new $class();
    }

    public function testExistingTablesSurviveRepeatedMigrationsAndGatewayUpserts(): void
    {
        $store = new PanelStore(DB::connection(), 'panel_01');
        $store->put('devices', 'CaseKey', ['deviceId' => 'fixture', 'version' => '1']);
        foreach ([
            ['2026_10_08_230000_create_community_identities_table', 'CreateCommunityIdentitiesTable'],
            ['2026_10_10_120000_create_client_gateway_table', 'CreateClientGatewayTable'],
            ['2026_10_12_120000_create_accounting_receipts', 'CreateAccountingReceipts'],
            ['2026_10_12_120100_add_order_status_scan_index', 'AddOrderStatusScanIndex'],
        ] as [$file, $class]) {
            $migration = $this->migration($file, $class);
            $migration->up(); $migration->up();
        }
        $store->put('devices', 'CaseKey', ['deviceId' => 'fixture', 'version' => '2']);
        $store->put('devices', 'casekey', ['deviceId' => 'other', 'version' => '3']);
        $this->assertSame('2', $store->get('devices', 'CaseKey')['version']);
        $this->assertSame(2, DB::table('v2_client_gateway')->count());
        $this->assertNull((new PanelStore(DB::connection(), 'panel_02'))->get('devices', 'CaseKey'));
        $this->assertCount(2, DB::select("SHOW INDEX FROM v2_order WHERE Key_name = 'idx_status_id'"));
        Schema::drop('v2_accounting_receipt');
        $this->migration('2026_10_12_120000_create_accounting_receipts', 'CreateAccountingReceipts')->up();
        app(AccountingReceipt::class)->assertReady();
        $this->assertTrue(app(AccountingReceipt::class)->once('fixture', str_repeat('a', 64), fn () => null));
        $this->assertFalse(app(AccountingReceipt::class)->once('fixture', str_repeat('a', 64), fn () => null));
    }

    public function testGatewayEntryProbeDoesNotLockOtherConnectionsOrOverwriteConcurrentSettings(): void
    {
        $store = new PanelStore(DB::connection(), 'panel_01');
        $store->put('panel_overrides', 'panel_01', ['name' => 'Original']);
        $beforeCount = DB::table(PanelStore::TABLE)->count();
        $class = new \ReflectionClass(\App\Services\ClientGateway\Gateway::class);
        $gateway = $class->newInstanceWithoutConstructor();
        $crypto = (new \ReflectionClass(\App\Services\ClientGateway\Crypto::class))->newInstanceWithoutConstructor();
        $crypto->publicPem = 'fixture-public-key';
        foreach (['store' => $store, 'crypto' => $crypto, 'base' => [
            'id' => 'panel_01', 'name' => 'Original', 'status' => 'active', 'allowedClientNames' => ['Fixture'],
            'panelUrl' => 'https://fixture.invalid', 'gatewayTarget' => '127.0.0.1:443',
        ]] as $key => $value) $class->getProperty($key)->setValue($gateway, $value);
        config(['database.connections.gateway_concurrent' => config('database.connections.runtime_fixture')]);
        $other = DB::connection('gateway_concurrent');
        $other->statement('SET SESSION innodb_lock_wait_timeout=1');
        try {
            $gateway->configureEntry('entry.example.com', $gateway->entryPanel()['revision'], function () use ($other) {
                $this->assertSame(0, DB::connection()->transactionLevel());
                (new PanelStore($other, 'panel_01'))->put('panel_overrides', 'panel_01', ['name' => 'Concurrent']);
            });
            $this->fail('stale entry must be rejected after the network probe');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            DB::purge('gateway_concurrent');
        }
        $this->assertSame('Concurrent', $gateway->entryPanel()['name']);
        $this->assertSame('', $gateway->entryPanel()['entryDomain']);
        $this->assertSame($beforeCount, DB::table(PanelStore::TABLE)->count());
    }

    public function testHttpLoginReadPasswordChangeAndSessionRevocation(): void
    {
        $user = $this->member();
        $auth = $this->login();
        $headers = ['Authorization' => $auth];
        $this->getJson('/api/v1/user/info', $headers)->assertOk()->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/v1/user/getSubscribe', $headers)->assertOk()->assertJsonPath('data.u', 123)->assertJsonPath('data.d', 456);
        $this->getJson('/api/v1/user/getStat', $headers)->assertOk()->assertJsonPath('data', [0, 0, 0]);
        $sessions = $this->getJson('/api/v1/user/getActiveSession', $headers)->assertOk()->json('data');
        $this->assertCount(1, $sessions);
        $this->assertArrayNotHasKey('auth_data', array_values($sessions)[0]);
        $this->postJson('/api/v1/user/changePassword', ['old_password' => 'fixture-password', 'new_password' => 'new-fixture-password'], $headers)
            ->assertOk()->assertJsonPath('data', true);
        $this->getJson('/api/v1/user/info', $headers)->assertStatus(403);
        $newAuth = $this->login('new-fixture-password');
        $sessions = $this->getJson('/api/v1/user/getActiveSession', ['Authorization' => $newAuth])->assertOk()->json('data');
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => array_key_first($sessions)], ['Authorization' => $newAuth])->assertOk();
        $this->getJson('/api/v1/user/info', ['Authorization' => $newAuth])->assertStatus(403);
        $this->assertSame([123, 456, 107374182400], array_values($user->fresh()->only(['u', 'd', 'transfer_enable'])));
    }

    public function testLoginCooldownUsesSecondsAndMalformedIdentityIsRejected(): void
    {
        $this->member();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/passport/auth/login', ['email' => 'member@example.invalid', 'password' => 'wrong-password'])
                ->assertStatus(422)->assertJsonPath('message', __('Incorrect email or password'));
        }
        $key = Cache::getStore()->getPrefix() . CacheKey::get('PASSWORD_ERROR_LIMIT', 'member@example.invalid');
        $ttl = Redis::ttl($key);
        $this->assertGreaterThan(0, $ttl); $this->assertLessThanOrEqual(60, $ttl);
        $this->postJson('/api/v1/passport/auth/login', ['email' => 'member@example.invalid', 'password' => 'fixture-password'])
            ->assertStatus(429)->assertJsonPath('message', __('There are too many password errors, please try again after :minute minutes.', ['minute' => 1]));
        Redis::expire($key, 1); sleep(2);
        $this->assertNotEmpty($this->login());
        $this->getJson('/api/v1/user/info?auth_data[id]=1&auth_data[is_admin]=1')->assertStatus(403);
        $this->postJson('/api/v1/passport/auth/login', ['email' => ['nested'], 'password' => 'fixture-password'])->assertStatus(422);
    }

    public function testHttpPlanAndOrderCreateCancelPreserveAccounting(): void
    {
        $user = $this->member();
        $plan = Plan::create(['group_id' => 1, 'name' => 'Fixture plan', 'transfer_enable' => 100,
            'show' => 1, 'renew' => 1, 'month_price' => 100, 'onetime_price' => 200]);
        $headers = ['Authorization' => $this->login()];
        $this->getJson('/api/v1/user/plan/fetch', $headers)->assertOk();
        $trade = $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'], $headers)
            ->assertOk()->json('data');
        $this->getJson('/api/v1/user/order/detail?trade_no=' . $trade, $headers)->assertOk()->assertJsonPath('data.total_amount', 100);
        $this->getJson('/api/v1/user/order/check?trade_no=' . $trade, $headers)->assertOk()->assertJsonPath('data', 0);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $trade], $headers)->assertOk();
        $this->assertSame(2, (int)DB::table('v2_order')->where('trade_no', $trade)->value('status'));
        $this->assertSame([123, 456, 107374182400], array_values($user->fresh()->only(['u', 'd', 'transfer_enable'])));
    }

    public function testHttpRegistrationAndPasswordRecoveryConsumeVerificationOnce(): void
    {
        config(['v2board.email_verify' => 1]);
        $key = CacheKey::get('EMAIL_VERIFY_CODE', 'member@example.invalid');
        Cache::put($key, '123456', 300);
        $auth = $this->postJson('/api/v1/passport/auth/register', ['email' => 'member@example.invalid',
            'password' => 'fixture-password', 'email_code' => '123456', 'is_admin' => 1, 'is_staff' => 1])
            ->assertOk()->json('data.auth_data');
        $user = User::firstOrFail();
        $this->assertSame(0, (int)$user->is_admin);
        $this->assertSame(0, (int)$user->is_staff);
        $this->assertNull(Cache::get($key));
        $this->getJson('/api/v1/user/info', ['Authorization' => $auth])->assertOk();
        Cache::put($key, '654321', 300);
        $payload = ['email' => 'member@example.invalid', 'password' => 'recovered-password', 'email_code' => '654321'];
        $this->postJson('/api/v1/passport/auth/forget', $payload)->assertOk()->assertJsonPath('data', true);
        $this->getJson('/api/v1/user/info', ['Authorization' => $auth])->assertStatus(403);
        $this->assertNotEmpty($this->login('recovered-password'));
        $this->postJson('/api/v1/passport/auth/forget', $payload)->assertStatus(422)
            ->assertJsonPath('message', __('Incorrect email verification code'));
        $this->assertSame(1, User::count());
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
    }

    public function testActualRedisWorkerConsumesEncryptedMailAndNativeBindingMailWithoutSending(): void
    {
        config(['queue.default' => 'redis']);
        SendEmailJob::dispatch(['email' => 'recipient@example.invalid', 'subject' => 'Fixture', 'template_name' => 'verify',
            'template_value' => ['name' => 'Fixture', 'code' => '846291', 'url' => 'https://fixture.invalid']]);
        config(['telegram_binding.mail_enabled' => false]);
        SendTelegramBindingEmail::dispatch(123, 'fixture-challenge', '976421');
        $payload = Redis::lindex('queues:send_email', 0);
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('846291', $payload);
        $this->assertStringNotContainsString('recipient@example.invalid', $payload);
        $this->assertSame(2, Queue::connection('redis')->size('send_email'));
        foreach (Redis::lrange('queues:send_email', 0, -1) as $queued) {
            $this->assertStringNotContainsString('976421', $queued);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'send_email', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        }
        $this->assertSame(0, Queue::connection('redis')->size('send_email'));
        $this->assertSame(0, Queue::connection('redis')->size('send_telegram'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('v2_mail_log')->count());
        $this->assertNull(DB::table('v2_mail_log')->value('error'));
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
    }

    public function testLegacyRedisPayloadsAndLocksWorkWithoutObjectDeserialization(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
        $this->assertSame('php', config('session.serialization'));
        $payload = ['sessions' => ['fixture' => ['login_at' => time()]], 'quota' => 107374182400];
        $key = Cache::getStore()->getPrefix() . 'legacy-fixture';
        Redis::setex($key, 60, serialize($payload));
        $this->assertSame($payload, Cache::get('legacy-fixture'));
        $lock = Cache::lock('runtime-lock', 5);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('runtime-lock', 5)->get());
        $this->assertTrue($lock->release());
        $this->assertTrue(Cache::lock('runtime-lock', 5)->get());
        Redis::setex(Cache::getStore()->getPrefix() . 'object-fixture', 60, serialize((object)['unsafe' => true]));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('object-fixture'));
        $this->assertSame($payload, Cache::get('legacy-fixture'));
    }

    public function testScheduledCommandsRunAgainstInstalledSchema(): void
    {
        foreach (['traffic:update', 'check:order', 'check:commission', 'check:ticket', 'check:renewal',
            'reset:traffic', 'reset:log', 'send:remindMail', 'v2board:statistics'] as $command) {
            $this->assertSame(0, Artisan::call($command), $command . ': ' . Artisan::output());
        }
        $this->assertSame(0, DB::table('v2_user')->count());
        $this->assertSame(0, DB::table('v2_log')->whereIn('level', ['WARNING', 'ERROR'])->count());
    }

    public function testSecuritySubscriptionTokenRequiresExactStringCredential(): void
    {
        $user = $this->member();
        $user->update(['token' => '1abcdef0123456789abcdef012345678']);
        $this->assertSame($user->token, $user->fresh()->token);
        foreach ([1, true, [$user->token], strtoupper($user->token)] as $token) {
            $response = $this->json('GET', '/api/v1/client/app/getVersion', ['token' => $token]);
            $response->assertStatus(403);
        }
        $this->getJson('/api/v1/client/app/getVersion?token=' . $user->token)->assertOk();
        $this->assertSame($user->token, $user->fresh()->token);
    }

    public function testSecuritySubscriptionTemporaryModesKeepValidTokensWorking(): void
    {
        $user = $this->member();
        $middleware = new \App\Http\Middleware\Client();
        $resolve = fn ($token) => $middleware->handle(\Illuminate\Http\Request::create('/fixture', 'GET', ['token' => $token]),
            fn ($request) => $request->user->id);
        config(['v2board.show_subscribe_method' => 1]);
        Cache::put('otpn_fixture-otp', $user->token, 60);
        Cache::put('otp_' . $user->token, 'fixture-otp', 60);
        $this->assertSame($user->id, $resolve('fixture-otp'));
        $this->assertFalse(Cache::has('otpn_fixture-otp'));
        config(['v2board.show_subscribe_method' => 2, 'v2board.show_subscribe_expire' => 5]);
        $bytes = pack('N*', 0) . pack('N*', (int)floor(time() / 300));
        $token = \App\Utils\Helper::base64EncodeUrlSafe($user->id . ':' . hash_hmac('sha1', $bytes, $user->token));
        $this->assertSame($user->id, $resolve($token));
        $this->assertSame($user->id, $resolve($token));
    }

    public function testSecuritySubscriptionConfigNeverLeaksCredentialsForNumericToken(): void
    {
        $user = $this->member();
        $user->update(['token' => '1abcdef0123456789abcdef012345678', 'group_id' => 1]);
        \App\Models\ServerTrojan::create(['group_id' => [1], 'name' => 'fixture-node', 'host' => 'node.example.invalid',
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'show' => 1]);
        $valid = $this->getJson('/api/v1/client/app/getConfig?token=' . $user->token);
        $this->assertSame(200, $valid->status(), $valid->getContent());
        $valid->assertSee($user->uuid);
        $this->json('GET', '/api/v1/client/app/getConfig', ['token' => 1])->assertStatus(403)->assertDontSee($user->uuid);
    }

    public function testSecurityGiftcardCannotBeGuessedWithNumericJsonAndValidRedemptionStillWorks(): void
    {
        $user = $this->member();
        $gift = \App\Models\Giftcard::create(['code' => '1private-gift-fixture', 'name' => 'fixture', 'type' => 1,
            'value' => 100, 'limit_use' => 1, 'started_at' => time() - 60, 'ended_at' => time() + 600]);
        $headers = ['Authorization' => $this->login()];
        foreach ([1, true, [$gift->code]] as $code) {
            $this->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $code], $headers)->assertStatus(422);
        }
        $this->assertSame(0, (int)$user->fresh()->balance);
        $this->assertSame(1, (int)$gift->fresh()->limit_use);
        $this->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $gift->code], $headers)->assertOk();
        $this->assertSame(100, (int)$user->fresh()->balance);
        $this->assertSame(0, (int)$gift->fresh()->limit_use);
    }

    public function testSecurityCouponCodeCannotUseMysqlNumericCoercion(): void
    {
        $this->member();
        $coupon = \App\Models\Coupon::create(['code' => '1private-coupon-fixture', 'name' => 'Fixture', 'type' => 1,
            'value' => 10, 'show' => 1, 'limit_use' => 3, 'started_at' => time() - 60, 'ended_at' => time() + 600]);
        $headers = ['Authorization' => $this->login()];
        foreach ([1, true, [$coupon->code]] as $code) {
            $this->postJson('/api/v1/user/coupon/check', ['code' => $code], $headers)->assertStatus(500)->assertDontSee($coupon->code);
        }
        $this->postJson('/api/v1/user/coupon/check', ['code' => $coupon->code], $headers)->assertOk()->assertJsonPath('data.code', $coupon->code);
        $order = new \App\Models\Order(['total_amount' => 100, 'user_id' => 1, 'plan_id' => 1, 'period' => 'month_price']);
        $this->assertTrue((new \App\Services\CouponService($coupon->code))->use($order));
        $this->assertSame(10, $order->discount_amount);
        $this->assertSame(2, (int)$coupon->fresh()->limit_use);
    }

    public function testSecurityInviteOnlyRegistrationRequiresStringCode(): void
    {
        $owner = $this->member();
        $invite = new \App\Models\InviteCode();
        $invite->user_id = $owner->id;
        $invite->code = '1abcdefg';
        $invite->status = 0;
        $invite->save();
        config(['v2board.invite_force' => 1]);
        $params = ['email' => 'invited@example.invalid', 'password' => 'fixture-password'];
        $this->postJson('/api/v1/passport/auth/register', $params + ['invite_code' => 1])->assertStatus(422);
        $this->assertSame(1, User::count());
        $this->postJson('/api/v1/passport/auth/register', $params + ['invite_code' => $invite->code])->assertOk();
        $this->assertSame($owner->id, User::where('email', $params['email'])->value('invite_user_id'));
    }

    public function testHy2NodeAuthenticationAndConditionalJsonReplaceRetiredNodeEndpoints(): void
    {
        $user = $this->member();
        $user->update(['group_id' => 1]);
        $node = \App\Models\ServerV2node::create(['group_id' => [1], 'name' => 'Fixture', 'host' => 'node.example.invalid',
            'port' => '443', 'server_port' => 443, 'protocol' => 'hysteria2', 'rate' => 1]);
        config(['v2board.server_token' => 'fixture-node-secret']);
        foreach (['/api/v1/server/UniProxy/user', '/api/v2/server/config'] as $path) {
            $this->getJson($path . '?token=fixture-node-secret&node_id=' . $node->id)->assertNotFound();
        }
        $path = '/api/v2/server/hy2/users';
        $this->getJson($path)->assertStatus(426)->assertJsonPath('error', 'contract_mismatch');
        $this->getJson($path, ['X-V2node-Contract' => 'hy2-1'])->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
        $headers = ['Authorization' => 'Bearer fixture-node-secret', 'X-Node-ID' => (string) $node->id];
        $this->getJson($path, $headers)->assertStatus(426);
        $headers['X-V2node-Contract'] = 'hy2-1';
        $json = $this->getJson($path, $headers)->assertOk()->assertJsonPath('users.0.uuid', $user->uuid);
        $etag = $json->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getJson($path, $headers + ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
    }

    public function testSecurityAllRegisteredRoleRoutesRejectForgedAndLowerRoles(): void
    {
        $member = $this->member();
        $memberAuth = $this->login();
        $staff = User::create(['email' => 'staff@example.invalid', 'password' => password_hash('fixture-password', PASSWORD_BCRYPT),
            'uuid' => 'staff-fixture', 'token' => str_repeat('b', 32), 'is_staff' => 1]);
        $staffAuth = (new \App\Services\AuthService($staff))->generateAuthData(\Illuminate\Http\Request::create('/'))['auth_data'];
        $cases = 0;
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->middleware();
            $role = in_array('admin', $middleware, true) ? 'admin' :
                (in_array('staff', $middleware, true) ? 'staff' : (in_array('user', $middleware, true) ? 'user' : null));
            if (!$role) continue;
            $uri = '/' . preg_replace('/\{[^}]+\}/', 'fixture', $route->uri());
            $actors = [null];
            if ($role !== 'user') $actors[] = $memberAuth;
            if ($role === 'admin') $actors[] = $staffAuth;
            foreach ($actors as $auth) {
                $this->flushHeaders();
                $params = ['user' => ['id' => $staff->id, 'is_admin' => 1, 'is_staff' => 1]];
                if ($auth === null) $params['auth_data'] = ['id' => $staff->id, 'is_admin' => 1];
                $this->json($route->methods()[0], $uri, $params, $auth ? ['Authorization' => $auth] : [])
                    ->assertStatus(403);
                $cases++;
            }
        }
        $this->assertGreaterThan(400, $cases);
        $this->assertSame(0, (int)$member->fresh()->is_admin);
        $this->assertSame(0, DB::table('v2_order')->count());
        $this->assertSame(0, DB::table('v2_ticket')->count());
    }

    public function testSecurityUsersCannotReadOrChangeAnotherUsersResources(): void
    {
        $owner = $this->member();
        $other = User::create(['email' => 'other@example.invalid', 'password' => 'fixture-hash', 'uuid' => 'other-fixture', 'token' => str_repeat('c', 32)]);
        $order = \App\Models\Order::create(['user_id' => $other->id, 'plan_id' => 0, 'period' => 'deposit', 'trade_no' => 'other-private-order', 'total_amount' => 100]);
        $ticket = \App\Models\Ticket::create(['user_id' => $other->id, 'subject' => 'private-subject', 'level' => 1]);
        \App\Models\TicketMessage::create(['user_id' => $other->id, 'ticket_id' => $ticket->id, 'message' => 'private-ticket-message']);
        $headers = ['Authorization' => $this->login()];
        foreach (['/user/info?user[id]=' . $other->id, '/user/info?user_id=' . $other->id] as $uri) {
            $this->getJson('/api/v1' . $uri, $headers)->assertOk()->assertJsonPath('data.email', $owner->email);
        }
        foreach (['/user/order/detail?trade_no=' . $order->trade_no, '/user/order/check?trade_no=' . $order->trade_no,
            '/user/ticket/fetch?id=' . $ticket->id] as $uri) {
            $response = $this->getJson('/api/v1' . $uri, $headers);
            $this->assertGreaterThanOrEqual(400, $response->status());
            $response->assertDontSee('private-ticket-message');
        }
        foreach (['/user/order/cancel' => ['trade_no' => $order->trade_no],
            '/user/order/checkout' => ['trade_no' => $order->trade_no],
            '/user/ticket/close' => ['id' => $ticket->id],
            '/user/ticket/reply' => ['id' => $ticket->id, 'message' => 'forbidden']] as $uri => $params) {
            $response = $this->postJson('/api/v1' . $uri, $params + ['user' => ['id' => $other->id]], $headers);
            $this->assertGreaterThanOrEqual(400, $response->status());
        }
        $this->postJson('/api/v1/user/update', ['auto_renewal' => 1, 'is_admin' => 1, 'is_staff' => 1,
            'balance' => 999999, 'user' => ['id' => $other->id]], $headers)->assertOk();
        $this->assertSame(0, (int)$owner->fresh()->is_admin);
        $this->assertSame(0, (int)$owner->fresh()->balance);
        $this->assertSame(0, (int)$order->fresh()->status);
        $this->assertSame(0, (int)$ticket->fresh()->status);
        $this->assertSame(1, \App\Models\TicketMessage::count());
    }

    public function testSecurityStaffCannotPromoteAccountsOrModifyProtectedRoles(): void
    {
        $member = $this->member();
        $staff = User::create(['email' => 'operator@example.invalid', 'password' => 'fixture-hash',
            'uuid' => 'operator-fixture', 'token' => str_repeat('d', 32), 'is_staff' => 1]);
        $admin = User::create(['email' => 'admin@example.invalid', 'password' => 'admin-private-hash',
            'uuid' => 'admin-fixture', 'token' => str_repeat('e', 32), 'is_admin' => 1]);
        $auth = (new \App\Services\AuthService($staff))->generateAuthData(\Illuminate\Http\Request::create('/'))['auth_data'];
        $headers = ['Authorization' => $auth];
        foreach ([$staff, $admin] as $target) {
            $this->postJson('/api/v1/staff/user/update', ['id' => $target->id, 'email' => $target->email,
                'banned' => 0, 'password' => 'unauthorized-password'], $headers)->assertStatus(500);
            $this->getJson('/api/v1/staff/user/getUserInfoById?id=' . $target->id, $headers)->assertStatus(500);
            $this->assertSame($target->password, $target->fresh()->password);
        }
        $this->postJson('/api/v1/staff/user/update', ['id' => $member->id, 'email' => $member->email,
            'banned' => 0, 'is_admin' => 1, 'is_staff' => 1, 'discount' => 5], $headers)->assertOk();
        $this->assertSame(5, (int)$member->fresh()->discount);
        $this->assertSame(0, (int)$member->fresh()->is_admin);
        $this->assertSame(0, (int)$member->fresh()->is_staff);
        $this->assertSame($member->transfer_enable, $member->fresh()->transfer_enable);
        $this->getJson('/api/v1/staff/user/getUserInfoById?id=' . $member->id, $headers)
            ->assertOk()->assertDontSee($member->password)->assertJsonMissingPath('data.password_salt');
    }

    public function testSecurityGiftcardInternalFailureRollsBackWithoutLeakingDetails(): void
    {
        config(['app.debug' => false]);
        $user = $this->member();
        $gift = \App\Models\Giftcard::create(['code' => 'gift-rollback-fixture', 'name' => 'fixture', 'type' => 1,
            'value' => 100, 'limit_use' => 1, 'started_at' => time() - 60, 'ended_at' => time() + 600]);
        $headers = ['Authorization' => $this->login()];
        User::updating(static function () { throw new \RuntimeException('SQLSTATE[23000]: private-gift-query'); });
        try {
            $this->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $gift->code], $headers)
                ->assertStatus(500)->assertDontSee('SQLSTATE')->assertDontSee('private-gift-query');
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(1, (int)$gift->fresh()->limit_use);
            $this->assertSame(0, \App\Models\GiftcardRedemption::count());
            $this->assertSame(0, DB::connection()->transactionLevel());
        } finally {
            User::flushEventListeners();
        }
    }

    public function testSecurityTicketErrorsDoNotExposeSqlOrCredentialsAndRollback(): void
    {
        config(['app.debug' => false]);
        $this->member();
        $headers = ['Authorization' => $this->login()];
        \App\Models\TicketMessage::creating(static function () {
            throw new \RuntimeException('SQLSTATE[23000]: fixture-secret-query /private/config.php');
        });
        try {
            $response = $this->postJson('/api/v1/user/ticket/save', ['subject' => 'Fixture', 'level' => 1, 'message' => 'Fixture'], $headers);
            $response->assertStatus(500)->assertDontSee('SQLSTATE')->assertDontSee('fixture-secret-query')->assertDontSee('/private/config.php');
            $this->assertSame(0, \App\Models\Ticket::count());
            $this->assertSame(0, DB::connection()->transactionLevel());
        } finally {
            \App\Models\TicketMessage::flushEventListeners();
        }
    }

    public function testSecurityNormalTicketValidationAndMissingKnowledgeRemainReadable(): void
    {
        $user = $this->member();
        $headers = ['Authorization' => $this->login()];
        config(['v2board.ticket_status' => 2]);
        $this->postJson('/api/v1/user/ticket/save', ['subject' => 'Fixture', 'level' => 1, 'message' => 'Fixture'], $headers)
            ->assertStatus(500)->assertJsonPath('message', __('当前套餐不允许发起工单'));
        $this->getJson('/api/v1/user/knowledge/fetch?id=999999', $headers)
            ->assertStatus(500)->assertJsonPath('message', __('Article does not exist'));
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(0, \App\Models\Ticket::count());
    }
}
