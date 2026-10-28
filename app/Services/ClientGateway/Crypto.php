<?php

namespace App\Services\ClientGateway;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\ClientGateway\Crypto::class, __NAMESPACE__ . '\\Crypto');
