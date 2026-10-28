<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\System\RuntimeMonitorService::class, __NAMESPACE__ . '\\RuntimeMonitorService');
