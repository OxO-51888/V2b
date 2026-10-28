<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\Core\PaymentService::class, __NAMESPACE__ . '\\PaymentService');
