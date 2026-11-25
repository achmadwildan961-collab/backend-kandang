<?php

return [
    // Separate from operational MQTT. Never bound to the production dispatcher.
    'staging' => [
        'enabled' => (bool) env('MQTT_STAGING_ENABLED', false),
        'virtual_only' => (bool) env('MQTT_STAGING_VIRTUAL_ONLY', false),
        'host' => env('MQTT_STAGING_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_STAGING_PORT', 18884),
        'username' => env('MQTT_STAGING_USERNAME'),
        'password' => env('MQTT_STAGING_PASSWORD'),
        'tls' => (bool) env('MQTT_STAGING_TLS', false),
        'ca_file' => env('MQTT_STAGING_CA_FILE'),
        'retained_authorized' => (bool) env('MQTT_STAGING_RETAINED_AUTHORIZED', false),
        'scopes' => json_decode(env('MQTT_STAGING_VIRTUAL_SCOPES', '{}'), true),
        'attempts' => 2,
        'timeout_seconds' => 5,
    ],
];
