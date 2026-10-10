<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TelegramBindingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendTelegramBindingEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 30;
    public $telegramId;
    public $challengeId;
    protected $code;

    public function __construct(int $telegramId, string $challengeId, string $code)
    {
        $this->telegramId = $telegramId;
        $this->challengeId = $challengeId;
        $this->code = $code;
        $this->onQueue('send_email');
    }

    public function handle()
    {
        if (!config('telegram_binding.enabled') || !config('telegram_binding.mail_enabled')) return;
        $challenge = Cache::get(TelegramBindingService::key('challenge:' . $this->telegramId));
        if (!$challenge || $challenge['id'] !== $this->challengeId || $challenge['expires_at'] <= time()) return;
        $user = User::find($challenge['user_id']);
        if (!$user || (int) $user->banned === 1 || strtolower($user->email) !== $challenge['email']) return;
        $name = trim((string) config('v2board.app_name', '网站'));
        if ($name === '') $name = '网站';
        if (strpos($name, '机场') === false) $name .= '机场';
        $this->deliver([
            'email' => $challenge['email'],
            'subject' => $name . ' - Telegram绑定验证码',
            'template_name' => 'telegram_binding',
            'template_value' => ['name' => $name, 'code' => $this->code,
                'telegram_id' => $this->telegramId, 'expires_at' => $challenge['expires_at']],
        ]);
    }

    protected function deliver(array $params): void
    {
        $result = (new SendEmailJob($params))->handle();
        if (!empty($result['error'])) throw new \RuntimeException('Telegram binding email delivery failed');
    }
}
