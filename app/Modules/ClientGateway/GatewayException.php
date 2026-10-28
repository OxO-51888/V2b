<?php

namespace App\Modules\ClientGateway;

// The public exception identity must exist before a legacy catch clause is evaluated.
class_alias(\App\Services\ClientGateway\GatewayException::class, __NAMESPACE__ . '\\GatewayException');
