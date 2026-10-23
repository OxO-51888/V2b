<?php

namespace Tests\Unit;

use App\Http\Middleware\HideQueueSecrets;
use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramBindingEmail;
use App\Services\TelegramBindingService;
use Illuminate\Bus\Dispatcher;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\TestCase;

class MailQueuePrivacyTest extends TestCase
{
    private $app;
    private $database;
    private $code = '937461';

    protected function setUp(): void
    {
        $this->app = new Application('/fixture/mail-privacy');
        $this->app->instance('config', new Repository([
            'horizon' => ['path' => 'monitor'],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'v2board' => ['email_template' => 'default', 'app_name' => 'Fixture'],
            'telegram_binding' => ['enabled' => true, 'mail_enabled' => true, 'site' => 'fixture'],
            'queue' => ['connections' => ['redis' => ['connection' => 'default']]],
        ]));
        $this->app->instance(EncrypterContract::class, new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        $this->app->instance('cache', new CacheManager($this->app));
        $this->app->instance(DispatcherContract::class, new Dispatcher($this->app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Mail::swap(Mockery::mock());
        Redis::swap(Mockery::mock());
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_mail_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('subject');
            $table->string('template_name');
            $table->text('error')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->integer('banned')->default(0);
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        Mockery::close();
    }

    private function params(string $template = 'verify'): array
    {
        return ['email' => 'privacy-fixture@example.invalid', 'subject' => 'Fixture only',
            'template_name' => $template, 'template_value' => ['code' => $this->code, 'name' => 'Fixture']];
    }

    private function payload($job): array
    {
        $queue = new SyncQueue();
        $queue->setContainer($this->app);
        $method = new \ReflectionMethod($queue, 'createPayload');
        $method->setAccessible(true);
        return json_decode($method->invoke($queue, $job, $job->queue), true);
    }

    private function restore(array $payload)
    {
        $handler = new CallQueuedHandler($this->app->make(DispatcherContract::class), $this->app);
        $method = new \ReflectionMethod($handler, 'getCommand');
        $method->setAccessible(true);
        return $method->invoke($handler, $payload['data']);
    }

    public function test_both_mail_jobs_encrypt_secrets_and_round_trip_with_real_queue_codec(): void
    {
        foreach ([new SendEmailJob($this->params()), new SendTelegramBindingEmail(123, 'challenge-fixture', $this->code)] as $job) {
            $payload = $this->payload($job);
            $serialized = json_encode($payload);
            $this->assertStringNotContainsString($this->code, $serialized);
            $this->assertStringNotContainsString('privacy-fixture@example.invalid', $serialized);
            $this->assertStringNotContainsString('challenge-fixture', $serialized);
            $this->assertFalse(strpos($payload['data']['command'], 'O:') === 0);
            $this->assertEquals($job, $this->restore($payload));
            $this->assertSame(get_class($job), $payload['data']['commandName']);
            $this->assertSame($job->timeout, $payload['timeout']);
            $this->assertSame($job->tries, $payload['maxTries']);
            $this->assertNotSame($payload['data']['command'], $this->payload($job)['data']['command']);
        }
    }

    public function test_mail_for_another_site_cannot_be_decrypted(): void
    {
        $payload = $this->payload(new SendEmailJob($this->params()));
        $this->app->instance(EncrypterContract::class, new Encrypter(str_repeat('z', 32), 'AES-256-CBC'));
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $this->restore($payload);
    }

    public function test_tampered_ciphertext_is_rejected_before_mail_delivery(): void
    {
        Mail::shouldReceive('send')->never();
        $payload = $this->payload(new SendEmailJob($this->params()));
        $envelope = json_decode(base64_decode($payload['data']['command']), true);
        $envelope['mac'] = str_repeat('0', 64);
        $payload['data']['command'] = base64_encode(json_encode($envelope));
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $this->restore($payload);
    }

    public function test_legacy_plaintext_jobs_remain_readable_by_worker(): void
    {
        foreach ([new SendEmailJob($this->params()), new SendTelegramBindingEmail(123, 'old-challenge', $this->code)] as $job) {
            $this->assertEquals($job, $this->restore(['data' => ['command' => serialize($job)]]));
        }
    }

    public function test_encrypted_verification_mail_delivers_original_code_to_mock_transport(): void
    {
        Redis::shouldReceive('connection')->never();
        Mail::shouldReceive('send')->once()->andReturnUsing(function ($view, $data, $callback) {
            $this->assertSame('mail.default.verify', $view);
            $this->assertSame($this->code, $data['code']);
            $message = Mockery::mock();
            $message->shouldReceive('to')->with('privacy-fixture@example.invalid')->once()->andReturnSelf();
            $message->shouldReceive('subject')->with('Fixture only')->once()->andReturnSelf();
            $callback($message);
        });
        $job = $this->restore($this->payload(new SendEmailJob($this->params())));
        $this->assertNull($job->handle()['error']);
        $row = $this->database->getConnection()->table('v2_mail_log')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString($this->code, json_encode($row));
    }

    public function test_real_queue_worker_handles_encrypted_and_legacy_jobs_without_smtp(): void
    {
        Redis::shouldReceive('connection')->never();
        Mail::shouldReceive('send')->twice()->andReturnUsing(function ($view, $data) {
            $this->assertSame($this->code, $data['code']);
        });
        $queue = new SyncQueue();
        $queue->setContainer($this->app);
        $this->assertSame(0, $queue->push(new SendEmailJob($this->params()), '', 'send_email'));
        $legacy = $this->payload(new SendEmailJob($this->params()));
        $legacy['data']['command'] = serialize(new SendEmailJob($this->params()));
        $worker = new \Illuminate\Queue\Jobs\SyncJob($this->app, json_encode($legacy), 'sync', 'send_email');
        $worker->fire();
        $this->assertTrue($worker->isDeleted());
        $this->assertSame(2, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_horizon_native_list_and_detail_responses_are_redacted(): void
    {
        if (!class_exists(\Laravel\Horizon\Http\Controllers\CompletedJobsController::class)) {
            $this->markTestSkipped('Production Horizon dependency is tested on the target server.');
        }
        $record = $this->record();
        $record['payload'] = json_encode($record['payload']);
        $record['context'] = json_encode($record['context']);
        $record['retried_by'] = json_encode($record['retried_by']);
        foreach (['CompletedJobsController', 'FailedJobsController', 'JobsController'] as $name) {
            $controller = (new \ReflectionClass('Laravel\\Horizon\\Http\\Controllers\\' . $name))->newInstanceWithoutConstructor();
            $repo = Mockery::mock(\Laravel\Horizon\Contracts\JobRepository::class);
            $controller->jobs = $repo;
            if ($name === 'JobsController') {
                $repo->shouldReceive('getJobs')->with(['fixture-job-id'])->once()->andReturn(collect([(object) $record]));
                $body = $controller->show('fixture-job-id');
            } else {
                $method = $name === 'CompletedJobsController' ? 'Completed' : 'Failed';
                $repo->shouldReceive('get' . $method)->once()->andReturn(collect([(object) $record]));
                $repo->shouldReceive('count' . $method)->once()->andReturn(1);
                $body = $controller->index(Request::create('/monitor/api/jobs/failed'));
            }
            $content = $this->render($body)->getContent();
            $this->assertStringNotContainsString($this->code, $content);
            $this->assertStringContainsString('fixture-job-id', $content);
        }
    }

    public function test_encrypted_telegram_mail_delivers_original_code_and_station_name(): void
    {
        $this->database->getConnection()->table('v2_user')->insert(['id' => 42, 'email' => 'privacy-fixture@example.invalid']);
        Cache::put(TelegramBindingService::key('challenge:123'), ['id' => 'challenge-fixture',
            'user_id' => 42, 'email' => 'privacy-fixture@example.invalid', 'expires_at' => time() + 300], 300);
        Redis::shouldReceive('connection')->never();
        Mail::shouldReceive('send')->once()->andReturnUsing(function ($view, $data) {
            $this->assertSame('mail.default.telegram_binding', $view);
            $this->assertSame($this->code, $data['code']);
            $this->assertSame('Fixture' . json_decode('"\u673a\u573a"'), $data['name']);
        });
        $this->restore($this->payload(new SendTelegramBindingEmail(123, 'challenge-fixture', $this->code)))->handle();
        $this->assertSame(1, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_expired_telegram_challenge_still_sends_nothing(): void
    {
        Mail::shouldReceive('send')->never();
        Cache::put(TelegramBindingService::key('challenge:123'), ['id' => 'challenge-fixture', 'expires_at' => time() - 1], 300);
        $this->restore($this->payload(new SendTelegramBindingEmail(123, 'challenge-fixture', $this->code)))->handle();
        $this->assertSame(0, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_encrypted_bulk_mail_preserves_rate_limited_release(): void
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->once()->andReturn($connection);
        $connection->shouldReceive('eval')->once()->andReturn(0);
        Mail::shouldReceive('send')->never();
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(1);
        $job = $this->restore($this->payload(new SendEmailJob($this->params('notify'), 'send_email_mass')));
        $job->setJob($queued);
        $job->handle();
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame([30, 120, 300], $job->backoff);
    }

    public function test_encrypted_retry_retains_ciphertext_and_original_code(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Fixture SMTP unavailable', 421));
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('fail')->never();
        $payload = $this->payload(new SendEmailJob($this->params()));
        $original = json_encode($payload);
        $job = $this->restore($payload);
        $job->setJob($queued);
        try { $job->handle(); $this->fail('SMTP failure must reach the existing retry path'); }
        catch (\RuntimeException $error) { $this->assertSame(421, $error->getCode()); }
        $this->assertSame($original, json_encode($payload));
        $this->assertStringNotContainsString($this->code, $original);
        $this->assertEquals(new SendEmailJob($this->params()), $this->restore($payload));
    }

    private function record(bool $encrypted = false, bool $telegram = false): array
    {
        $job = $telegram ? new SendTelegramBindingEmail(123, 'challenge-fixture', $this->code) : new SendEmailJob($this->params());
        $payload = $this->payload($job);
        if (!$encrypted) $payload['data']['command'] = serialize($job);
        return ['id' => 'fixture-job-id', 'name' => get_class($job), 'queue' => 'send_email',
            'status' => 'failed', 'payload' => $payload,
            'exception' => 'Fixture diagnostic code=' . $this->code,
            'context' => ['args' => [$this->code]],
            'retried_by' => [['id' => 'retry-id', 'retried_at' => 12345]]];
    }

    private function render($body, string $path = '/monitor/api/jobs/completed', bool $jsonResponse = true)
    {
        $response = $jsonResponse ? new JsonResponse($body) : new Response($body);
        return (new HideQueueSecrets())->handle(Request::create($path), function () use ($response) { return $response; });
    }

    public function monitorPaths(): array
    {
        return array_map(function ($path) { return [$path]; }, [
            '/monitor/api/jobs/pending', '/monitor/api/jobs/completed', '/monitor/api/jobs/silenced',
            '/monitor/api/jobs/failed', '/monitor/api/jobs/failed/fixture-id',
            '/monitor/api/jobs/fixture-id', '/monitor/api/monitoring/fixture-tag',
        ]);
    }

    /** @dataProvider monitorPaths */
    public function test_all_monitor_paths_hide_legacy_and_encrypted_mail_data(string $path): void
    {
        foreach ([false, true] as $encrypted) {
            foreach ([false, true] as $telegram) {
                $record = $this->record($encrypted, $telegram);
                $before = json_encode($record);
                $response = $this->render(['jobs' => [$record], 'total' => 1], $path);
                $content = $response->getContent();
                $this->assertStringNotContainsString($this->code, $content);
                $this->assertStringNotContainsString('privacy-fixture@example.invalid', $content);
                $this->assertStringNotContainsString('challenge-fixture', $content);
                $safe = json_decode($content, true);
                $this->assertSame(1, $safe['total']);
                foreach (['id', 'name', 'queue', 'status', 'retried_by'] as $key) {
                    $this->assertSame($record[$key], $safe['jobs'][0][$key]);
                }
                $this->assertSame('[redacted]', $safe['jobs'][0]['payload']['data']['command']);
                $this->assertSame($before, json_encode($record));
                $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
                $this->assertStringNotContainsString($this->code, json_encode($response->getData()));
            }
        }
    }

    public function test_single_job_and_encoded_payload_are_redacted(): void
    {
        $record = $this->record();
        $record['payload'] = json_encode($record['payload']);
        $response = $this->render($record, '/monitor/api/jobs/fixture-job-id');
        $this->assertStringNotContainsString($this->code, $response->getContent());
        $safe = json_decode($response->getContent(), true);
        $this->assertSame('[redacted]', json_decode($safe['payload'], true)['data']['command']);
    }

    public function test_mail_detection_falls_back_to_payload_class_without_name(): void
    {
        $record = $this->record();
        unset($record['name'], $record['payload']['displayName']);
        $this->assertStringNotContainsString($this->code, $this->render($record)->getContent());
    }

    public function test_malformed_legacy_mail_payload_is_hidden_without_unserializing(): void
    {
        $record = $this->record();
        $record['payload'] = 'corrupt command ' . $this->code;
        $this->assertStringNotContainsString($this->code, $this->render($record)->getContent());
    }

    public function test_non_mail_jobs_and_monitor_stats_keep_their_values(): void
    {
        $body = ['jobs' => [['id' => 'other', 'name' => 'App\\Jobs\\StatUserJob', 'payload' => ['data' => ['command' => 'original']],
            'exception' => 'Original diagnostic', 'context' => (object) []]], 'total' => 1, 'empty' => (object) []];
        $this->assertEquals(json_decode(json_encode($body)), json_decode($this->render($body)->getContent()));
    }

    public function test_other_endpoints_and_html_are_untouched(): void
    {
        $record = $this->record();
        $this->assertSame((new JsonResponse($record))->getContent(), $this->render($record, '/api/v1/user/info')->getContent());
        $html = '<html>Monitor application</html>';
        $this->assertSame($html, $this->render($html, '/monitor', false)->getContent());
    }

    public function test_configured_monitor_path_is_respected(): void
    {
        $this->app['config']->set('horizon.path', '/custom/queue');
        $this->assertStringNotContainsString($this->code, $this->render($this->record(), '/custom/queue/api/jobs/failed')->getContent());
        $this->app['config']->set('horizon.path', '');
        $this->assertStringNotContainsString($this->code, $this->render($this->record(), '/api/jobs/completed')->getContent());
    }

    public function test_global_middleware_registration_does_not_depend_on_route_cache(): void
    {
        $properties = (new \ReflectionClass(\App\Http\Kernel::class))->getDefaultProperties();
        $this->assertContains(HideQueueSecrets::class, $properties['middleware']);
    }
}
