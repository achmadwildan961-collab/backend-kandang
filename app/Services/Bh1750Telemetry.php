<?php

namespace App\Services;

use App\Models\Coop;

class Bh1750Telemetry
{
    public function latest(Coop $coop): array
    {
        $devices = LightingContract::bh1750Devices($coop);
        $supported = (clone $devices)->exists();
        $reading = $supported ? $coop->readings()->where('sensor_identifier', 'outdoor_light_lux')
            ->where('sensor_type', 'outdoor_light')->whereIn('device_id', $devices->select('id'))
            ->orderByDesc('recorded_at')->orderByDesc('id')->first() : null;
        $valid = $reading && is_finite($reading->value) && $reading->value >= 0
            && $reading->value <= LightingContract::BH1750_MAX_LUX && $reading->unit === 'lux'
            && $reading->measurement_version === LightingContract::BH1750_MEASUREMENT_VERSION
            && $reading->recorded_at->lte(now());
        $status = ! $supported ? 'unsupported' : (! $reading ? 'missing' : (! $valid ? 'invalid'
            : ($reading->recorded_at->lt(now()->subSeconds(max(1, config('lighting.stale_after_seconds')))) ? 'stale' : 'fresh')));

        return ['sensor_identifier' => 'outdoor_light_lux', 'sensor_type' => 'outdoor_light', 'sensor_model' => 'BH1750',
            'interface' => 'i2c', 'unit' => 'lux', 'value' => $valid ? $reading->value : null,
            'status' => $status, 'valid' => $status === 'fresh', 'recorded_at' => $reading?->recorded_at->toISOString(),
            'device_id' => $reading?->device_id, 'measurement_version' => $reading?->measurement_version,
            'batch_key' => $reading?->batch_key, 'field_calibration_verified' => false,
            'calibration_version' => null];
    }
}
