<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\System\MailService::class, __NAMESPACE__ . '\\MailService');
