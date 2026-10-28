<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyTelegramFeatureRemovalTest extends TestCase
{
    private User $member;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'retirement88', 'database.connections.retirement88' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'cache.default' => 'array', 'outbound.enabled' => false,
            'v2board.telegram_bot_enable' => 1, 'v2board.telegram_bot_token' => 'retired-fixture-token',
            'v2board.telegram_discuss_link' => 'https://t.me/fixture_support',
            'v2board.ticket_status' => 0, 'v2board.ticket_ai_auto_reply_enable' => 0,
            'v2board.withdraw_close_enable' => 0, 'v2board.commission_withdraw_limit' => 1,
            'v2board.commission_withdraw_method' => ['fixture']]);
        DB::purge('retirement88');
        Schema::create('v2_user', function ($t) {
            $t->increments('id'); $t->string('email'); $t->string('password'); $t->string('token');
            $t->bigInteger('telegram_id')->nullable();
            foreach (['banned', 'is_admin', 'is_staff', 'commission_balance', 'plan_id'] as $field) $t->integer($field)->default(0);
            foreach (['expired_at', 'created_at', 'updated_at'] as $field) $t->integer($field)->nullable();
        });
        Schema::create('v2_ticket', function ($t) {
            $t->increments('id'); $t->integer('user_id'); $t->string('subject'); $t->integer('level');
            $t->integer('status')->default(0); $t->integer('reply_status')->default(0);
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        Schema::create('v2_ticket_message', function ($t) {
            $t->increments('id'); $t->integer('ticket_id'); $t->integer('user_id'); $t->text('message');
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $this->member = User::create(['email' => 'member@example.invalid', 'password' => 'fixture-hash',
            'token' => 'fixture-token', 'telegram_id' => 9001, 'plan_id' => 1, 'commission_balance' => 10000]);
        $this->admin = User::create(['email' => 'admin@example.invalid', 'password' => 'fixture-hash',
            'token' => 'fixture-admin-token', 'telegram_id' => 9002, 'is_admin' => 1]);
        Mail::fake(); Queue::fake(); Http::preventStrayRequests();
    }

    private function headers(User $user): array
    {
        return ['Authorization' => (new AuthService($user))->generateAuthData(Request::create('/fixture'))['auth_data']];
    }

    private function assertNoLegacySideEffects(): void
    {
        Mail::assertNothingSent(); Queue::assertNothingPushed(); Http::assertNothingSent();
        $this->assertSame(9001, $this->member->fresh()->telegram_id);
        $this->assertSame(9002, $this->admin->fresh()->telegram_id);
    }

    public static function oldUserRoutes(): array
    {
        return [['/api/v1/user/telegram/getBotInfo', false], ['/api/v1/user/telegram/getBotInfo', true],
            ['/api/v1/user/unbindTelegram', false], ['/api/v1/user/unbindTelegram', true]];
    }

    #[DataProvider('oldUserRoutes')]
    public function testOldUserRoutesAreGoneEvenForSignedInUsers(string $path, bool $signedIn): void
    {
        $this->getJson($path, $signedIn ? $this->headers($this->member) : [])->assertNotFound();
        $this->assertNoLegacySideEffects();
    }

    public function testLegacyClassesAndNotificationQueueAreRemoved(): void
    {
        foreach (['Services/TelegramService', 'Jobs/SendTelegramJob', 'Console/Commands/CheckServer',
            'Http/Controllers/V1/User/TelegramController'] as $path) {
            $this->assertFileDoesNotExist(app_path($path . '.php'));
            $this->assertFalse(class_exists('App\\' . str_replace('/', '\\', $path)));
        }
        $horizon = require base_path('config/horizon.php');
        $queues = [];
        array_walk_recursive($horizon, function ($value) use (&$queues) { if (is_string($value)) $queues[] = $value; });
        $this->assertNotContains('send_telegram', $queues);
        foreach (['order_handle', 'traffic_fetch', 'stat', 'send_email', 'send_email_mass'] as $queue) $this->assertContains($queue, $queues);
    }

    public function testLegacySettingsAreNeitherAcceptedNorReturned(): void
    {
        foreach (['telegram_bot_enable', 'telegram_bot_token', 'telegram_discuss_id', 'telegram_channel_id'] as $key) {
            $this->assertArrayNotHasKey($key, ConfigSave::RULES);
        }
        $adminPath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
        $data = $this->getJson('/api/v1/' . $adminPath . '/config/fetch?key=telegram', $this->headers($this->admin))
            ->assertOk()->json('data.telegram');
        $this->assertSame(['telegram_discuss_link' => 'https://t.me/fixture_support'], $data);
        $this->assertNoLegacySideEffects();
    }

    public function testSupportGroupLinkStillFollowsPlanEligibility(): void
    {
        $headers = $this->headers($this->member);
        $response = $this->getJson('/api/v1/user/comm/config', $headers)->assertOk();
        $response->assertJsonPath('data.telegram_discuss_link', 'https://t.me/fixture_support');
        $this->assertArrayNotHasKey('is_telegram', $response->json('data'));
        $this->member->update(['expired_at' => time() - 10]);
        $this->getJson('/api/v1/user/comm/config', $headers)->assertOk()->assertJsonPath('data.telegram_discuss_link', null);
        $this->assertNoLegacySideEffects();
    }

    public function testTicketCreationWorksWithoutLegacyNotification(): void
    {
        $this->postJson('/api/v1/user/ticket/save', ['subject' => 'Fixture support', 'level' => 1,
            'message' => 'Fixture question'], $this->headers($this->member))->assertOk()->assertJsonPath('data', true);
        $this->assertSame(1, Ticket::count());
        $this->assertSame('Fixture question', TicketMessage::first()->message);
        $this->assertSame($this->member->id, Ticket::first()->user_id);
        $this->assertNoLegacySideEffects();
    }

    public function testTicketReplyAndCloseWorkWithoutLegacyNotification(): void
    {
        $ticket = Ticket::create(['user_id' => $this->member->id, 'subject' => 'Fixture', 'level' => 1]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'message' => 'Fixture staff reply']);
        $headers = $this->headers($this->member);
        $this->postJson('/api/v1/user/ticket/reply', ['id' => $ticket->id, 'message' => 'Fixture followup'], $headers)->assertOk();
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame(0, $ticket->fresh()->reply_status);
        $this->postJson('/api/v1/user/ticket/close', ['id' => $ticket->id], $headers)->assertOk();
        $this->assertSame(1, $ticket->fresh()->status);
        $this->assertNoLegacySideEffects();
    }

    public function testWithdrawalTicketWorksWithoutLegacyNotification(): void
    {
        $before = $this->member->fresh()->getAttributes();
        $this->postJson('/api/v1/user/ticket/withdraw', ['withdraw_method' => 'fixture', 'withdraw_account' => 'fixture-account'],
            $this->headers($this->member))->assertOk()->assertJsonPath('data', true);
        $this->assertSame(1, Ticket::count());
        $this->assertStringContainsString('fixture-account', TicketMessage::first()->message);
        $this->assertSame($before, $this->member->fresh()->getAttributes());
        $this->assertNoLegacySideEffects();
    }

    public function testBusinessCodeNoLongerReferencesLegacySenders(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])TelegramService\b|\bSendTelegramJob\b|telegram_bot_enable|telegram_bot_token/', $source, $file->getPathname());
            }
        }
    }
}
