<?php

namespace App\Services;

// Preserve the existing public API while the two domains own separate implementations.
class AiRiskService
{
    use \App\Modules\Security\SubscriptionAiActions;
    use \App\Modules\Support\TicketAiActions;
    use \App\Modules\System\AiModelSupport;
}
