<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\System\ScheduledTaskLock::class, __NAMESPACE__ . '\\ScheduledTaskLock');
