<?php

namespace App\Modules\Core;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class TrafficBuffer
{
    public const UPLOAD = 'v2board_upload_traffic';
    public const DOWNLOAD = 'v2board_download_traffic';
    public const PENDING = 'v2board_traffic_pending';
    public const CURSORS = 'v2board_traffic_cursors';
    public const BATCH_SIZE = 250;

    public function hasBufferedTraffic(): bool
    {
        return Redis::hlen(self::UPLOAD) > 0 || Redis::hlen(self::DOWNLOAD) > 0;
    }

    public function claim(): ?array
    {
        // Only one bounded batch is in flight. A failed DB write leaves it available for replay.
        $script = <<<'LUA'
local pending = redis.call('GET', KEYS[3])
if pending then return pending end
local selected, ids = {}, {}
for index = 1, 2 do
    local cursor = redis.call('HGET', KEYS[4], tostring(index)) or '0'
    local page = redis.call('HSCAN', KEYS[index], cursor, 'COUNT', ARGV[2])
    redis.call('HSET', KEYS[4], tostring(index), page[1])
    for offset = 1, #page[2], 2 do
        local id = page[2][offset]
        if not selected[id] and #ids < tonumber(ARGV[2]) then
            selected[id] = true
            ids[#ids + 1] = id
        end
    end
end
if #ids == 0 then return false end
local rows = {}
for _, id in ipairs(ids) do
    rows[#rows + 1] = {id, redis.call('HGET', KEYS[1], id) or '0', redis.call('HGET', KEYS[2], id) or '0'}
end
local payload = cjson.encode({id = ARGV[1], rows = rows})
redis.call('SET', KEYS[3], payload)
for _, id in ipairs(ids) do
    redis.call('HDEL', KEYS[1], id)
    redis.call('HDEL', KEYS[2], id)
end
return payload
LUA;
        $raw = Redis::eval($script, 4, self::UPLOAD, self::DOWNLOAD, self::PENDING, self::CURSORS,
            bin2hex(random_bytes(16)), self::BATCH_SIZE);
        if ($raw === null || $raw === false) return null;
        $batch = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($batch) || !preg_match('/^[a-f0-9]{32}$/D', $batch['id'] ?? '')
            || !is_array($batch['rows'] ?? null) || count($batch['rows']) > self::BATCH_SIZE) {
            throw new RuntimeException('Invalid pending traffic batch');
        }
        foreach ($batch['rows'] as $row) {
            if (!is_array($row) || count($row) !== 3) throw new RuntimeException('Invalid traffic row');
            foreach ($row as $value) {
                if (!is_string($value) || !ctype_digit($value) || strlen($value) > 19
                    || (strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                    throw new RuntimeException('Invalid traffic counter');
                }
            }
            if ((int) $row[0] < 1) throw new RuntimeException('Invalid traffic user');
        }
        return $batch;
    }

    public function acknowledge(string $id): void
    {
        $script = <<<'LUA'
local pending = redis.call('GET', KEYS[1])
if not pending then return 1 end
if cjson.decode(pending).id ~= ARGV[1] then return 0 end
redis.call('DEL', KEYS[1])
return 1
LUA;
        if (!(int) Redis::eval($script, 1, self::PENDING, $id)) {
            throw new RuntimeException('Pending traffic batch changed before acknowledgement');
        }
    }
}
