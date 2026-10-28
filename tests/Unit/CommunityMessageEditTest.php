<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Guest\CommunityConsoleController;
use App\Models\User;
use App\Services\CommunityConsoleService;
use App\Services\CommunityService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommunityMessageEditTest extends TestCase
{
    private $requestMacros;

    protected function setUp(): void
    {
        $app = new Application();
        $app->instance('log', new \Psr\Log\NullLogger());
        $cache = new Repository(new ArrayStore());
        $app->instance(CacheRateLimiter::class, new CacheRateLimiter($cache));
        $validator = new Validator(new Translator(new ArrayLoader(), 'en'), $app);
        $macros = new \ReflectionProperty(Request::class, 'macros');
        $this->requestMacros = $macros->getValue();
        Request::macro('validate', function (array $rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        $responses = $this->createStub(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(function ($data = []) { return new JsonResponse($data); });
        $app->instance(ResponseFactory::class, $responses);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        $macros = new \ReflectionProperty(Request::class, 'macros');
        $macros->setValue(null, $this->requestMacros);
        \Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    public function test_edit_is_console_only_and_drops_identity_attachment_and_reply_fields(): void
    {
        $service = new CommunityService();
        $request = Request::create('/', 'POST', ['post_id' => str_repeat('a', 26), 'message' => "修正\n内容",
            'user_id' => '999', 'moderator' => true, 'administrator' => true, 'root_id' => str_repeat('b', 26),
            'file_ids' => [str_repeat('c', 26)], 'client_id' => 'untrusted']);
        $this->assertSame(['post_id' => str_repeat('a', 26), 'message' => "修正\n内容"], $service->validatedData($request, 'edit', true));
        try {
            $service->validatedData($request, 'edit');
            $this->fail('User bridge exposed console-only edit');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_edit_requires_an_exact_post_id_and_present_bounded_text(): void
    {
        $service = new CommunityService();
        foreach ([[], ['post_id' => str_repeat('a', 26)], ['post_id' => 'bad', 'message' => 'text'],
            ['post_id' => str_repeat('a', 26) . "\n", 'message' => 'text'],
            ['post_id' => str_repeat('a', 26), 'message' => ['text']],
            ['post_id' => str_repeat('a', 26), 'message' => str_repeat('字', 2001)]] as $data) {
            try {
                $service->validatedData(Request::create('/', 'POST', $data), 'edit', true);
                $this->fail('Invalid edit accepted');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach (['', null, str_repeat('字', 2000)] as $message) {
            $data = $service->validatedData(Request::create('/', 'POST', ['post_id' => str_repeat('a', 26), 'message' => $message]), 'edit', true);
            $this->assertSame((string)$message, $data['message']);
        }
    }

    public function test_edit_uses_trusted_admin_write_bucket_and_metadata_only_audit(): void
    {
        $admin = new User(); $admin->id = 7; $admin->is_admin = 1;
        $console = $this->getMockBuilder(CommunityConsoleService::class)->onlyMethods(['authenticate'])->getMock();
        $console->expects($this->atLeastOnce())->method('authenticate')->willReturn($admin);
        $community = $this->getMockBuilder(CommunityService::class)->onlyMethods(['call'])->getMock();
        $data = ['post_id' => str_repeat('a', 26), 'message' => '修正后的消息'];
        $community->expects($this->once())->method('call')->with($admin, 'edit', $data)->willReturn(['id' => $data['post_id'], 'edited_at' => 100]);
        Log::shouldReceive('info')->once()->with('community_console_action', ['admin_id' => 7, 'action' => 'edit', 'target_id' => $data['post_id']]);
        $controller = new CommunityConsoleController();
        $request = Request::create('/', 'POST', ['action' => 'edit', 'data' => array_merge($data, ['user_id' => '999', 'file_ids' => ['spoof']])]);
        $response = $controller->handle($request, $community, $console);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, RateLimiter::attempts('community_console:7:write'));
        $this->assertSame(0, RateLimiter::attempts('community_console:7:read'));
        for ($i = 1; $i < 30; $i++) RateLimiter::hit('community_console:7:write', 60);
        try {
            $controller->handle($request, $community, $console);
            $this->fail('Edit bypassed write limit');
        } catch (HttpException $error) {
            $this->assertSame(429, $error->getStatusCode());
        }
    }
}
