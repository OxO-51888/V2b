<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\Core\UserService::class, __NAMESPACE__ . '\\UserService');
