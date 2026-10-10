<?php

namespace App\Services\ClientGateway;

use PDO;

class Store
{
    private $db;

    public function __construct(string $path)
    {
        // Never create a replacement database when a deployment path is wrong.
        if (!is_file($path)) throw new \RuntimeException('Gateway database is not provisioned');
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }

    public function transaction(callable $fn)
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $value = $fn();
            $this->db->exec('COMMIT');
            return $value;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function query(string $sql, array $args = []): array
    {
        $statement = $this->db->prepare($sql);
        foreach ($args as $i => $value) $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->execute();
        return $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function get(string $table, string $id): ?array
    {
        if (!in_array($table, ['devices', 'sessions', 'panel_overrides'], true)) throw new \InvalidArgumentException('Invalid table');
        $rows = $this->query("SELECT data FROM $table WHERE id=?", [$id]);
        return $rows ? json_decode($rows[0]['data'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function put(string $table, string $id, array $data): void
    {
        $raw = Crypto::json($data);
        if ($table === 'sessions') {
            $this->query('INSERT INTO sessions(id,device_id,expires_at,data) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET device_id=excluded.device_id,expires_at=excluded.expires_at,data=excluded.data', [$id, $data['deviceId'], $data['expiresAt'], $raw]);
        } elseif (in_array($table, ['devices', 'panel_overrides'], true)) {
            $this->query("INSERT INTO $table(id,data) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data", [$id, $raw]);
        } else throw new \InvalidArgumentException('Invalid table');
    }

    public function audit(string $event, array $payload): void
    {
        $this->query('INSERT INTO audit_log(event,payload,created_at) VALUES(?,?,?)', [$event, Crypto::json($payload), Crypto::now()]);
    }

    public function deleteSession(string $id): void
    {
        $this->query('DELETE FROM sessions WHERE id=?', [$id]);
    }

    public function pruneSessions(string $now): void
    {
        $this->query('DELETE FROM sessions WHERE expires_at<=?', [$now]);
    }

    public function devices(int $offset): array
    {
        $rows = $this->query('SELECT data FROM devices ORDER BY rowid DESC LIMIT 30 OFFSET ?', [$offset]);
        $keys = array_flip(['deviceId', 'clientName', 'userEmail', 'version', 'status', 'firstSeenAt', 'lastSeenAt']);
        return ['total' => (int) $this->query('SELECT COUNT(*) AS n FROM devices')[0]['n'],
            'items' => array_map(fn($row) => array_intersect_key(json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR), $keys), $rows)];
    }
}
