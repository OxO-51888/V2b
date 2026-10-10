<?php

return [
    'enabled' => (bool) env('TG_BINDING_ENABLED', false),
    'mail_enabled' => (bool) env('TG_BINDING_MAIL_ENABLED', false),
    'secret' => env('TG_BINDING_SECRET', ''),
];
