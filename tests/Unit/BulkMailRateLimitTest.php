<?php

namespace Tests\Unit;

use App\Jobs\SendEmailJob;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\TestCase;

class BulkMailRateLimitTest extends TestCase
{
    private $app;
    private $database;

    protected function setUp(): void
    {
        // No site bootstrap, SMTP transport, or persistent database is loaded.
        $this->app = new Application('/fixture/site-one');
        $this->app->instance('config', new Repository([
            'v2board' => ['email_template' => 'default'],
            'queue' => ['connections' => ['redis' => ['connection' => 'default']]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Redis::swap(Mockery::mock());
        Mail::swap(Mockery::mock());
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->getSchemaBuilder()->create('v2_mail_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('subject');
            $table->string('template_name');
            $table->text('error')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
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

    private function params(): array
    {
        return [
            'email' => 'mail-limit-fixture@example.invalid',
            'subject' => 'Fixture only',
            'template_name' => 'notify',
            'template_value' => [],
        ];
    }

    private function limiter($result): void
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->once()->andReturn($connection);
        $call = $connection->shouldReceive('eval')->once()->withArgs(function ($script, $keys, $key, $token) {
            return $keys === 1
                && $key === 'send_email_mass:rate:' . hash('sha256', '/fixture/site-one')
                && strlen($token) === 32;
        });
        if ($result instanceof \Throwable) {
            $call->andThrow($result);
        } else {
            $call->andReturn($result);
        }
    }

    public function test_limited_job_is_requeued_without_sending_or_logging_success(): void
    {
        $this->limiter(0);
        Mail::shouldReceive('send')->never();
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(1);
        $job = new SendEmailJob($this->params(), 'send_email_mass');
        $job->setJob($queued);
        $this->assertNull($job->handle());
        $this->assertSame(0, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_admitted_bulk_job_calls_only_mock_mail_and_logs_once(): void
    {
        $this->limiter(1);
        Mail::shouldReceive('send')->once()->andReturnUsing(function ($view, $data, $callback) {
            $this->assertSame('mail.default.notify', $view);
            $message = Mockery::mock();
            $message->shouldReceive('to')->once()->with('mail-limit-fixture@example.invalid')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('Fixture only')->andReturnSelf();
            $callback($message);
        });
        $job = new SendEmailJob($this->params(), 'send_email_mass');
        $this->assertNull($job->handle()['error']);
        $this->assertSame(1, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_redis_failure_does_not_bypass_limit_or_record_success(): void
    {
        $this->limiter(new \RuntimeException('Fixture Redis unavailable'));
        Mail::shouldReceive('send')->never();
        try {
            (new SendEmailJob($this->params(), 'send_email_mass'))->handle();
            $this->fail('Redis failures must reach the queue retry handler');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture Redis unavailable', $error->getMessage());
        }
        $this->assertSame(0, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_normal_mail_skips_bulk_limiter_and_preserves_delay(): void
    {
        Redis::shouldReceive('connection')->never();
        Mail::shouldReceive('send')->once()->andReturnNull();
        $start = microtime(true);
        $result = (new SendEmailJob($this->params()))->handle();
        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $start);
        $this->assertNull($result['error']);
        $this->assertSame(1, $this->database->getConnection()->table('v2_mail_log')->count());
    }

    public function test_real_smtp_error_still_uses_existing_error_log_path(): void
    {
        $this->limiter(1);
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Fixture SMTP failure'));
        $result = (new SendEmailJob($this->params(), 'send_email_mass'))->handle();
        $this->assertSame('Fixture SMTP failure', $result['error']);
        $this->assertSame('Fixture SMTP failure', $this->database->getConnection()->table('v2_mail_log')->value('error'));
    }

    public function test_serialized_queue_payload_preserves_exception_limit_without_attempt_limit(): void
    {
        $queue = new SyncQueue();
        $queue->setContainer($this->app);
        $createPayload = new \ReflectionMethod($queue, 'createPayload');
        $createPayload->setAccessible(true);
        foreach (['send_email_mass' => 0, 'send_email' => 3] as $name => $tries) {
            $job = new SendEmailJob($this->params(), $name);
            $payload = json_decode($createPayload->invoke($queue, $job, $name), true);
            $this->assertSame($tries, $payload['maxTries']);
            $this->assertSame($name === 'send_email_mass' ? 3 : null, $payload['maxExceptions']);
            $this->assertSame(10, $payload['timeout']);
            $restored = unserialize($payload['data']['command']);
            $this->assertSame($name, $restored->queue);
            $this->assertSame($tries, $restored->tries);
        }
    }

    public function test_each_site_has_its_own_limit_key(): void
    {
        $job = new SendEmailJob($this->params(), 'send_email_mass');
        $method = new \ReflectionMethod($job, 'bulkRateLimitKey');
        $method->setAccessible(true);
        $first = $method->invoke($job);
        $this->app->setBasePath('/fixture/site-two');
        $second = $method->invoke($job);
        $this->assertNotSame($first, $second);
    }

    public function test_admin_bulk_dispatch_uses_rate_limited_queue(): void
    {
        $this->assertBulkDispatch(new \App\Http\Controllers\V1\Admin\UserController());
    }

    public function test_staff_bulk_dispatch_routes_to_same_queue_with_recipient_filter_stubbed(): void
    {
        $controller = Mockery::mock(\App\Http\Controllers\V1\Staff\UserController::class)->makePartial();
        $controller->shouldReceive('filter')->once()->andReturnNull();
        $this->assertBulkDispatch($controller);
    }

    private function assertBulkDispatch($controller): void
    {
        Redis::shouldReceive('connection')->never();
        Mail::shouldReceive('send')->never();
        $this->database->getConnection()->getSchemaBuilder()->create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->integer('created_at');
        });
        $this->database->getConnection()->table('v2_user')->insert([
            ['email' => 'first@example.invalid', 'created_at' => 1],
            ['email' => 'second@example.invalid', 'created_at' => 2],
        ]);
        $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) {
            $this->assertInstanceOf(SendEmailJob::class, $job);
            $this->assertSame('send_email_mass', $job->queue);
            $this->assertSame(0, $job->tries);
        });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $response = Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->shouldReceive('make')->once()->andReturnUsing(function ($body, $status, $headers) {
            return new \Illuminate\Http\Response($body, $status, $headers);
        });
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $request = \App\Http\Requests\Admin\UserSendMail::create('/fixture/sendMail', 'POST', [
            'subject' => 'Fixture only', 'content' => 'Never delivered',
        ]);
        $result = $controller->sendMail($request);
        $this->assertSame(['data' => true], $result->getOriginalContent());
    }
}
