<?php

namespace App\Services;

use App\Models\Device;

class LightingContract
{
    public static function verified(): bool
    {
        $min = filter_var(config('lighting.adc_min'), FILTER_VALIDATE_INT);
        $max = filter_var(config('lighting.adc_max'), FILTER_VALIDATE_INT);
        $version = config('lighting.measurement_version');

        return config('lighting.telemetry_verified') === true
            && $min !== false && $max !== false && $min >= 0 && $min < $max && $max <= 99999999
            && in_array(config('lighting.polarity'), ['increasing', 'decreasing'], true)
            && is_string($version) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $version)
            && is_array(config('lighting.device_identifiers')) && config('lighting.device_identifiers') !== [];
    }

    public static function accepts(Device $device): bool
    {
        return self::verified() && in_array($device->device_identifier, config('lighting.device_identifiers'), true);
    }

    public static function storesConfiguration(): bool
    {
        // No implicit inversion or fabricated lux conversion. Inverted ADC needs a
        // separately approved brightness mapping before thresholds can be stored.
        return self::verified() && config('lighting.polarity') === 'increasing'
            && config('lighting.configuration_storage') === true;
    }
}
