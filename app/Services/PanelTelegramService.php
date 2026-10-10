<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use PDO;

class PanelTelegramService
{
    private $db;
    private $chatId;
    private $site;
    private $connection;

    public function __construct(?ClientInterface $http = null)
    {
        $directory = config('panel_modules.telegram.directory');
        $this->site = config('panel_modules.site');
        $this->chatId = (string) config('panel_modules.telegram.chat_id');
        $sites = json_decode(file_get_contents($directory . '/panel-sites.json'), true, 32, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($sites, fn($site) => in_array($this->chatId, array_map('strval', $site['chat_ids'] ?? []), true)));
        abort_unless(count($matches) === 1 && $matches[0]['id'] === $this->site, 503, '本站群组配置不匹配');
        abort_unless(is_file($directory . '/bot_admin.sqlite3'), 503);
        $this->db = new PDO('sqlite:' . $directory . '/bot_admin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=3000');
        $this->connection = new PanelTelegramConnection($directory, $this->db, $http);
        $this->chatId = (string) $this->connection->own()['chat_id'];
    }

    private function query(string $sql, array $args = []): array
    {
        $statement = $this->db->prepare($sql);
        foreach ($args as $i => $value) $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->execute();
        return $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    private function group(): array
    {
        $rows = $this->query('SELECT chat_id,title,enabled,verify_join,verify_message,updated_at FROM groups WHERE chat_id=?', [$this->chatId]);
        abort_unless(count($rows) === 1, 503, '本站群组尚未接入');
        $row = $rows[0];
        foreach (['chat_id', 'enabled', 'verify_join', 'verify_message', 'updated_at'] as $key) $row[$key] = (int) $row[$key];
        return $row + ['revision' => hash('sha256', json_encode([$row, $this->connection->own()], JSON_THROW_ON_ERROR))];
    }

    public function request(string $method, string $path, array $data): array
    {
        if ($method === 'GET' && $path === '/v1/state') {
            $connection = $this->connection->own();
            return ['site' => $this->site, 'bot_username' => $connection['username'], 'bot_token_masked' => PanelTelegramConnection::masked($connection['token']), 'group' => $this->group(),
                'connection_applied' => $this->connection->applied(),
                'pending_count' => (int) $this->query('SELECT COUNT(*) AS n FROM pending_joins WHERE chat_id=? AND expires_at>?', [$this->chatId, time()])[0]['n'],
                'join_timeout_seconds' => 300, 'prompt_delete_seconds' => 10, 'spam_repeat_count' => 10];
        }
        if ($method === 'GET' && $path === '/v1/events') {
            abort_if(array_diff(array_keys($data), ['before', 'limit']), 422);
            $before = filter_var($data['before'] ?? 0, FILTER_VALIDATE_INT);
            $limit = filter_var($data['limit'] ?? 30, FILTER_VALIDATE_INT);
            abort_unless($before !== false && $before >= 0 && $limit !== false && $limit >= 1 && $limit <= 100, 422);
            $items = $this->query('SELECT id,event_type,telegram_id,username,display_name,action,reason,created_at FROM events WHERE chat_id=? AND (?=0 OR id<?) ORDER BY id DESC LIMIT ?', [$this->chatId, $before, $before, $limit + 1]);
            $more = count($items) > $limit; $items = array_slice($items, 0, $limit);
            return ['items' => $items, 'next_cursor' => $more ? end($items)['id'] : null];
        }
        abort_unless($method === 'POST' && in_array($path, ['/v1/group', '/v1/configure'], true), 404);
        $configure = $path === '/v1/configure';
        $flags = ['enabled', 'verify_join', 'verify_message'];
        abort_unless(!array_diff(array_merge($flags, ['revision']), array_keys($data))
            && !array_diff(array_keys($data), array_merge($flags, $configure ? ['revision', 'token', 'chat_id'] : ['revision'])), 422);
        foreach ($flags as $key) abort_unless(is_bool($data[$key]), 422);
        abort_unless(is_string($data['revision']) && strlen($data['revision']) === 64, 422);
        $row = $this->group();
        abort_unless(hash_equals($row['revision'], $data['revision']), 409, '设置已被修改，请刷新后重试');
        $connection = $this->connection->own();
        abort_unless(!isset($data['token']) || is_string($data['token']), 422);
        abort_unless(!isset($data['chat_id']) || is_string($data['chat_id']), 422);
        $token = trim($data['token'] ?? '') ?: $connection['token'];
        $chatId = trim($data['chat_id'] ?? $this->chatId);
        $connectionChanged = !hash_equals($connection['token'], $token) || $chatId !== $this->chatId;
        $candidate = $configure ? $this->connection->validate($token, $chatId) : null;
        $oldChatId = $this->chatId;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row = $this->group();
            abort_unless(hash_equals($row['revision'], $data['revision']), 409, '设置已被修改，请刷新后重试');
            $changed = false;
            foreach ($flags as $key) if ((bool) $row[$key] !== $data[$key]) $changed = true;
            if ($configure) {
                foreach ($this->connection->all() as $site => $other) {
                    if ($site === $this->site) continue;
                    abort_if((string) $other['chat_id'] === $chatId, 409, '群组配置已变化，请刷新后重试');
                    abort_if(explode(':', $other['token'], 2)[0] === explode(':', $token, 2)[0]
                        && !hash_equals($other['token'], $token), 409, '机器人配置已变化，请刷新后重试');
                }
                $this->connection->store($candidate);
                $this->chatId = $chatId;
                $this->query('INSERT INTO groups(chat_id,title,enabled,verify_join,verify_message,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title',
                    [$chatId, $candidate['title'], (int) $data['enabled'], (int) $data['verify_join'], (int) $data['verify_message'], time(), time()]);
                $row['title'] = $candidate['title'];
                $changed = $connectionChanged || $changed;
            }
            if ($changed) {
                $this->query('UPDATE groups SET enabled=?,verify_join=?,verify_message=?,updated_at=? WHERE chat_id=?',
                    [(int) $data['enabled'], (int) $data['verify_join'], (int) $data['verify_message'], time(), $this->chatId]);
                $this->query('INSERT INTO events(event_type,chat_id,chat_title,action,reason,created_at) VALUES(?,?,?,?,?,?)',
                    ['panel.group.settings', $this->chatId, $row['title'], 'updated', $connectionChanged ? '本站机器人连接配置已更新' : '本站面板管理员更新验证开关', time()]);
            }
            $result = ['group' => $this->group(), 'changed' => $changed, 'connection_changed' => $connectionChanged, 'configured' => $configure];
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            $this->chatId = $oldChatId;
            throw $e;
        }
    }
}
