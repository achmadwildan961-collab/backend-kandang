<?php

namespace App\Services;

use App\Models\Coop;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;

class LightingContract
{
    public const BH1750_MEASUREMENT_VERSION = 'bh1750_lux_v1';

    // ROHM BH1750FVI Rev.D normal advertised range; zero represents darkness.
    // Extended sensitivity/MTreg modes need a separately versioned contract.
    public const BH1750_MAX_LUX = 65535;

    public static function bh1750Verified(): bool
    {
        return config('lighting.bh1750.telemetry_verified') === true
            && is_array(config('lighting.bh1750.device_identifiers'))
            && config('lighting.bh1750.device_identifiers') !== [];
    }

    public static function acceptsBh1750(Device $device): bool
    {
        $mapping = config('mqtt.device_sensors');
        $allowed = is_array($mapping) && ($mapping === []
            || (is_array($mapping[$device->device_identifier] ?? null) && in_array('outdoor_light_lux', $mapping[$device->device_identifier], true)));

        return self::bh1750Verified() && $device->type === 'nodemcu_esp8266'
            && in_array($device->device_identifier, config('lighting.bh1750.device_identifiers'), true) && $allowed;
    }

    public static function bh1750Devices(Coop $coop): Builder
    {
        // Apply the same case-sensitive identity/type policy on SQLite and MySQL,
        // whose default string collation is otherwise case-insensitive.
        $ids = self::bh1750Verified() ? $coop->devices()->get()->filter(fn ($device) => self::acceptsBh1750($device))->modelKeys() : [];

        return Device::query()->where('coop_id', $coop->id)->whereIn('id', $ids);
    }

    // LEGACY/DEPRECATED ADC APIs below deliberately retain their old semantics.
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
