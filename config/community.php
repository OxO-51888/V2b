<?php

return [
    'enabled' => env('COMMUNITY_ENABLED', false),
    'site' => env('COMMUNITY_SITE', ''),
    'url' => env('COMMUNITY_URL', ''),
    'secret' => env('COMMUNITY_SECRET', ''),
    'identity_secret' => env('COMMUNITY_IDENTITY_SECRET', ''),
    'allowed_users' => env('COMMUNITY_ALLOWED_USERS', ''),
];
