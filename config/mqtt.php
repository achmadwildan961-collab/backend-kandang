<?php

return [
    'enabled' => (bool) env('MQTT_ENABLED', false),
    'host' => env('MQTT_HOST', '127.0.0.1'),
    'port' => (int) env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'client_id' => env('MQTT_CLIENT_ID', 'smart-kandang-laravel'),
    'topic_prefix' => env('MQTT_TOPIC_PREFIX', 'smart-kandang'),
    // Optional explicit identifier -> sensor allowlists. Empty preserves legacy devices.
    'device_sensors' => json_decode(env('MQTT_DEVICE_SENSORS', '{}'), true),
    'tls' => (bool) env('MQTT_TLS', false),
    'tls_ca_file' => env('MQTT_TLS_CA_FILE'),
    'max_payload_bytes' => 16384,
    'future_tolerance_seconds' => (int) env('MQTT_FUTURE_TOLERANCE_SECONDS', 30),
    'offline_after_seconds' => (int) env('MQTT_OFFLINE_AFTER_SECONDS', 120),
    'retry_min_seconds' => max(1, (int) env('MQTT_RETRY_MIN_SECONDS', 2)),
    'retry_max_seconds' => max(1, (int) env('MQTT_RETRY_MAX_SECONDS', 30)),
];
