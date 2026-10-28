<?php

namespace Tests\Unit;

use App\Jobs\SendEmailJob;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class FrameworkUpgradeTest extends TestCase
{
    public function testInstallAndUpdateAcceptRawSqlStrings(): void
    {
        foreach (['V2boardInstall', 'V2boardUpdate'] as $command) {
            $source = file_get_contents(app_path('Console/Commands/' . $command . '.php'));
            $this->assertStringContainsString('DB::statement($item)', $source);
            $this->assertStringNotContainsString('DB::select(DB::raw(', $source);
        }
        $this->assertTrue(\Illuminate\Support\Facades\DB::statement('CREATE TABLE framework_fixture (id INTEGER PRIMARY KEY)'));
        $this->assertTrue(\Illuminate\Support\Facades\DB::statement('INSERT INTO framework_fixture (id) VALUES (1)'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('framework_fixture')->count());
    }

    public function testDisabledNotificationsCannotDeliverOrStartReceiver(): void
    {
        config(['outbound.enabled' => false, 'mail.driver' => 'array']);
        Mail::raw('Fixture only', function ($message) {
            $message->to('nobody@example.invalid')->subject('Fixture');
        });
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
        config(['mail.driver' => 'smtp', 'mail.host' => 'smtp.example.invalid', 'mail.port' => 587]);
        $this->assertNull(Mail::raw('Fixture only', function ($message) {
            $message->to('nobody@example.invalid')->subject('Fixture');
        }));
        $this->assertFalse(class_exists(\App\Jobs\SendTelegramJob::class));
        $this->assertFalse(class_exists(\App\Services\TelegramService::class));
        $this->artisan('panel:telegram-run')->assertExitCode(0);
    }

    public function testDisabledNativeTelegramConnectionNeverCallsTheApi(): void
    {
        config(['outbound.enabled' => false]);
        $connection = new \App\Services\PanelTelegramConnection('/nonexistent', new \PDO('sqlite::memory:'));
        $method = new \ReflectionMethod($connection, 'telegram');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $method->invoke($connection, 'fixture', 'sendMessage', ['chat_id' => 123, 'text' => 'Fixture']);
    }

    public function testExistingRoutesAndUnauthorizedResponsesRemainAvailable(): void
    {
        $this->getJson('/api/v1/guest/comm/config')->assertOk();
        $this->getJson('/api/v1/user/info')->assertStatus(403);
        $this->getJson('/api/v1/staff/user/getUserInfoById')->assertStatus(403);
        $this->getJson('/api/v1/user/info?auth_data[id]=1&auth_data[is_admin]=1')->assertStatus(403);
        $this->assertSame(resource_path('lang'), lang_path());
    }

    public static function encryptionModes(): array
    {
        return [['ssl', 2465, true, false], ['tls', 587, false, true], [null, 2525, false, false]];
    }

    #[DataProvider('encryptionModes')]
    public function testLegacyMailSettingsRetainTlsSemanticsWithoutSending($encryption, $port, $tls, $startTls): void
    {
        config(['v2board.email_host' => 'smtp.example.invalid', 'v2board.email_port' => $port,
            'v2board.email_encryption' => $encryption, 'v2board.email_username' => 'fixture',
            'v2board.email_password' => 'fixture-only', 'v2board.email_from_address' => 'sender@example.invalid',
            'v2board.app_name' => 'Fixture', 'v2board.email_template' => 'default', 'mail.driver' => 'array']);
        Schema::create('v2_mail_log', function ($table) {
            $table->increments('id');
            foreach (['email','subject','template_name','error'] as $field) $table->text($field)->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $result = (new SendEmailJob(['email' => 'recipient@example.invalid', 'subject' => 'Fixture verification',
            'template_name' => 'verify', 'template_value' => ['name' => 'Fixture', 'code' => '123456', 'url' => 'https://example.invalid']]))->handle();
        $this->assertNull($result['error']);
        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertStringContainsString('123456', $message->getHtmlBody());
        $this->assertSame('recipient@example.invalid', $message->getTo()[0]->getAddress());
        config(['mail.driver' => 'smtp']);
        $transport = (new MailManager($this->app))->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame($tls, $transport->getStream()->isTLS());
        $this->assertSame($startTls, $transport->isAutoTls());
        $this->assertSame($startTls, $transport->isTlsRequired());
    }
}
