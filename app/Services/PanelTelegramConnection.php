<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\Telegram\PanelTelegramConnection::class, __NAMESPACE__ . '\\PanelTelegramConnection');
