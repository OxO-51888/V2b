<?php

return [
    // Stable origin hosts used by domestic entry proxies, not their changing public addresses.
    'domestic_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_DOMESTIC_HOSTS', ''))
    ))),
];
