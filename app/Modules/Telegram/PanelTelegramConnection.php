<?php

namespace App\Modules\Telegram;

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PDO;

class PanelTelegramConnection
{
    private $directory;
    private $db;
    private $http;

    public function __construct(string $directory, PDO $db, ?ClientInterface $http = null)
    {
        $this->directory = $directory;
        $this->db = $db;
        $this->http = $http ?? new Client();
    }

    public function all(): array
    {
        $valid = true;
        try {
            $env = Dotenv::createArrayBacked($this->directory)->safeLoad();
            $sites = json_decode(file_get_contents($this->directory . '/panel-sites.json'), true, 32, JSON_THROW_ON_ERROR);
            $settings = $this->db->query("SELECT key,value FROM settings WHERE key LIKE 'panel.connection.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $result = [];
            foreach ($sites as $site) {
                $key = 'panel.connection.' . $site['id'];
                $saved = isset($settings[$key]) ? json_decode($settings[$key], true, 16, JSON_THROW_ON_ERROR) : [];
                $result[$site['id']] = $saved + ['token' => trim($env['TELEGRAM_BOT_TOKEN'] ?? ''),
                    'chat_id' => (string) ($site['chat_ids'][0] ?? ''), 'username' => config('panel_modules.telegram.username')];
            }
        } catch (\Throwable $e) {
            $valid = false;
        }
        abort_unless($valid, 503, '机器人配置读取失败');
        return $result;
    }

    public function own(): array
    {
        $all = $this->all();
        $site = config('panel_modules.site');
        abort_unless(isset($all[$site]), 503, '本站机器人尚未配置');
        return $all[$site];
    }

    public static function masked(string $token): string
    {
        if ($token === '') return '';
        $id = explode(':', $token, 2)[0];
        return (ctype_digit($id) ? $id . ':' : '') . str_repeat('*', 24);
    }

    private function telegram(string $token, string $method, array $body = []): array
    {
        abort_unless(config('outbound.enabled', true), 503, 'Outbound notifications are disabled');
        $result = null;
        try {
            $response = $this->http->request('POST', 'https://api.telegram.org/bot' . $token . '/' . $method,
                ['json' => $body, 'connect_timeout' => 3, 'timeout' => 6, 'force_ip_resolve' => 'v4',
                    'allow_redirects' => false, 'http_errors' => false]);
            $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if ($response->getStatusCode() === 200 && ($data['ok'] ?? false) === true && is_array($data['result'] ?? null)) {
                $result = $data['result'];
            }
        } catch (\Throwable $e) {
            // Never surface the Telegram URL, which contains the credential.
        }
        abort_if($result === null, 422, '机器人校验失败，请检查 Token、群组 ID 和网络后重试；原配置未更改');
        return $result;
    }

    public function validate(string $token, string $chatId): array
    {
        abort_unless(preg_match('/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,100}\z/', $token), 422, 'Token 格式不正确');
        abort_unless(preg_match('/\A-[1-9][0-9]{0,14}\z/', $chatId), 422, '群组 ID 格式不正确');
        $botId = explode(':', $token, 2)[0];
        foreach ($this->all() as $site => $connection) {
            if ($site === config('panel_modules.site')) continue;
            abort_if((string) $connection['chat_id'] === $chatId, 422, '该群组已属于其他站点');
            abort_if(explode(':', $connection['token'], 2)[0] === $botId && !hash_equals($connection['token'], $token),
                422, '该机器人正由其他站点共用，不能仅为本站替换同一机器人的密钥；请使用独立机器人');
        }
        $me = $this->telegram($token, 'getMe');
        abort_unless(($me['is_bot'] ?? false) === true && (string) ($me['id'] ?? '') === $botId
            && preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $me['username'] ?? ''), 422, '机器人身份不正确');
        $webhook = $this->telegram($token, 'getWebhookInfo');
        abort_if(!empty($webhook['url']), 422, '该机器人已设置其他 Webhook，请先解除占用；原配置未更改');
        $group = $this->telegram($token, 'getChat', ['chat_id' => $chatId]);
        abort_unless((string) ($group['id'] ?? '') === $chatId && in_array($group['type'] ?? '', ['group', 'supergroup'], true), 422, '请输入群组 ID，不能使用私聊或频道 ID');
        $member = $this->telegram($token, 'getChatMember', ['chat_id' => $chatId, 'user_id' => (int) $botId]);
        abort_unless(($member['status'] ?? '') === 'administrator' && !empty($member['can_invite_users'])
            && !empty($member['can_delete_messages']) && !empty($member['can_restrict_members']),
            422, '请先将机器人设为该群管理员，并授予邀请用户、删除消息及封禁用户权限');
        return ['token' => $token, 'chat_id' => $chatId, 'username' => $me['username'], 'title' => (string) ($group['title'] ?? '')];
    }

    public function store(array $connection): void
    {
        $statement = $this->db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $statement->execute(['panel.connection.' . config('panel_modules.site'), json_encode($connection, JSON_THROW_ON_ERROR)]);
    }

    public function applied(): bool
    {
        $path = $this->directory . '/receiver-status.json';
        if (!is_file($path)) return false;
        $status = json_decode(file_get_contents($path), true);
        $own = $this->own();
        $state = $status['sites'][config('panel_modules.site')] ?? [];
        return ($status['updated_at'] ?? 0) > time() - 30 && ($state['ready'] ?? false) === true
            && hash_equals(hash('sha256', $own['token'] . "\n" . $own['chat_id']), $state['fingerprint'] ?? '');
    }
}
