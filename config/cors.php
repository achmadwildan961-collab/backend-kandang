<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'OPTIONS'],
    // Explicit per-environment allowlist; production has no development fallback.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', '')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],
    'exposed_headers' => ['Content-Disposition', 'X-Export-Rows', 'X-Aggregation-Seconds'],
    'max_age' => 600,
    // Sanctum bearer tokens are sent in Authorization, not cross-origin cookies.
    'supports_credentials' => false,
];
