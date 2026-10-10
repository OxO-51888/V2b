<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\CommunityController;
use App\Models\User;
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
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as Validator;
use PHPUnit\Framework\TestCase;

class CommunityMessageSystemTest extends TestCase
{
    private $requestMacros;

    protected function setUp(): void
    {
        $app = new Application();
        $cache = new Repository(new ArrayStore());
        $app->instance(CacheRateLimiter::class, new CacheRateLimiter($cache));
        $validator = new Validator(new Translator(new ArrayLoader(), 'en'), $app);
        $macros = new \ReflectionProperty(Request::class, 'macros');
        $macros->setAccessible(true);
        $this->requestMacros = $macros->getValue();
        Request::macro('validate', function (array $rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(function ($data = []) { return new JsonResponse($data); });
        $app->instance(ResponseFactory::class, $responses);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        $macros = new \ReflectionProperty(Request::class, 'macros');
        $macros->setAccessible(true);
        $macros->setValue(null, $this->requestMacros);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    public function test_browser_signals_become_only_a_coarse_system_label(): void
    {
        $method = new \ReflectionMethod(CommunityController::class, 'messageSystem');
        $method->setAccessible(true);
        foreach ([
            ['Mozilla/5.0 (Linux; Android 15; Pixel 9)', '', 'Android'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', '', 'iOS'],
            ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)', '', 'iPadOS'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '', 'Windows'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', '', 'macOS'],
            ['Mozilla/5.0 (X11; Linux x86_64)', '', 'Linux'],
            ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0)', '', 'ChromeOS'],
            ['Mozilla/5.0 (Linux; Android 12; HarmonyOS 4.0)', '"Android"', 'HarmonyOS'],
            ['Mozilla/5.0 (OpenHarmony 5.0)', '', 'HarmonyOS'],
            ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)', '"macOS"', 'iPadOS'],
            ['', '"Windows"', 'Windows'],
            ['', '"Chrome OS"', 'ChromeOS'],
            ['unknown browser', '"unknown"', ''],
            ['', '', ''],
            [str_repeat('x', 2049) . ' Android', '', ''],
            ['unknown browser', '"<script>Android</script>"', ''],
        ] as [$agent, $platform, $expected]) {
            $request = Request::create('/', 'POST', [], [], [], [
                'HTTP_USER_AGENT' => $agent, 'HTTP_SEC_CH_UA_PLATFORM' => $platform]);
            $this->assertSame($expected, $method->invoke(null, $request));
        }
    }

    public function test_user_post_overrides_body_metadata_and_does_not_forward_raw_headers(): void
    {
        $user = new User(); $user->id = 7;
        $payload = ['message' => 'hello', 'client_id' => 'a1234567-1234-1234-1234-123456789abc'];
        $service = $this->getMockBuilder(CommunityService::class)->onlyMethods(['authenticate', 'call'])->getMock();
        $service->method('authenticate')->willReturn($user);
        $service->expects($this->once())->method('call')->with($user, 'post', $payload + ['client_os' => 'Android'])->willReturn(['id' => 'post']);
        $request = Request::create('/', 'POST', $payload + ['client_os' => 'Windows', 'user_agent' => 'untrusted', 'ip' => '192.0.2.4'], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9)', 'REMOTE_ADDR' => '192.0.2.9']);
        $response = (new CommunityController())->handle($request, 'post', $service);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_staff_and_non_post_actions_do_not_add_system_metadata(): void
    {
        foreach ([['post', true], ['history', false]] as [$action, $staff]) {
            $user = new User(); $user->id = 7; $user->is_staff = $staff;
            $payload = $action === 'post' ? ['message' => 'reply', 'client_id' => 'a1234567-1234-1234-1234-123456789abc'] : [];
            $service = $this->getMockBuilder(CommunityService::class)->onlyMethods(['authenticate', 'call'])->getMock();
            $service->method('authenticate')->willReturn($user);
            $service->expects($this->once())->method('call')->with($user, $action, $payload)->willReturn([]);
            $request = Request::create('/', 'POST', $payload + ['client_os' => 'Windows'], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0)']);
            $this->assertSame(200, (new CommunityController())->handle($request, $action, $service)->getStatusCode());
        }
    }
}
