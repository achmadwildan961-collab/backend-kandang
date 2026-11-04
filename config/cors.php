<?php

return [
    'paths' => ['api/*'], 'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],
    'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => false,
];
