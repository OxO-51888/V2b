<?php

return [
    'enabled' => (bool) env('PANEL_MODULES_ENABLED', false),
    'site' => env('PANEL_MODULES_SITE', ''),
    'gateway' => [
        'storage' => env('PANEL_GATEWAY_STORAGE', 'database'),
        'directory' => storage_path('app/panel-modules/gateway'),
        'panel_id' => env('PANEL_GATEWAY_PANEL_ID', ''),
        'public_url' => env('PANEL_GATEWAY_PUBLIC_URL', ''),
        'entry_enabled' => (bool) env('PANEL_GATEWAY_ENTRY_ENABLED', false),
        'entry_policy' => '/etc/panel-gateway-entry.json',
        'session_routes' => storage_path('app/panel-modules/gateway/session-routes.json'),
        'client_key_hash' => env('PANEL_GATEWAY_CLIENT_KEY_HASH', ''),
    ],
    'telegram' => [
        'driver' => env('PANEL_TELEGRAM_DRIVER', 'remote'),
        'directory' => storage_path('app/panel-modules/telegram'),
        'username' => env('PANEL_TELEGRAM_USERNAME', 'dadaninibot'),
        'url' => env('PANEL_TELEGRAM_MANAGEMENT_URL', ''),
        'token' => env('PANEL_TELEGRAM_MANAGEMENT_TOKEN', ''),
        'chat_id' => env('PANEL_TELEGRAM_CHAT_ID', ''),
    ],
];
