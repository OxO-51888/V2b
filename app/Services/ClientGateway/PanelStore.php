<?php

namespace App\Services\ClientGateway;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\ClientGateway\PanelStore::class, __NAMESPACE__ . '\\PanelStore');
