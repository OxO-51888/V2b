<?php

namespace Tests\Unit;

use App\Services\ConfigurationSaveService;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConfigurationSaveServiceTest extends TestCase
{
    private $app;
    private $directory;
    private $source;
    private $cache;
    private $initial;

    protected function setUp(): void
    {
        $this->directory = str_replace('\\', '/', sys_get_temp_dir()) . '/v2board-config-save-test-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        foreach (['config', 'bootstrap/cache'] as $path) {
            $files->makeDirectory($this->directory . '/' . $path, 0777, true);
        }
        $this->app = new Application($this->directory);
        $this->initial = [
            'app' => ['locale' => 'zh-CN', 'name' => 'Isolated fixture'],
            'v2board' => ['frontend_domestic_default_theme' => 0, 'frontend_theme' => 'default', 'app_name' => 'Original panel', 'currency' => 'CNY'],
            'database' => ['default' => 'fixture-only'],
            'mail' => ['mailer' => 'fixture-only'],
            'frontend' => ['domestic_hosts' => ['origin.example.test']],
            'theme' => ['default' => ['marker' => 'old'], 'NINI' => ['marker' => 'new']],
        ];
        $this->app->instance('config', new Repository($this->initial));
        $this->source = base_path('config/v2board.php');
        $this->cache = $this->app->getCachedConfigPath();
        $this->assertStringStartsWith($this->directory, str_replace('\\', '/', $this->cache));
        $this->write($this->source, $this->initial['v2board']);
        $this->write($this->cache, $this->initial);
    }

    protected function tearDown(): void
    {
        Application::setInstance(null);
        $expected = str_replace('\\', '/', sys_get_temp_dir()) . '/v2board-config-save-test-';
        if ($this->directory && strpos($this->directory, $expected) === 0) {
            (new Filesystem())->deleteDirectory($this->directory);
        }
    }

    private function write(string $path, array $value): void
    {
        file_put_contents($path, '<?php return ' . var_export($value, true) . ';' . PHP_EOL);
    }

    private function updated(): array
    {
        return array_merge($this->initial['v2board'], ['frontend_domestic_default_theme' => 1, 'frontend_theme' => 'NINI']);
    }

    private function bytes(): array
    {
        return [file_get_contents($this->source), is_file($this->cache) ? file_get_contents($this->cache) : null];
    }

    private function assertNoCandidates(): void
    {
        $this->assertSame([], glob($this->directory . '/config/.config-save-*'));
        $this->assertSame([], glob($this->directory . '/bootstrap/cache/.config-save-*'));
    }

    private function assertSaveFails(ConfigurationSaveService $service, int $status = 500, ?array $value = null): void
    {
        try {
            $service->save($value ?? $this->updated());
            $this->fail('The simulated save failure must not report success.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function testSaveCommitsMatchingSourceCacheAndRuntimeWithoutRebuildingOtherConfiguration(): void
    {
        $this->app['config']->set('app.locale', 'en');
        $this->app['config']->set('request_only', ['must_not_persist' => true]);
        $service = new RecordingConfigurationSaveService();
        $service->save($this->updated());
        $this->assertSame($this->updated(), require $this->source);
        $this->assertSame(array_merge($this->initial, ['v2board' => $this->updated()]), require $this->cache);
        $this->assertSame($this->updated(), config('v2board'));
        $this->assertSame('en', config('app.locale'));
        $this->assertSame([$this->source, $this->cache], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testSourceCacheDisagreementFromAnEarlierFailureIsRepairedFromTheEffectiveConfiguration(): void
    {
        $this->write($this->source, ['frontend_theme' => 'leftover-source', 'app_name' => 'uncommitted']);
        (new ConfigurationSaveService())->save($this->updated());
        $this->assertSame($this->updated(), require $this->source);
        $this->assertSame($this->updated(), (require $this->cache)['v2board']);
        $this->assertNoCandidates();
    }

    public function testSecondReplacementFailureRestoresBothFilesAndLeavesRuntimeUnchanged(): void
    {
        $before = $this->bytes();
        $service = new RecordingConfigurationSaveService();
        $service->failReplaceAt = 2;
        $this->assertSaveFails($service);
        $this->assertSame($before, $this->bytes());
        $this->assertSame($this->initial['v2board'], config('v2board'));
        $this->assertSame([$this->source, $this->source], $service->replaced, 'The source commit must be rolled back after the cache rename fails.');
        $this->assertNoCandidates();
    }

    public function testCacheCandidateValidationFailureNeverPublishesEitherFile(): void
    {
        $before = $this->bytes();
        $service = new RecordingConfigurationSaveService();
        // Loads are old-cache snapshot, prepared source, prepared cache.
        $service->failLoadAt = 3;
        $this->assertSaveFails($service);
        $this->assertSame($before, $this->bytes());
        $this->assertSame([], $service->replaced);
        $this->assertSame($this->initial['v2board'], config('v2board'));
        $this->assertNoCandidates();
    }

    public function testAValueThatCannotRoundTripThroughPhpFailsBeforeCommit(): void
    {
        $before = $this->bytes();
        $value = $this->updated();
        $value['invalid'] = static function () { return 'not serializable'; };
        $service = new RecordingConfigurationSaveService();
        $this->assertSaveFails($service, 500, $value);
        $this->assertSame($before, $this->bytes());
        $this->assertSame([], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testAnOlderRequestCannotOverwriteSettingsSavedByAnotherRequest(): void
    {
        $latest = $this->initial;
        $latest['v2board']['app_name'] = 'Saved by another request';
        $this->write($this->cache, $latest);
        $this->write($this->source, $latest['v2board']);
        $before = $this->bytes();
        $service = new RecordingConfigurationSaveService();
        $this->assertSaveFails($service, 409);
        $this->assertSame($before, $this->bytes());
        $this->assertSame([], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testExternalChangesDuringPreparationAreNotOverwritten(): void
    {
        $service = new RecordingConfigurationSaveService();
        $concurrent = $this->initial['v2board'];
        $concurrent['app_name'] = 'External source edit';
        $service->afterCandidateValidation = function () use ($concurrent) {
            $this->write($this->source, $concurrent);
        };
        $cacheBefore = file_get_contents($this->cache);
        $this->assertSaveFails($service, 409);
        $this->assertSame($concurrent, require $this->source);
        $this->assertSame($cacheBefore, file_get_contents($this->cache));
        $this->assertSame([], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testRollbackDoesNotOverwriteAConcurrentCacheChange(): void
    {
        $service = new RecordingConfigurationSaveService();
        $sourceBefore = file_get_contents($this->source);
        $concurrent = $this->initial;
        $concurrent['v2board']['app_name'] = 'Concurrent cache update';
        $service->beforeSecondReplace = function () use ($concurrent) {
            $this->write($this->cache, $concurrent);
            throw new RuntimeException('Simulated failure after an external cache change');
        };
        $this->assertSaveFails($service);
        $this->assertSame($sourceBefore, file_get_contents($this->source));
        $this->assertSame($concurrent, require $this->cache);
        $this->assertSame($this->initial['v2board'], config('v2board'));
        $this->assertNoCandidates();
    }

    public function testFailedEffectiveCacheVerificationRollsBackBothCommittedFiles(): void
    {
        $before = $this->bytes();
        $service = new RecordingConfigurationSaveService();
        $service->staleLoadPath = $this->cache;
        $service->staleValue = $this->initial;
        $this->assertSaveFails($service);
        $this->assertSame($before, $this->bytes());
        $this->assertSame($this->initial['v2board'], config('v2board'));
        $this->assertSame([$this->source, $this->cache, $this->cache, $this->source], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testUncachedSiteUpdatesSourceAndRuntimeWithoutCreatingACache(): void
    {
        unlink($this->cache);
        $service = new RecordingConfigurationSaveService();
        $service->save($this->updated());
        $this->assertSame($this->updated(), require $this->source);
        $this->assertSame($this->updated(), config('v2board'));
        $this->assertFileDoesNotExist($this->cache);
        $this->assertSame([$this->source], $service->replaced);
        $this->assertNoCandidates();
    }

    public function testSubscriptionRuleConfigurationUsesTheSameConsistentSavePath(): void
    {
        $controller = new \App\Http\Controllers\V1\Admin\SubscriptionRuleController();
        $method = new \ReflectionMethod($controller, 'writeV2boardConfig');
        $method->setAccessible(true);
        $method->invoke($controller, $this->updated());
        $this->assertSame($this->updated(), require $this->source);
        $this->assertSame($this->updated(), (require $this->cache)['v2board']);
        $this->assertSame($this->updated(), config('v2board'));
        $this->assertNoCandidates();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider opcacheAvailability
     */
    public function testUnavailableOrDisabledOpcacheDoesNotFailOrResetOtherSites(bool $invalidateAvailable): void
    {
        // Process-local PHP function shims exercise the real invalidate implementation.
        $GLOBALS['config_test_invalidate_available'] = $invalidateAvailable;
        $GLOBALS['config_test_invalidations'] = [];
        $GLOBALS['config_test_resets'] = 0;
        eval('namespace App\\Services;
            function function_exists($name) {
                if ($name === "opcache_invalidate") return $GLOBALS["config_test_invalidate_available"];
                if ($name === "opcache_reset") return true;
                return \\function_exists($name);
            }
            function opcache_invalidate($path, $force) {
                $GLOBALS["config_test_invalidations"][] = $path;
                return false;
            }
            function opcache_reset() {
                $GLOBALS["config_test_resets"]++;
                return false;
            }');
        (new ConfigurationSaveService())->save($this->updated());
        $this->assertSame($this->updated(), require $this->source);
        $this->assertSame($this->updated(), (require $this->cache)['v2board']);
        $this->assertSame(0, $GLOBALS['config_test_resets']);
        if ($invalidateAvailable) {
            $this->assertContains($this->source, $GLOBALS['config_test_invalidations']);
            $this->assertContains($this->cache, $GLOBALS['config_test_invalidations']);
            foreach ($GLOBALS['config_test_invalidations'] as $path) {
                $this->assertStringStartsWith(
                    str_replace('\\', '/', realpath($this->directory)) . '/',
                    str_replace('\\', '/', realpath(dirname($path))) . '/'
                );
            }
        } else {
            $this->assertSame([], $GLOBALS['config_test_invalidations']);
        }
        $this->assertNoCandidates();
    }

    public static function opcacheAvailability(): array
    {
        return ['function unavailable' => [false], 'function returns false' => [true]];
    }
}

class RecordingConfigurationSaveService extends ConfigurationSaveService
{
    public $replaced = [];
    public $failReplaceAt;
    public $failLoadAt;
    public $afterCandidateValidation;
    public $beforeSecondReplace;
    public $staleLoadPath;
    public $staleValue;
    private $replaceCalls = 0;
    private $loadCalls = 0;

    protected function replace(string $candidate, string $path): void
    {
        $this->replaceCalls++;
        if ($this->replaceCalls === 2 && $this->beforeSecondReplace) {
            ($this->beforeSecondReplace)();
        }
        if ($this->replaceCalls === $this->failReplaceAt) {
            throw new RuntimeException('Simulated configuration replacement failure');
        }
        parent::replace($candidate, $path);
        $this->replaced[] = $path;
    }

    protected function load(string $path): array
    {
        $this->loadCalls++;
        if ($this->loadCalls === $this->failLoadAt) {
            throw new RuntimeException('Simulated candidate validation failure');
        }
        if ($path === $this->staleLoadPath) {
            $this->staleLoadPath = null;
            return $this->staleValue;
        }
        $value = parent::load($path);
        if ($this->loadCalls === 3 && $this->afterCandidateValidation) {
            ($this->afterCandidateValidation)();
        }
        return $value;
    }
}
