<?php

namespace App\Services;

use App\Models\Coop;

class HumidityAggregation
{
    public function channel(Coop $coop, string $id): array
    {
        $spec = SensorCatalog::series()[$id];
        // Inspect the newest attempt, not just the last good reading. A changed
        // source, invalid unit or future sample must not look like healthy data.
        $reading = $coop->readings()->where('sensor_identifier', $id)->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $source = $reading && in_array($reading->device_id, HumidityContract::deviceIds($coop, $id), true)
            && $reading->measurement_version === HumidityContract::version($id) && $reading->batch_key !== null;
        $valid = $reading && $reading->sensor_type === $spec['sensor_type'] && $reading->unit === $spec['unit']
            && is_finite($reading->value) && $reading->value >= $spec['min'] && $reading->value <= $spec['max']
            && $reading->recorded_at->lte(now());
        $status = ! $reading ? 'missing' : (! $valid ? 'invalid' : (! $source ? 'unverified_source'
            : ($reading->recorded_at->lt(now()->subSeconds(max(1, config('monitoring.stale_after_seconds')))) ? 'stale' : 'fresh')));

        return ['sensor_identifier' => $id, 'sensor_type' => $spec['sensor_type'],
            'sensor_model' => $source ? (HumidityContract::version($id) === HumidityContract::DHT22_VERSION ? 'DHT22' : 'AHT20') : null,
            'paired_temperature_identifier' => str_ends_with($id, '_humidity') && str_starts_with($id, 'indoor_dht22_') ? substr($id, 0, -9) : null,
            'value' => $status === 'fresh' ? $reading->value : null, 'diagnostic_value' => $valid ? $reading->value : null,
            'unit' => $spec['unit'], 'unit_symbol' => $spec['unit'] === 'percent' ? '%' : '°C',
            'recorded_at' => $reading?->recorded_at->toISOString(), 'device_id' => $reading?->device_id,
            'batch_key' => $reading?->batch_key, 'measurement_version' => $reading?->measurement_version,
            'status' => $status, 'valid' => $status === 'fresh', 'source_verified' => (bool) $source,
            'provenance' => $source ? 'registered_sensor_mapping' : 'unverified', 'physical_verified' => false,
            'unavailable_reason' => $status === 'fresh' ? null : $status];
    }

    public function indoor(Coop $coop): array
    {
        $sensors = array_map(fn ($id) => $this->channel($coop, $id), HumidityContract::indoorIdentifiers());
        $fresh = array_values(array_filter($sensors, fn ($s) => $s['valid']));
        $count = count($fresh);
        $consistent = $count > 0 && count(array_unique(array_column($fresh, 'recorded_at'))) === 1
            && count(array_unique(array_column($fresh, 'device_id'))) === 1
            && count(array_unique(array_column($fresh, 'batch_key'))) === 1;
        // Even the diagnostic average may not silently mix unrelated batches.
        $partial = $consistent ? round(array_sum(array_column($fresh, 'value')) / $count, 4) : null;
        $complete = $consistent && $count === 5;
        $reason = $complete ? null : ($count > 1 && ! $consistent ? 'inconsistent_batch' : 'missing_stale_invalid_or_unverified');
        $idsWithStatus = fn ($status) => array_values(array_column(array_filter($sensors, fn ($s) => $s['status'] === $status), 'sensor_identifier'));

        return ['average' => $complete ? $partial : null, 'partial_average' => $partial, 'unit' => 'percent', 'unit_symbol' => '%',
            'aggregation' => 'five_dht22', 'five_sensor_verified' => $complete, 'expected_count' => 5, 'valid_count' => $count,
            'complete' => $complete, 'batch_consistent' => $consistent, 'batch_provenance' => $complete ? 'mqtt_v1_message' : null,
            'batch_key' => $complete ? $fresh[0]['batch_key'] : null, 'device_id' => $complete ? $fresh[0]['device_id'] : null,
            'recorded_at' => $complete ? $fresh[0]['recorded_at'] : null, 'status' => $complete ? 'fresh' : 'unknown',
            'unavailable_reason' => $reason, 'physical_verified' => false,
            'missing_sensors' => $idsWithStatus('missing'), 'stale_sensors' => $idsWithStatus('stale'),
            'invalid_sensors' => $idsWithStatus('invalid'), 'unverified_sensors' => $idsWithStatus('unverified_source'), 'sensors' => $sensors];
    }
}
