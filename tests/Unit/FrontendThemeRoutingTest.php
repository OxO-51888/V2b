<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\Admin\ThemeController;
use App\Http\Requests\Admin\ConfigSave;
use App\Services\ConfigurationSaveService;
use App\Services\FrontendThemeService;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\View\ViewServiceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontendThemeRoutingTest extends TestCase
{
    private $app;
    private $directory;
    private $filesystem;
    private $router;
    private $validators;

    protected function setUp(): void
    {
        // No application bootstrap, site .env, database, or production assets are loaded.
        $this->directory = str_replace('\\', '/', sys_get_temp_dir()) . '/v2board-theme-test-' . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        foreach (['config', 'bootstrap/cache', 'compiled', 'public/theme/default', 'public/theme/NINI'] as $path) {
            $this->filesystem->makeDirectory($this->directory . '/' . $path, 0777, true);
        }
        $this->app = new Application($this->directory);
        $this->app->instance('path.public', $this->directory . '/public');
        $this->app->instance('files', $this->filesystem);
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->instance('config', new Repository([
            'app' => ['key' => 'isolated-test-key', 'version' => 'test'],
            'v2board' => ['frontend_theme' => 'NINI', 'app_url' => 'https://overseas.example.test', 'secure_path' => 'test-admin'],
            'frontend' => ['domestic_hosts' => ['origin.example.test']],
            'theme' => ['default' => ['marker' => 'old-config'], 'NINI' => ['marker' => 'new-config']],
            'view' => ['paths' => [], 'compiled' => $this->directory . '/compiled'],
        ]));
        foreach (['default', 'NINI'] as $theme) {
            $this->filesystem->put($this->directory . '/public/theme/' . $theme . '/config.json', json_encode([
                'name' => $theme, 'configs' => [['field_name' => 'marker', 'default_value' => $theme]],
            ]));
            $this->filesystem->put($this->directory . '/public/theme/' . $theme . '/dashboard.blade.php',
                'view=' . $theme . ';selected={{ $theme }};config={{ $theme_config[\'marker\'] }};asset=/theme/{{ $theme }}/assets/main.js');
        }
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('make')->andReturnUsing(function ($data, $status, $headers) {
            return new Response($data, $status, $headers);
        });
        $this->app->instance(ResponseFactory::class, $response);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->register(ViewServiceProvider::class);
        $this->app['view']->addNamespace('theme', $this->directory . '/public/theme');
        $this->router = new Router($this->app['events'], $this->app);
        $this->app->instance('router', $this->router);
        if (!class_exists('Route', false)) {
            class_alias(\Illuminate\Support\Facades\Route::class, 'Route');
        }
        require dirname(__DIR__, 2) . '/routes/web.php';
        $this->validators = new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $this->app->instance(\Illuminate\Contracts\Validation\Factory::class, $this->validators);
        $this->app->bind(\Illuminate\Routing\Redirector::class, function () {
            return new \Illuminate\Routing\Redirector(new \Illuminate\Routing\UrlGenerator($this->router->getRoutes(), $this->app['request']));
        });
        (new \Illuminate\Foundation\Providers\FormRequestServiceProvider($this->app))->boot();
        $this->router->post('/api/v1/test-admin/config/save', [ConfigController::class, 'save']);
        $this->router->get('/api/v1/test-admin/config/fetch', [ConfigController::class, 'fetch']);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Application::setInstance(null);
            // Remove only the unique directory this test created beneath the temp directory.
            $expected = str_replace('\\', '/', sys_get_temp_dir()) . '/v2board-theme-test-';
            if ($this->directory && strpos($this->directory, $expected) === 0) {
                $this->filesystem->deleteDirectory($this->directory);
            }
        }
    }

    private function dispatch(string $host, string $query = '', array $headers = []): Response
    {
        $before = config('v2board');
        $request = Request::create('/' . $query, 'GET', [], [], [], array_merge([
            'HTTP_HOST' => $host,
        ], $headers));
        $this->app->instance('request', $request);
        $response = $this->router->dispatch($request);
        $this->assertSame($before, config('v2board'), 'Rendering must not mutate saved settings.');
        return $response;
    }

    private function assertTheme(string $theme, Response $response): void
    {
        $marker = $theme === 'default' ? 'old-config' : 'new-config';
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("view={$theme};selected={$theme};config={$marker};asset=/theme/{$theme}/assets/main.js", $response->getContent());
    }

    public function testConfiguredModeSwitchesAllRoutesBetweenSplitThemesAndDefault(): void
    {
        // ON must activate NINI globally even if the stored legacy theme was default.
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        $this->assertTheme('default', $this->dispatch('origin.example.test'));
        $this->assertTheme('NINI', $this->dispatch('overseas.example.test'));
        $this->assertTheme('NINI', $this->dispatch('unknown.example.test'));
        // OFF must select default everywhere even if a stale legacy value is NINI.
        $this->app['config']->set('v2board.frontend_theme', 'NINI');
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 0);
        $this->assertTheme('default', $this->dispatch('origin.example.test'));
        $this->assertTheme('default', $this->dispatch('overseas.example.test'));
        $this->assertTheme('default', $this->dispatch('unknown.example.test'));
        $this->app['config']->set('v2board.frontend_domestic_default_theme', '1');
        $this->assertTheme('default', $this->dispatch('origin.example.test'));
        $this->assertTheme('NINI', $this->dispatch('overseas.example.test'));
    }

    public function testSitesWithoutTheModeKeyKeepTheirLegacyThemeOnEveryHost(): void
    {
        $this->assertArrayNotHasKey('frontend_domestic_default_theme', config('v2board'));
        foreach (['NINI', 'default'] as $legacyTheme) {
            $this->app['config']->set('v2board.frontend_theme', $legacyTheme);
            foreach (['origin.example.test', 'overseas.example.test', 'unknown.example.test'] as $host) {
                $this->assertTheme($legacyTheme, $this->dispatch($host));
            }
        }
    }

    /** @dataProvider domesticHostVariants */
    public function testDomesticOriginHostIsStableAcrossCaseTrailingDotAndPort(string $host): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        $this->assertTheme('default', $this->dispatch($host));
    }

    public static function domesticHostVariants(): array
    {
        return [['origin.example.test'], ['ORIGIN.EXAMPLE.TEST'], ['origin.example.test.'],
            ['origin.example.test:443'], ['ORIGIN.EXAMPLE.TEST.:51888']];
    }

    public function testConfiguredHostVariantsAreNormalizedAndSeveralOriginsAreSupported(): void
    {
        $this->app['config']->set('frontend.domestic_hosts', [' ORIGIN.EXAMPLE.TEST.:443 ', 'second.example.test', '', null, 'https://invalid.example.test']);
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        $this->assertTheme('default', $this->dispatch('origin.example.test'));
        $this->assertTheme('default', $this->dispatch('second.example.test:51888'));
        $this->assertTheme('NINI', $this->dispatch('invalid.example.test'));
    }

    /** @dataProvider unrecognizedHosts */
    public function testOnlyExplicitOriginHostsSelectTheOldTheme(string $host): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        $this->assertTheme('NINI', $this->dispatch($host));
    }

    public static function unrecognizedHosts(): array
    {
        return [['overseas.example.test'], ['origin.example.test.evil.test'], ['child.origin.example.test'],
            ['203.0.113.19:51811'], ['origin.example.test:99999']];
    }

    public function testForwardedHostAndQueryParametersCannotSelectTheDomesticTheme(): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        $headers = ['HTTP_X_FORWARDED_HOST' => 'origin.example.test', 'HTTP_FORWARDED' => 'host=origin.example.test'];
        $this->assertTheme('NINI', $this->dispatch('overseas.example.test',
            '?theme=default&host=origin.example.test&frontend_domestic_default_theme=1', $headers));
        $this->assertTheme('default', $this->dispatch('origin.example.test', '?theme=NINI', [
            'HTTP_X_FORWARDED_HOST' => 'overseas.example.test',
        ]));
    }

    public function testUnknownHostConfigurationDoesNotGuessDomesticEntrypoints(): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 1);
        foreach ([[], 'origin.example.test', [null, '', 'https://origin.example.test']] as $hosts) {
            $this->app['config']->set('frontend.domestic_hosts', $hosts);
            $this->assertFalse((new FrontendThemeService())->domesticEntryReady());
            $this->assertTheme('NINI', $this->dispatch('origin.example.test'));
        }
    }

    public function testOriginHostConfigurationReadsTheExplicitEnvironmentListAndDefaultsEmpty(): void
    {
        $name = 'FRONTEND_DOMESTIC_HOSTS';
        $previousProcess = getenv($name);
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        try {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
            $config = require dirname(__DIR__, 2) . '/config/frontend.php';
            $this->assertSame([], $config['domestic_hosts']);

            $value = ' origin.example.test, second.example.test ,,';
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
            $config = require dirname(__DIR__, 2) . '/config/frontend.php';
            $this->assertSame(['origin.example.test', 'second.example.test'], $config['domestic_hosts']);
        } finally {
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
            putenv($previousProcess === false ? $name : $name . '=' . $previousProcess);
        }
    }

    /** @dataProvider safeModeCases */
    public function testSafeModeOnlyAddsExplicitDomesticHostsWhileTheSwitchIsOn($enabled, string $host, array $headers, bool $allowed): void
    {
        $this->app['config']->set('v2board.safe_mode_enable', 1);
        $this->app['config']->set('v2board.frontend_domestic_default_theme', $enabled);
        if (!$allowed) {
            try {
                $this->dispatch($host, '?theme=default&host=origin.example.test', $headers);
                $this->fail('Safe mode accepted an unauthorized host.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            return;
        }
        $this->assertTheme($enabled === 1 && $host === 'overseas.example.test' ? 'NINI' : 'default', $this->dispatch($host, '', $headers));
    }

    public static function safeModeCases(): array
    {
        return [
            'canonical while off' => [0, 'overseas.example.test', [], true],
            'canonical while on' => [1, 'overseas.example.test', [], true],
            'domestic while on' => [1, 'origin.example.test', [], true],
            'normalized domestic while on' => [1, 'ORIGIN.EXAMPLE.TEST.:51811', [], true],
            'domestic while off' => [0, 'origin.example.test', [], false],
            'unknown while on' => [1, 'unknown.example.test', [], false],
            'forwarded spoof' => [1, 'unknown.example.test', ['HTTP_X_FORWARDED_HOST' => 'origin.example.test'], false],
        ];
    }

    private function validateConfig(array $data)
    {
        $request = ConfigSave::create('/api/v1/test-admin/config/save', 'POST', $data);
        $request->setContainer($this->app);
        $validator = $this->validators->make($request->all(), $request->rules(), $request->messages());
        $request->setValidator($validator);
        return [$request, $validator];
    }

    /** @dataProvider switchInputs */
    public function testConfigSaveValidatesSwitchValues($value, bool $valid): void
    {
        [, $validator] = $this->validateConfig(['frontend_domestic_default_theme' => $value]);
        $this->assertSame($valid, $validator->passes());
        if (!$valid) {
            $this->assertTrue($validator->errors()->has('frontend_domestic_default_theme'));
        }
    }

    public static function switchInputs(): array
    {
        return [[0, true], [1, true], ['0', true], ['1', true], [2, false], [-1, false], ['on', false], [[], false]];
    }

    /** @dataProvider unavailableEntryConditions */
    public function testEnablingRequiresConfiguredOriginsAndBothThemesButDisablingRemainsPossible(string $missing): void
    {
        if ($missing === 'hosts') {
            $this->app['config']->set('frontend.domestic_hosts', []);
        } else {
            $this->filesystem->delete($this->directory . '/public/theme/' . $missing);
        }
        $this->assertFalse((new FrontendThemeService())->domesticEntryReady());
        [, $on] = $this->validateConfig(['frontend_domestic_default_theme' => 1]);
        $this->assertFalse($on->passes());
        $this->assertTrue($on->errors()->has('frontend_domestic_default_theme'));
        [, $off] = $this->validateConfig(['frontend_domestic_default_theme' => 0]);
        $this->assertTrue($off->passes());
        [, $unrelated] = $this->validateConfig(['app_name' => 'Updated test panel']);
        $this->assertTrue($unrelated->passes());
    }

    public static function unavailableEntryConditions(): array
    {
        return [['hosts'], ['default/config.json'], ['default/dashboard.blade.php'], ['NINI/config.json'], ['NINI/dashboard.blade.php']];
    }

    public function testGetThemesExposesTypedSwitchAndReadinessWithoutChangingActiveTheme(): void
    {
        $controller = new ThemeController();
        $data = json_decode($controller->getThemes()->getContent(), true)['data'];
        $this->assertSame(0, $data['domestic_default_theme']);
        $this->assertTrue($data['domestic_entry_ready']);
        $this->assertSame('NINI', $data['active']);
        $this->assertSame(['NINI', 'default'], array_keys($data['themes']));

        $this->app['config']->set('v2board.frontend_domestic_default_theme', '0');
        $data = json_decode($controller->getThemes()->getContent(), true)['data'];
        $this->assertSame(0, $data['domestic_default_theme']);
        $this->assertSame('default', $data['active']);

        $this->app['config']->set('v2board.frontend_theme', 'default');
        $this->app['config']->set('v2board.frontend_domestic_default_theme', '1');
        $this->app['config']->set('frontend.domestic_hosts', []);
        $data = json_decode($controller->getThemes()->getContent(), true)['data'];
        $this->assertSame(1, $data['domestic_default_theme']);
        $this->assertFalse($data['domestic_entry_ready']);
        $this->assertSame('NINI', $data['active']);
    }

    public function testConfigFetchExposesTheFrontendSwitchAsAnInteger(): void
    {
        $controller = new ConfigController();
        $request = Request::create('/api/v1/test-admin/config/fetch', 'GET', ['key' => 'frontend']);
        $data = json_decode($controller->fetch($request)->getContent(), true)['data'];
        $this->assertSame(0, $data['frontend']['frontend_domestic_default_theme']);
        $this->assertSame('NINI', $data['frontend']['frontend_theme'], 'An absent mode key retains the legacy theme.');
        $this->app['config']->set('v2board.frontend_domestic_default_theme', '0');
        $data = json_decode($controller->fetch($request)->getContent(), true)['data'];
        $this->assertSame('default', $data['frontend']['frontend_theme']);
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $this->app['config']->set('v2board.frontend_domestic_default_theme', '1');
        $data = json_decode($controller->fetch($request)->getContent(), true)['data'];
        $this->assertSame(1, $data['frontend']['frontend_domestic_default_theme']);
        $this->assertSame('NINI', $data['frontend']['frontend_theme']);
    }

    private function prepareConfigStorage(int $successfulSaves): string
    {
        $cache = Mockery::mock();
        $cache->shouldReceive('has')->with('WEBMANPID')->times($successfulSaves)->andReturn(false);
        Cache::swap($cache);
        $target = $this->directory . '/config/v2board.php';
        $cachePath = $this->app->getCachedConfigPath();
        $this->assertStringStartsWith($this->directory, str_replace('\\', '/', $cachePath));
        file_put_contents($target, '<?php return ' . var_export(config('v2board'), true) . ';');
        file_put_contents($cachePath, '<?php return ' . var_export($this->app['config']->all(), true) . ';');
        return $target;
    }

    public function testExistingSaveWritesModeAndActiveThemeTogetherAndPreservesOtherSettings(): void
    {
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $this->app['config']->set('v2board.app_name', 'Original panel');
        $this->app['config']->set('v2board.currency', 'CNY');
        $this->app['config']->set('v2board.ticket_ai_api_key', 'isolated-fixture-key');
        $this->app['config']->set('v2board.unrelated_existing_option', ['keep' => true]);
        $target = $this->prepareConfigStorage(2);
        $before = config('v2board');
        [$request, $validator] = $this->validateConfig([
            'frontend_domestic_default_theme' => 1,
            'ticket_ai_api_key' => '',
            'unexpected_input' => 'must-not-persist',
        ]);
        $this->assertTrue($validator->passes());
        $this->assertSame(['data' => true], json_decode((new ConfigController())->save($request)->getContent(), true));
        $persisted = require $target;
        $this->assertSame(array_merge($before, ['frontend_domestic_default_theme' => 1, 'frontend_theme' => 'NINI']), $persisted);
        $this->assertArrayNotHasKey('unexpected_input', $persisted);
        $this->assertSame($persisted, (require $this->app->getCachedConfigPath())['v2board']);
        $this->assertSame($persisted, config('v2board'));

        $this->app['config']->set('v2board', $persisted);
        $this->app['config']->set('frontend.domestic_hosts', []);
        [$request, $validator] = $this->validateConfig(['frontend_domestic_default_theme' => 0]);
        $this->assertTrue($validator->passes());
        (new ConfigController())->save($request);
        $this->assertSame(array_merge($before, ['frontend_domestic_default_theme' => 0, 'frontend_theme' => 'default']), require $target);
        $this->assertSame(require $target, (require $this->app->getCachedConfigPath())['v2board']);
    }

    /** @dataProvider conflictingThemeSaves */
    public function testOldActivationRequestsCannotContradictAConfiguredMode($mode, string $requested, string $expected): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', $mode);
        $this->app['config']->set('v2board.frontend_theme', $expected);
        $before = config('v2board');
        $target = $this->prepareConfigStorage(1);
        [$request, $validator] = $this->validateConfig(['frontend_theme' => $requested]);
        $this->assertTrue($validator->passes());
        (new ConfigController())->save($request);
        $this->assertSame($before, require $target);
    }

    public static function conflictingThemeSaves(): array
    {
        return [[0, 'NINI', 'default'], [1, 'default', 'NINI'], ['0', 'NINI', 'default'], ['1', 'another-theme', 'NINI']];
    }

    public function testLegacyActivationWithoutAModeKeyRemainsUnchanged(): void
    {
        $before = config('v2board');
        $target = $this->prepareConfigStorage(1);
        [$request, $validator] = $this->validateConfig(['frontend_theme' => 'default']);
        $this->assertTrue($validator->passes());
        (new ConfigController())->save($request);
        $persisted = require $target;
        $this->assertSame(array_merge($before, ['frontend_theme' => 'default']), $persisted);
        $this->assertArrayNotHasKey('frontend_domestic_default_theme', $persisted);
    }

    public function testExplicitModeWinsOverAConflictingThemeInTheSameSave(): void
    {
        $target = $this->prepareConfigStorage(2);
        foreach ([[1, 'default', 'NINI'], [0, 'NINI', 'default']] as [$flag, $requested, $expected]) {
            [$request, $validator] = $this->validateConfig([
                'frontend_domestic_default_theme' => $flag,
                'frontend_theme' => $requested,
            ]);
            $this->assertTrue($validator->passes());
            (new ConfigController())->save($request);
            $persisted = require $target;
            $this->assertSame($flag, $persisted['frontend_domestic_default_theme']);
            $this->assertSame($expected, $persisted['frontend_theme']);
            $this->app['config']->set('v2board', $persisted);
        }
    }

    public function testARejectedEnableDoesNotWriteTheModeOrActiveTheme(): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 0);
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $before = config('v2board');
        $this->filesystem->delete($this->directory . '/public/theme/NINI/dashboard.blade.php');
        $target = $this->prepareConfigStorage(0);
        file_put_contents($target, '<?php return ' . var_export($before, true) . ';');
        [$request] = $this->validateConfig(['frontend_domestic_default_theme' => 1]);
        try {
            (new ConfigController())->save($request);
            $this->fail('Missing NINI assets must reject enabling before persistence.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('frontend_domestic_default_theme', $exception->errors());
        }
        $this->assertSame($before, require $target);
        $this->assertSame($before, config('v2board'));
    }

    public function testFailedConfigurationWriteKeepsThePreviousModeAndActiveTheme(): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 0);
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $before = config('v2board');
        $target = $this->prepareConfigStorage(0);
        $cacheBefore = file_get_contents($this->app->getCachedConfigPath());
        $this->app->instance(ConfigurationSaveService::class, new class extends ConfigurationSaveService {
            protected function replace(string $candidate, string $path): void
            {
                throw new \RuntimeException('Simulated filesystem replacement failure');
            }
        });
        [$request, $validator] = $this->validateConfig(['frontend_domestic_default_theme' => 1]);
        $this->assertTrue($validator->passes());
        try {
            (new ConfigController())->save($request);
            $this->fail('A failed configuration write must not report success.');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }
        $this->assertSame($before, require $target);
        $this->assertSame($before, config('v2board'));
        $this->assertSame($cacheBefore, file_get_contents($this->app->getCachedConfigPath()));
    }

    public function testRealSaveApiKeepsSourceCacheAndRenderedModeInSyncAcrossRepeatedChanges(): void
    {
        $this->app['config']->set('v2board.frontend_domestic_default_theme', 0);
        $this->app['config']->set('v2board.frontend_theme', 'default');
        $source = $this->prepareConfigStorage(3);
        foreach ([1, 0, 1] as $flag) {
            $request = Request::create('/api/v1/test-admin/config/save', 'POST', ['frontend_domestic_default_theme' => $flag], [], [], ['HTTP_ACCEPT' => 'application/json']);
            $this->app->instance('request', $request);
            $response = $this->router->dispatch($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['data' => true], json_decode($response->getContent(), true));
            $saved = require $source;
            $this->assertSame($flag, $saved['frontend_domestic_default_theme']);
            $this->assertSame($flag ? 'NINI' : 'default', $saved['frontend_theme']);
            $this->assertSame($saved, (require $this->app->getCachedConfigPath())['v2board']);
            $this->assertSame($saved, config('v2board'));
            $this->assertTheme('default', $this->dispatch('origin.example.test'));
            $this->assertTheme($flag ? 'NINI' : 'default', $this->dispatch('overseas.example.test'));
        }
    }
}
