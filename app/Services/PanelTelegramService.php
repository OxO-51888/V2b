<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\Telegram\PanelTelegramService::class, __NAMESPACE__ . '\\PanelTelegramService');
