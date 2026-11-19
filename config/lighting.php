<?php

// Deployment opt-in requires the firmware handoff checklist. No actuator transport
// exists in this release; adding a table or a flag cannot enable commands.
return [
    'telemetry_verified' => env('LIGHTING_TELEMETRY_VERIFIED', false),
    'adc_min' => env('LIGHTING_ADC_MIN'),
    'adc_max' => env('LIGHTING_ADC_MAX'),
    'polarity' => env('LIGHTING_ADC_POLARITY'), // increasing | decreasing brightness
    'measurement_version' => env('LIGHTING_MEASUREMENT_VERSION'),
    'device_identifiers' => array_values(array_filter(explode(',', env('LIGHTING_DEVICE_IDENTIFIERS', '')))),
    'configuration_storage' => env('LIGHTING_CONFIGURATION_STORAGE', false),
    'stale_after_seconds' => 300,
];
