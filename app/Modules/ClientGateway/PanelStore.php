<?php

namespace App\Modules\ClientGateway;

use Illuminate\Database\Connection;

class PanelStore
{
    private $db;
    private $panelId;
    public const TABLE = 'v2_client_gateway';

    public function __construct(Connection $connection, string $panelId)
    {
        if (!preg_match('/\Apanel_[0-9]{2}\z/', $panelId)) throw new \InvalidArgumentException('Invalid gateway site');
        $this->db = $connection;
        $this->panelId = $panelId;
    }

    private function records(string $type)
    {
        if (!in_array($type, ['devices', 'sessions', 'panel_overrides', 'audit_log'], true)) {
            throw new \InvalidArgumentException('Invalid gateway record type');
        }
        return $this->db->table(self::TABLE)->where('panel_id', $this->panelId)->where('record_type', $type);
    }

    public function transaction(callable $fn)
    {
        return $this->db->transaction($fn, 3);
    }

    public function get(string $type, string $id): ?array
    {
        $query = $this->records($type)->where('record_key', $id);
        if ($this->db->transactionLevel() > 0) $query->lockForUpdate();
        $raw = $query->value('data');
        return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $type, string $id, array $data): void
    {
        $this->records($type);
        if ($id === '' || strlen($id) > 180 || ($type === 'panel_overrides' && $id !== $this->panelId)
            || (isset($data['panelId']) && $data['panelId'] !== $this->panelId)
            || ($type === 'sessions' && ($data['panelId'] ?? '') !== $this->panelId)) {
            throw new \InvalidArgumentException('Gateway record belongs to another site');
        }
        $this->db->table(self::TABLE)->upsert([[
            'panel_id' => $this->panelId, 'record_type' => $type, 'record_key' => $id,
            'data' => Crypto::json($data), 'expires_at' => $type === 'sessions' ? $data['expiresAt'] : null,
            'updated_at' => time(),
        ]], ['panel_id', 'record_type', 'record_key'], ['data', 'expires_at', 'updated_at']);
    }

    public function deleteSession(string $id): void
    {
        $this->records('sessions')->where('record_key', $id)->delete();
    }

    public function pruneSessions(string $now): void
    {
        $this->records('sessions')->where('expires_at', '<=', $now)->delete();
    }

    public function audit(string $event, array $payload): void
    {
        $this->put('audit_log', bin2hex(random_bytes(16)), ['event' => $event, 'payload' => $payload, 'created_at' => Crypto::now()]);
    }

    public function devices(int $offset): array
    {
        $query = $this->records('devices');
        $total = (clone $query)->count();
        $keys = array_flip(['deviceId', 'clientName', 'userEmail', 'version', 'status', 'firstSeenAt', 'lastSeenAt']);
        $items = $query->orderByDesc('id')->offset($offset)->limit(30)->pluck('data')->map(function ($raw) use ($keys) {
            return array_intersect_key(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $keys);
        })->all();
        return ['total' => $total, 'items' => $items];
    }
}
