<?php

namespace App\Modules\ClientGateway;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnlineDeviceService
{
    public function snapshot(?int $now = null): array
    {
        if (!config('panel_modules.enabled')) return ['count' => 0, 'window_seconds' => null];

        try {
            $panelId = (string) config('panel_modules.gateway.panel_id');
            if (config('panel_modules.gateway.storage') !== 'database' || !preg_match('/\Apanel_[0-9]{2}\z/', $panelId)) {
                throw new \RuntimeException('Native gateway statistics are unavailable');
            }
            $file = config('panel_modules.gateway.directory') . '/settings.json';
            $raw = @file_get_contents($file);
            if ($raw === false) throw new \RuntimeException('Gateway settings are unavailable');
            $settings = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $window = max(60, (int) ($settings['heartbeatAfterSeconds'] ?? 300)) + 120;
            $now = $now ?? time();
            $end = gmdate('Y-m-d\TH:i:s', $now) . '.000Z';
            $start = gmdate('Y-m-d\TH:i:s', $now - $window) . '.000Z';

            // Count a device once, only while its heartbeat and at least one session are current.
            $sessions = DB::table(PanelStore::TABLE)->select('data->deviceId')
                ->where('panel_id', $panelId)->where('record_type', 'sessions')->where('expires_at', '>', $end);
            $count = DB::table(PanelStore::TABLE)->where('panel_id', $panelId)->where('record_type', 'devices')
                ->where('data->status', 'approved')->where('data->lastSeenAt', '>=', $start)
                ->where('data->lastSeenAt', '<=', gmdate('Y-m-d\TH:i:s', $now) . '.999Z')
                ->whereIn('record_key', $sessions)->count();

            return ['count' => $count, 'window_seconds' => $window];
        } catch (\Throwable $e) {
            Log::warning('Client gateway online-device statistics unavailable', ['exception' => get_class($e)]);
            return ['count' => null, 'window_seconds' => null];
        }
    }
}
