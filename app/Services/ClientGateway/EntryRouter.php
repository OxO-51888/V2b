<?php

namespace App\Services\ClientGateway;

// Stable entry point; implementation belongs to the panel module.
class_alias(\App\Modules\ClientGateway\EntryRouter::class, __NAMESPACE__ . '\\EntryRouter');
