<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Middleware\TelegramBotAuth;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyTelegramRemovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'retirement87', 'database.connections.retirement87' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'outbound.enabled' => false, 'telegram_binding.enabled' => true,
            'telegram_binding.secret' => str_repeat('a', 64), 'telegram_binding.mail_enabled' => false,
            'v2board.telegram_bot_token' => 'fixture-only-token', 'v2board.telegram_bot_enable' => 1]);
        DB::purge('retirement87');
        Schema::create('v2_user', function ($table) {
            $table->increments('id'); $table->string('email'); $table->string('token');
            $table->bigInteger('telegram_id')->nullable(); $table->integer('banned')->default(0);
            $table->integer('is_admin')->default(0); $table->integer('is_staff')->default(0);
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        User::create(['email' => 'fixture@example.invalid', 'token' => 'fixture-secret', 'telegram_id' => 9001]);
        Mail::fake(); Queue::fake();
    }

    public static function oldCommands(): array
    {
        return ['bind' => ['/bind https://fixture.invalid/?token=fixture-secret'],
            'unbind' => ['/unbind'], 'traffic' => ['/traffic'], 'url' => ['/getlatesturl'],
            'ticket_reply' => ['fixture reply']];
    }

    #[DataProvider('oldCommands')]
    public function testLegacyWebhookIsGoneEvenWithOldValidSecret(string $command): void
    {
        $before = User::first()->getAttributes();
        $message = ['text' => $command, 'chat' => ['id' => 9001, 'type' => 'private'], 'message_id' => 1];
        if ($command === 'fixture reply') $message['reply_to_message'] = ['text' => '#1'];
        $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5('fixture-only-token'), [
            'message' => $message,
        ])->assertStatus(404);
        $this->assertSame($before, User::first()->getAttributes());
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function testOldRoutesAndConfigurationMethodsAreNotRegistered(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $this->assertStringNotContainsString('guest/telegram/webhook', $route->uri());
            $this->assertStringNotContainsString('config/setTelegramWebhook', $route->uri());
        }
        $this->assertFalse(method_exists(ConfigController::class, 'setTelegramWebhook'));
        foreach (['setWebhook', 'discoverCommands', 'setMyCommands'] as $method) {
            $this->assertFalse(method_exists(TelegramService::class, $method));
        }
        $adminPath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->postJson('/api/v1/' . $adminPath . '/config/setTelegramWebhook', [
            'user' => ['id' => 1, 'is_admin' => 1], 'telegram_bot_token' => 'fixture-only-token',
        ])->assertStatus(404);
    }

    public function testRetiredControllerAndCommandsCannotAutoload(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/V1/Guest/TelegramController.php'));
        $this->assertFalse(class_exists('App\\Http\\Controllers\\V1\\Guest\\TelegramController'));
        foreach (['Telegram', 'Commands/Bind', 'Commands/UnBind', 'Commands/Traffic', 'Commands/ReplyTicket', 'Commands/GetLatestUrl'] as $path) {
            $this->assertFileDoesNotExist(app_path('Plugins/Telegram/' . $path . '.php'));
        }
    }

    public static function nativeEndpoints(): array
    {
        return array_map(fn ($action) => [$action], ['email/request', 'email/confirm', 'email/unbind/prepare',
            'email/unbind/confirm', 'verify', 'account/subscription', 'account/plan']);
    }

    #[DataProvider('nativeEndpoints')]
    public function testNativeEndpointsRemainRegisteredAndAuthenticated(string $action): void
    {
        $path = '/api/v1/bot/telegram/' . $action;
        $route = $this->app['router']->getRoutes()->match(Request::create($path, 'POST'));
        $this->assertContains(TelegramBotAuth::class, $route->gatherMiddleware());
        $this->postJson($path, ['telegram_id' => 9001, 'user' => ['id' => 1, 'is_admin' => 1]])->assertStatus(401);
        $this->assertSame(9001, User::first()->telegram_id);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function testNativeSignedVerificationStillWorks(): void
    {
        $path = '/api/v1/bot/telegram/verify';
        $body = json_encode(['telegram_id' => 9001]);
        $timestamp = (string)time(); $nonce = bin2hex(random_bytes(16));
        $canonical = $timestamp . "\n" . $nonce . "\nPOST\n" . $path . "\n" . hash('sha256', $body);
        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TG_TIMESTAMP' => $timestamp, 'HTTP_X_TG_NONCE' => $nonce,
            'HTTP_X_TG_SIGNATURE' => hash_hmac('sha256', $canonical, config('telegram_binding.secret')),
        ], $body);
        $response->assertOk()->assertJsonPath('data.allowed', true);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
}
