<?php

namespace App\Services;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\Security\EmailVerificationService::class, __NAMESPACE__ . '\\EmailVerificationService');
