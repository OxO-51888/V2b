<?php

namespace Tests\Unit;

use App\Http\Middleware\TelegramBotAuth;
use App\Http\Routes\V1\TelegramBindingRoute;
use App\Jobs\SendTelegramBindingEmail;
use App\Models\User;
use App\Models\Plan;
use App\Services\TelegramBindingService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramBindingTest extends TestCase
{
    private $app;
    private $service;
    private $jobs;
    private $macros;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir() . '/tg-binding-isolated-test');
        $this->app->instance('config', new Config([
            'app' => ['key' => str_repeat('k', 32)],
            'v2board' => ['app_name' => 'Isolated test', 'subscribe_url' => 'https://sub.example.invalid'],
            'telegram_binding' => ['enabled' => true, 'mail_enabled' => true, 'secret' => str_repeat('s', 48)],
        ]));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $capsule = new Capsule($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Capsule::schema()->create('v2_user', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->bigInteger('telegram_id')->nullable();
            $table->integer('banned')->default(0);
            $table->bigInteger('u')->default(123);
            $table->bigInteger('d')->default(456);
            $table->bigInteger('transfer_enable')->default(0);
            $table->integer('plan_id')->nullable();
            $table->bigInteger('expired_at')->nullable();
            $table->string('token')->default('fixture-token');
            $table->string('uuid')->default('fixture-uuid');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Capsule::schema()->create('v2_plan', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('transfer_enable')->default(1);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->jobs = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($job) { $this->jobs[] = $job; });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $macros = new \ReflectionProperty(Request::class, 'macros'); $macros->setAccessible(true);
        $this->macros = $macros->getValue();
        $validator = new Validator(new Translator(new ArrayLoader(), 'en'), $this->app);
        Request::macro('validate', function (array $rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn($data) => new JsonResponse($data));
        $this->app->instance(ResponseFactory::class, $responses);
        $this->service = new TelegramBindingService();
        User::create(['email' => 'member@example.invalid']);
    }

    protected function tearDown(): void
    {
        $macros = new \ReflectionProperty(Request::class, 'macros'); $macros->setAccessible(true); $macros->setValue(null, $this->macros);
        Capsule::connection()->disconnect();
        User::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function id(): string { return bin2hex(random_bytes(16)); }

    private function pending(int $tg = 7, string $email = 'member@example.invalid'): array
    {
        $this->service->requestCode($tg, $email, $this->id());
        $job = end($this->jobs);
        $property = new \ReflectionProperty(SendTelegramBindingEmail::class, 'code');
        $property->setAccessible(true);
        return [$property->getValue($job), $job];
    }

    private function failsWith(int $status, callable $callback): void
    {
        try { $callback(); $this->fail('Expected HTTP ' . $status); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function test_binding_without_plan_changes_only_telegram_id_and_timestamp(): void
    {
        $before = User::first()->toArray();
        [$code] = $this->pending();
        $this->assertNull(User::first()->telegram_id);
        $result = $this->service->confirmCode(7, $code, $this->id());
        $this->assertTrue($result['allowed']);
        $this->assertSame('bound', $result['status']);
        $after = User::first()->toArray();
        $this->assertSame(7, $after['telegram_id']);
        unset($before['telegram_id'], $before['updated_at'], $after['telegram_id'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertTrue($this->service->verify(7)['allowed']);
    }

    public function test_mail_disabled_does_not_enqueue_or_change_user(): void
    {
        config(['telegram_binding.mail_enabled' => false]);
        $this->failsWith(503, fn() => $this->pending());
        $this->assertCount(0, $this->jobs);
        $this->assertNull(User::first()->telegram_id);
    }

    public function test_unknown_or_banned_email_does_not_send(): void
    {
        $this->failsWith(422, fn() => $this->pending(8, 'unknown@example.invalid'));
        User::first()->update(['banned' => 1]);
        $this->failsWith(422, fn() => $this->pending());
        $this->assertCount(0, $this->jobs);
    }

    public function test_email_normalization_and_hash_only_cache(): void
    {
        [$code] = $this->pending(7, ' MEMBER@example.invalid ');
        $data = Cache::get(TelegramBindingService::key('challenge:7'));
        $this->assertSame('member@example.invalid', $data['email']);
        $this->assertArrayNotHasKey('code', $data);
        $this->assertSame(64, strlen($data['code_hash']));
        $this->assertNotSame($code, $data['code_hash']);
    }

    public function test_request_retry_is_idempotent_and_conflict_is_rejected(): void
    {
        $id = $this->id();
        $first = $this->service->requestCode(7, 'member@example.invalid', $id);
        $this->assertSame($first, $this->service->requestCode(7, 'member@example.invalid', $id));
        $this->assertCount(1, $this->jobs);
        $this->failsWith(409, fn() => $this->service->requestCode(7, 'other@example.invalid', $id));
    }

    public function test_resend_has_telegram_and_email_cooldown(): void
    {
        $this->pending();
        $this->failsWith(429, fn() => $this->pending());
        $this->failsWith(429, fn() => $this->pending(8));
        $this->assertCount(1, $this->jobs);
    }

    public function test_global_and_user_hourly_caps(): void
    {
        Cache::put(TelegramBindingService::key('hour:all'), 60, 3600);
        $this->failsWith(429, fn() => $this->pending());
        Cache::forget(TelegramBindingService::key('hour:all'));
        Cache::put(TelegramBindingService::key('hour:tg:7'), 5, 3600);
        $this->failsWith(429, fn() => $this->pending());
        $this->assertCount(0, $this->jobs);
    }

    public function test_wrong_codes_are_limited_and_other_telegram_cannot_consume(): void
    {
        [$code] = $this->pending();
        $this->failsWith(422, fn() => $this->service->confirmCode(8, $code, $this->id()));
        for ($i = 0; $i < 5; $i++) $this->failsWith(422, fn() => $this->service->confirmCode(7, '000000', $this->id()));
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
        $this->assertNull(User::first()->telegram_id);
    }

    public function test_expired_code_is_rejected(): void
    {
        [$code] = $this->pending();
        $key = TelegramBindingService::key('challenge:7');
        $data = Cache::get($key); $data['expires_at'] = time() - 1; Cache::put($key, $data, 600);
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
    }

    public function test_confirm_retry_only_reuses_same_request_and_same_code(): void
    {
        [$code] = $this->pending(); $id = $this->id();
        $first = $this->service->confirmCode(7, $code, $id);
        $this->assertSame($first, $this->service->confirmCode(7, $code, $id));
        $this->failsWith(409, fn() => $this->service->confirmCode(7, '000000', $id));
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
    }

    public function test_existing_binding_is_not_overwritten(): void
    {
        User::first()->update(['telegram_id' => 8]);
        [$code] = $this->pending();
        $this->failsWith(409, fn() => $this->service->confirmCode(7, $code, $this->id()));
        $this->assertSame(8, User::first()->telegram_id);
    }

    public function test_one_telegram_cannot_take_second_account(): void
    {
        User::create(['email' => 'other@example.invalid', 'telegram_id' => 7]);
        [$code] = $this->pending();
        $this->failsWith(409, fn() => $this->service->confirmCode(7, $code, $this->id()));
        $this->assertNull(User::where('email', 'member@example.invalid')->first()->telegram_id);
    }

    public function test_account_banned_or_email_changed_after_code_is_rejected(): void
    {
        [$code] = $this->pending();
        User::first()->update(['banned' => 1]);
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
        User::first()->update(['banned' => 0, 'email' => 'changed@example.invalid']);
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
    }

    public function test_banned_or_unknown_bound_id_is_denied(): void
    {
        $this->assertFalse($this->service->verify(7)['allowed']);
        User::first()->update(['telegram_id' => 7, 'banned' => 1]);
        $this->assertFalse($this->service->verify(7)['allowed']);
    }

    public function test_registration_code_cache_is_never_consumed_or_changed(): void
    {
        Cache::put('EMAIL_VERIFY_CODE_member@example.invalid', '123456', 300);
        $this->failsWith(422, fn() => $this->service->confirmCode(7, '123456', $this->id()));
        [$code] = $this->pending();
        $this->service->confirmCode(7, $code, $this->id());
        $this->assertSame('123456', Cache::get('EMAIL_VERIFY_CODE_member@example.invalid'));
    }

    public function test_failed_enqueue_discards_challenge(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('offline'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->failsWith(503, fn() => $this->pending());
        $this->assertFalse(Cache::has(TelegramBindingService::key('challenge:7')));
    }

    public function test_lock_prevents_parallel_requests(): void
    {
        $lock = Cache::lock(TelegramBindingService::key('lock:request'), 30); $lock->get();
        $this->failsWith(429, fn() => $this->pending());
        $lock->release();
        $this->pending(); $this->assertCount(1, $this->jobs);
    }

    private function signedRequest(string $body = '{"telegram_id":7}', ?int $timestamp = null, string $action = 'verify'): Request
    {
        $path = '/api/v1/bot/telegram/' . $action; $time = (string) ($timestamp ?? time()); $nonce = $this->id();
        $signature = hash_hmac('sha256', implode("\n", [$time, $nonce, 'POST', $path, hash('sha256', $body)]), str_repeat('s', 48));
        return Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_TG_TIMESTAMP' => $time, 'HTTP_X_TG_NONCE' => $nonce, 'HTTP_X_TG_SIGNATURE' => $signature], $body);
    }

    public function test_valid_signature_and_replay_rejection(): void
    {
        $request = $this->signedRequest(); $auth = new TelegramBotAuth();
        $this->assertSame('ok', $auth->handle($request, fn() => 'ok'));
        $this->failsWith(409, fn() => $auth->handle($request, fn() => 'bad'));
    }

    public function test_missing_bad_expired_and_future_signatures_are_rejected(): void
    {
        $auth = new TelegramBotAuth();
        foreach ([Request::create('/'), $this->signedRequest('{}', time() - 120), $this->signedRequest('{}', time() + 120)] as $request)
            $this->failsWith(401, fn() => $auth->handle($request, fn() => 'bad'));
        $request = $this->signedRequest(); $request->headers->set('X-Tg-Signature', str_repeat('0', 64));
        $this->failsWith(401, fn() => $auth->handle($request, fn() => 'bad'));
    }

    public function test_disabled_endpoint_and_large_body_are_rejected(): void
    {
        $auth = new TelegramBotAuth();
        $this->failsWith(413, fn() => $auth->handle($this->signedRequest(str_repeat('x', 2049)), fn() => 'bad'));
        config(['telegram_binding.enabled' => false]);
        $this->failsWith(503, fn() => $auth->handle($this->signedRequest(), fn() => 'bad'));
    }

    public function test_email_job_uses_dedicated_template_and_checks_pending_challenge(): void
    {
        [$code, $original] = $this->pending();
        $job = new class(7, $original->challengeId, $code) extends SendTelegramBindingEmail {
            public $deliveries = [];
            protected function deliver(array $params): void { $this->deliveries[] = $params; }
        };
        $job->handle();
        $this->assertCount(1, $job->deliveries);
        $this->assertSame('telegram_binding', $job->deliveries[0]['template_name']);
        $this->assertSame('member@example.invalid', $job->deliveries[0]['email']);
        $this->assertSame('send_email', $job->queue);
        $this->service->confirmCode(7, $code, $this->id());
        $job->handle(); $this->assertCount(1, $job->deliveries);
    }

    public function test_disabled_or_obsolete_email_job_never_delivers(): void
    {
        [$code, $original] = $this->pending();
        $job = new class(7, $original->challengeId, $code) extends SendTelegramBindingEmail {
            protected function deliver(array $params): void { throw new \RuntimeException('must not send'); }
        };
        config(['telegram_binding.mail_enabled' => false]); $job->handle();
        config(['telegram_binding.mail_enabled' => true]);
        $key = TelegramBindingService::key('challenge:7');
        $data = Cache::get($key); $data['id'] = $this->id(); Cache::put($key, $data, 600); $job->handle();
        $data['id'] = $original->challengeId; $data['expires_at'] = time() - 1; Cache::put($key, $data, 600); $job->handle();
        $this->assertNull(User::first()->telegram_id);
    }

    public function test_binding_email_adds_airport_name_only_when_missing(): void
    {
        [$code, $original] = $this->pending();
        $job = new class(7, $original->challengeId, $code) extends SendTelegramBindingEmail {
            public $deliveries = [];
            protected function deliver(array $params): void { $this->deliveries[] = $params; }
        };
        $before = User::first()->toArray();
        foreach (['Clash' => 'Clash机场', 'OvO' => 'OvO机场', '囡囡喵' => '囡囡喵机场',
            '光喵' => '光喵机场', '便宜机场' => '便宜机场', '一元机场' => '一元机场',
            '机场测试站' => '机场测试站', '  Clash  ' => 'Clash机场'] as $name => $expected) {
            config(['v2board.app_name' => $name]);
            $job->deliveries = [];
            $job->handle();
            $this->assertCount(1, $job->deliveries);
            $params = $job->deliveries[0];
            $this->assertSame($expected . ' - Telegram绑定验证码', $params['subject']);
            $this->assertSame($expected, $params['template_value']['name']);
            $this->assertSame('member@example.invalid', $params['email']);
            $this->assertSame($code, $params['template_value']['code']);
            $this->assertSame($name, config('v2board.app_name'));
        }
        $this->assertSame($before, User::first()->toArray());
    }

    public function test_binding_email_empty_site_name_has_a_nonempty_fallback(): void
    {
        [$code, $original] = $this->pending();
        $job = new class(7, $original->challengeId, $code) extends SendTelegramBindingEmail {
            public $deliveries = [];
            protected function deliver(array $params): void { $this->deliveries[] = $params; }
        };
        foreach (['', '   ', null] as $name) {
            config(['v2board.app_name' => $name]);
            $job->deliveries = [];
            $job->handle();
            $this->assertSame('网站机场 - Telegram绑定验证码', $job->deliveries[0]['subject']);
            $this->assertSame('网站机场', $job->deliveries[0]['template_value']['name']);
        }
    }

    public function test_account_reads_issue_only_selects_and_preserve_every_user_field(): void
    {
        $plan = Plan::create(['name' => 'Fixture plan', 'transfer_enable' => 1]);
        User::first()->update(['telegram_id' => 7, 'plan_id' => $plan->id, 'transfer_enable' => 9876543210]);
        User::create(['email' => 'other@example.invalid', 'telegram_id' => 8, 'token' => 'other-token']);
        $before = User::orderBy('id')->get()->toArray();
        Capsule::connection()->enableQueryLog();
        Capsule::connection()->flushQueryLog();
        $subscription = $this->service->readSubscription(7);
        $planData = $this->service->readPlan(7);
        foreach (Capsule::connection()->getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', strtolower(trim($query['query'])));
        }
        $this->assertSame('https://sub.example.invalid/api/v1/client/subscribe?token=fixture-token', $subscription['subscribe_url']);
        $this->assertSame(9876543210, $planData['total_bytes']);
        $this->assertSame(579, $planData['used_bytes']);
        $this->assertSame(9876542631, $planData['remaining_bytes']);
        $this->assertSame($before, User::orderBy('id')->get()->toArray());
        $this->assertCount(0, $this->jobs);
    }

    public function test_account_reads_reject_unbound_banned_and_ambiguous_bindings(): void
    {
        foreach (['readSubscription', 'readPlan'] as $method) {
            $this->failsWith(422, fn() => $this->service->$method(70));
        }
        User::first()->update(['telegram_id' => 7, 'banned' => 1]);
        foreach (['readSubscription', 'readPlan'] as $method) {
            $this->failsWith(422, fn() => $this->service->$method(7));
        }
        User::create(['email' => 'duplicate1@example.invalid', 'telegram_id' => 8]);
        User::create(['email' => 'duplicate2@example.invalid', 'telegram_id' => 8]);
        foreach (['readSubscription', 'readPlan'] as $method) {
            $this->failsWith(409, fn() => $this->service->$method(8));
        }
        $this->assertCount(0, $this->jobs);
    }

    public function test_account_reads_are_rate_limited_independently_and_do_not_reset_mail_limits(): void
    {
        User::first()->update(['telegram_id' => 7]);
        Cache::put(TelegramBindingService::key('hour:tg:7'), 5, 3600);
        $this->service->readSubscription(7);
        $this->failsWith(429, fn() => $this->service->readSubscription(7));
        $this->service->readPlan(7);
        $this->failsWith(429, fn() => $this->service->readPlan(7));
        $this->assertSame(5, Cache::get(TelegramBindingService::key('hour:tg:7')));
        Cache::forget(TelegramBindingService::key('read:plan:7'));
        $this->assertSame('no_plan', $this->service->readPlan(7)['status']);
    }

    public function test_plan_handles_no_plan_missing_plan_expired_and_overused(): void
    {
        $plan = Plan::create(['name' => 'Fixture']);
        $cases = [
            [70, null, null, 1000, 'no_plan'],
            [71, 9999, null, 1000, 'plan_missing'],
            [72, $plan->id, time() - 10, 1000, 'expired'],
            [73, $plan->id, null, 500, 'exhausted'],
            [74, $plan->id, null, 1000, 'active'],
            [75, $plan->id, time() + 10000, 1000, 'active'],
            [76, $plan->id, 0, 1000, 'expired'],
        ];
        foreach ($cases as [$tg, $planId, $expiry, $total, $status]) {
            User::create(['email' => $tg . '@example.invalid', 'telegram_id' => $tg,
                'plan_id' => $planId, 'expired_at' => $expiry, 'transfer_enable' => $total]);
            $data = $this->service->readPlan($tg);
            $this->assertSame($status, $data['status']);
            $this->assertSame(max(0, $total - 579), $data['remaining_bytes']);
            $this->assertSame($expiry, $data['expires_at']);
        }
    }

    public function test_subscription_does_not_generate_or_reset_missing_token(): void
    {
        User::first()->update(['telegram_id' => 7, 'token' => '']);
        $before = User::first()->toArray();
        $this->failsWith(503, fn() => $this->service->readSubscription(7));
        $this->assertSame($before, User::first()->toArray());
    }

    public function test_read_routes_return_only_requested_fields_and_disable_caching(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $subscription = $this->route('account/subscription', ['telegram_id' => 7]);
        $plan = $this->route('account/plan', ['telegram_id' => 7]);
        $this->assertSame(['subscribe_url'], array_keys($subscription->getData(true)['data']));
        $this->assertSame(['plan_name', 'status', 'expires_at', 'total_bytes', 'used_bytes', 'remaining_bytes'], array_keys($plan->getData(true)['data']));
        foreach ([$subscription, $plan] as $response) {
            $this->assertSame(200, $response->status());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        }
        $this->assertStringNotContainsString('fixture-token', $plan->getContent());
        $this->assertStringNotContainsString('fixture-uuid', $plan->getContent());
    }

    public function test_read_routes_ignore_other_account_identifiers(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $other = User::create(['email' => 'other@example.invalid', 'telegram_id' => 8, 'token' => 'other-token']);
        $data = $this->route('account/subscription', ['telegram_id' => 7, 'user_id' => $other->id,
            'email' => $other->email, 'token' => $other->token])->getData(true)['data'];
        $this->assertStringContainsString('fixture-token', $data['subscribe_url']);
        $this->assertStringNotContainsString('other-token', $data['subscribe_url']);
    }

    public function test_read_route_signatures_cannot_be_reused_for_other_identity_or_action(): void
    {
        foreach (['account/subscription', 'account/plan'] as $action) {
            $original = $this->signedRequest('{"telegram_id":7}', null, $action);
            foreach ([[$action, '{"telegram_id":8}'], ['email/unbind/prepare', '{"telegram_id":7}']] as [$path, $body]) {
                $tampered = Request::create('/api/v1/bot/telegram/' . $path, 'POST', [], [], [], [], $body);
                foreach (['X-Tg-Timestamp', 'X-Tg-Nonce', 'X-Tg-Signature'] as $header) $tampered->headers->set($header, $original->header($header));
                $this->failsWith(401, fn() => (new TelegramBotAuth())->handle($tampered, fn() => 'bad'));
            }
        }
    }

    private function route(string $action, array $data)
    {
        $router = new Router(new Events($this->app), $this->app);
        $router->group(['prefix' => 'api/v1', 'namespace' => 'App\\Http\\Controllers'], function ($router) {
            (new TelegramBindingRoute())->map($router);
        });
        $request = $this->signedRequest(json_encode($data), null, $action);
        $this->app->instance('request', $request);
        return $router->dispatch($request);
    }

    public function test_full_route_request_confirm_and_verification_flow(): void
    {
        $response = $this->route('email/request', ['telegram_id' => 7, 'email' => 'member@example.invalid', 'request_id' => $this->id()]);
        $this->assertSame(200, $response->status());
        $this->assertSame('queued', $response->getData(true)['data']['status']);
        $property = new \ReflectionProperty(SendTelegramBindingEmail::class, 'code'); $property->setAccessible(true);
        $code = $property->getValue($this->jobs[0]);
        $response = $this->route('email/confirm', ['telegram_id' => 7, 'code' => $code, 'request_id' => $this->id()]);
        $this->assertSame('bound', $response->getData(true)['data']['status']);
        $this->assertTrue($this->route('verify', ['telegram_id' => 7])->getData(true)['data']['allowed']);
        $this->assertFalse($this->route('verify', ['telegram_id' => 7, 'require_active_plan' => true])->getData(true)['data']['allowed']);
    }

    public function test_routes_reject_invalid_ids_email_codes_and_idempotency_keys(): void
    {
        foreach ([['verify', ['telegram_id' => -1]], ['verify', ['telegram_id' => '7x']],
            ['email/request', ['telegram_id' => 7, 'email' => 'bad', 'request_id' => $this->id()]],
            ['email/request', ['telegram_id' => 7, 'email' => 'member@example.invalid', 'request_id' => 'bad']],
            ['email/confirm', ['telegram_id' => 7, 'code' => '12345', 'request_id' => $this->id()]]] as [$action, $data]) {
            try { $this->route($action, $data); $this->fail('Expected invalid input rejection'); }
            catch (ValidationException $error) { $this->assertSame(422, $error->status); }
        }
        $this->assertCount(0, $this->jobs);
    }

    public function test_wrong_body_or_path_cannot_reuse_signature(): void
    {
        $original = $this->signedRequest();
        $tampered = Request::create('/api/v1/bot/telegram/email/request', 'POST', [], [], [], [], '{"telegram_id":8}');
        foreach (['X-Tg-Timestamp', 'X-Tg-Nonce', 'X-Tg-Signature'] as $header) $tampered->headers->set($header, $original->header($header));
        $this->failsWith(401, fn() => (new TelegramBotAuth())->handle($tampered, fn() => 'bad'));
    }

    public function test_unbind_preview_only_masks_email_and_preserves_binding(): void
    {
        User::first()->update(['telegram_id' => 7, 'plan_id' => 3, 'transfer_enable' => 987654]);
        $before = User::first()->toArray();
        $data = $this->service->prepareUnbind(7);
        $this->assertSame('confirmation_required', $data['status']);
        $this->assertSame('me***@example.invalid', $data['emailMasked']);
        $this->assertSame(300, $data['expires_in']);
        $this->assertSame($before, User::first()->toArray());
        $this->assertNotSame($data['confirmation_token'], Cache::get(TelegramBindingService::key('unbind:7'))['token_hash']);
        $this->assertCount(0, $this->jobs);
    }

    public function test_unbind_route_changes_only_own_binding_and_timestamp(): void
    {
        User::first()->update(['telegram_id' => 7, 'plan_id' => 3, 'transfer_enable' => 987654]);
        $other = User::create(['email' => 'other@example.invalid', 'telegram_id' => 8])->fresh()->toArray();
        $before = User::first()->toArray();
        $prepared = $this->route('email/unbind/prepare', ['telegram_id' => 7])->getData(true)['data'];
        $response = $this->route('email/unbind/confirm', ['telegram_id' => 7,
            'confirmation_token' => $prepared['confirmation_token'], 'request_id' => $this->id()]);
        $this->assertSame(['status' => 'unbound', 'allowed' => false], $response->getData(true)['data']);
        $after = User::first()->toArray();
        $this->assertNull($after['telegram_id']);
        unset($before['telegram_id'], $before['updated_at'], $after['telegram_id'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($other, User::where('id', $other['id'])->first()->toArray());
        $this->assertFalse($this->service->verify(7)['allowed']);
        $this->assertTrue($this->service->verify(8)['allowed']);
        $this->assertCount(0, $this->jobs);
    }

    public function test_unknown_binding_cannot_be_confirmed(): void
    {
        $this->assertSame(['status' => 'not_bound'], $this->service->prepareUnbind(7));
        $this->failsWith(422, fn() => $this->service->confirmUnbind(7, $this->id(), $this->id()));
        $this->assertCount(0, $this->jobs);
    }

    public function test_wrong_expired_and_other_users_unbind_token_are_rejected(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        $this->failsWith(422, fn() => $this->service->confirmUnbind(8, $token, $this->id()));
        $this->failsWith(422, fn() => $this->service->confirmUnbind(7, $this->id(), $this->id()));
        $key = TelegramBindingService::key('unbind:7');
        $data = Cache::get($key); $data['expires_at'] = time() - 1; Cache::put($key, $data, 300);
        $this->failsWith(422, fn() => $this->service->confirmUnbind(7, $token, $this->id()));
        $this->assertSame(7, User::first()->telegram_id);
    }

    public function test_new_unbind_preview_invalidates_old_confirmation(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $old = $this->service->prepareUnbind(7)['confirmation_token'];
        $new = $this->service->prepareUnbind(7)['confirmation_token'];
        $this->failsWith(422, fn() => $this->service->confirmUnbind(7, $old, $this->id()));
        $this->assertSame('unbound', $this->service->confirmUnbind(7, $new, $this->id())['status']);
    }

    public function test_changed_binding_or_email_cannot_be_removed_by_stale_confirmation(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        User::first()->update(['telegram_id' => 8]);
        $this->failsWith(409, fn() => $this->service->confirmUnbind(7, $token, $this->id()));
        User::first()->update(['telegram_id' => 7, 'email' => 'changed@example.invalid']);
        $this->failsWith(409, fn() => $this->service->confirmUnbind(7, $token, $this->id()));
        $this->assertSame(7, User::first()->telegram_id);
    }

    public function test_unbind_retry_is_idempotent_but_cannot_remove_a_new_binding(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $token = $this->service->prepareUnbind(7)['confirmation_token']; $id = $this->id();
        $result = $this->service->confirmUnbind(7, $token, $id);
        $this->assertSame($result, $this->service->confirmUnbind(7, $token, $id));
        $this->failsWith(409, fn() => $this->service->confirmUnbind(7, $this->id(), $id));
        User::first()->update(['telegram_id' => 7]);
        $this->failsWith(409, fn() => $this->service->confirmUnbind(7, $token, $id));
        $this->assertSame(7, User::first()->telegram_id);
    }

    public function test_unbind_invalidates_unused_code_but_does_not_reset_mail_limits(): void
    {
        User::first()->update(['telegram_id' => 7]);
        [$code] = $this->pending();
        $count = Cache::get(TelegramBindingService::key('hour:tg:7'));
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        $this->service->confirmUnbind(7, $token, $this->id());
        $this->failsWith(422, fn() => $this->service->confirmCode(7, $code, $this->id()));
        $this->failsWith(429, fn() => $this->pending());
        $this->assertSame($count, Cache::get(TelegramBindingService::key('hour:tg:7')));
        $this->assertCount(1, $this->jobs);
    }

    public function test_old_bind_receipt_cannot_claim_success_after_unbind(): void
    {
        [$code] = $this->pending(); $id = $this->id();
        $this->service->confirmCode(7, $code, $id);
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        $this->service->confirmUnbind(7, $token, $this->id());
        $this->failsWith(409, fn() => $this->service->confirmCode(7, $code, $id));
        $this->assertNull(User::first()->telegram_id);
    }

    public function test_new_binding_invalidates_pending_unbind_confirmation(): void
    {
        User::first()->update(['telegram_id' => 7]);
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        [$code] = $this->pending();
        $this->service->confirmCode(7, $code, $this->id());
        $this->failsWith(422, fn() => $this->service->confirmUnbind(7, $token, $this->id()));
        $this->assertSame(7, User::first()->telegram_id);
    }

    public function test_unbind_preserves_banned_status_and_respects_same_user_lock(): void
    {
        User::first()->update(['telegram_id' => 7, 'banned' => 1]);
        $token = $this->service->prepareUnbind(7)['confirmation_token'];
        $lock = Cache::lock(TelegramBindingService::key('lock:tg:7'), 30); $lock->get();
        $this->failsWith(429, fn() => $this->service->confirmUnbind(7, $token, $this->id()));
        $lock->release();
        $this->service->confirmUnbind(7, $token, $this->id());
        $this->assertSame(1, User::first()->banned);
    }

    public function test_unbind_routes_validate_identity_token_and_idempotency_key(): void
    {
        foreach ([['email/unbind/prepare', ['telegram_id' => 0]],
            ['email/unbind/confirm', ['telegram_id' => 7, 'confirmation_token' => 'bad', 'request_id' => $this->id()]],
            ['email/unbind/confirm', ['telegram_id' => 7, 'confirmation_token' => $this->id(), 'request_id' => 'bad']]] as [$action, $data]) {
            try { $this->route($action, $data); $this->fail('Expected invalid unbind input rejection'); }
            catch (ValidationException $error) { $this->assertSame(422, $error->status); }
        }
        $this->assertCount(0, $this->jobs);
    }
}
