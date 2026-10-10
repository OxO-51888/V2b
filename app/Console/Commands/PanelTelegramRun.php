<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PanelTelegramRun extends Command
{
    protected $signature = 'panel:telegram-run';
    protected $description = 'Run the single shared Telegram receiver from this panel';

    public function handle(): int
    {
        if (!config('panel_modules.enabled') || config('panel_modules.telegram.driver') !== 'local') {
            $this->error('Local Telegram receiver is not enabled for this panel.');
            return 1;
        }
        if (!function_exists('pcntl_exec')) {
            $this->error('The CLI pcntl extension is required.');
            return 1;
        }
        $directory = realpath(config('panel_modules.telegram.directory'));
        if (!$directory || !is_file($directory . '/.env') || !is_file($directory . '/bot_admin.sqlite3')) {
            $this->error('Telegram state has not been migrated.');
            return 1;
        }
        putenv('TG_DATA_DIR=' . $directory);
        putenv('TG_PANEL_LOCAL=true');
        putenv('WEB_ADMIN_ENABLE=false');
        putenv('BOT_ADMIN_DB=' . $directory . '/bot_admin.sqlite3');
        putenv('PANEL_EMAIL_BINDING_SITES_FILE=' . $directory . '/panel-sites.json');
        // Exec preserves the service PID and makes systemd signals reach the actual receiver.
        pcntl_exec('/usr/bin/python3', [base_path('modules/telegram/runtime/panel_receiver.py'), 'run']);
        $this->error('Unable to start Telegram receiver.');
        return 1;
    }
}
