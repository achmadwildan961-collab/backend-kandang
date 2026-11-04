<?php

return [
    'stale_after_seconds' => (int) env('SENSOR_STALE_AFTER_SECONDS', 300),
    'notification_preferences' => ['push_notifications' => true, 'email_notifications' => false, 'critical_alerts' => true, 'temperature_alerts' => true, 'gas_alerts' => true, 'maintenance_alerts' => false],
    'seed' => [
        'admin_email' => env('DEV_ADMIN_EMAIL'), 'admin_password' => env('DEV_ADMIN_PASSWORD'),
        'user_email' => env('DEV_USER_EMAIL'), 'user_password' => env('DEV_USER_PASSWORD'),
    ],
];
