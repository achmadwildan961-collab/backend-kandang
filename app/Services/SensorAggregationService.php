<?php

namespace App\Services;

use App\Models\Coop;
use Carbon\CarbonImmutable;

class SensorAggregationService
{
    public function latest(Coop $coop): array
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds(max(1, config('monitoring.stale_after_seconds')));
        $series = [];
        foreach (SensorCatalog::series() as $key => $spec) {
            if ($key === 'outdoor_light_lux' || in_array($key, HumidityContract::indoorIdentifiers(), true)) {
                continue; // BH1750 has mandatory source-binding and measurement-version validation below.
            }
            $query = $coop->readings()->where('sensor_identifier', $spec['sensor_identifier'])->where('sensor_type', $spec['sensor_type']);
            $newest = (clone $query)->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $reading = (clone $query)->where('recorded_at', '<=', $now->format('Y-m-d H:i:s.u'))->whereBetween('value', [$spec['min'], $spec['max']])->where('unit', $spec['unit'])
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $status = $reading ? ($reading->recorded_at->lt($cutoff) ? 'stale' : 'fresh') : ((clone $query)->exists() ? 'invalid' : 'missing');
            // Keep the last valid value for diagnostics, but a newer invalid/future
            // attempt cannot leave an old official aggregate looking healthy.
            if ($newest && $newest->id !== $reading?->id) {
                $status = 'invalid';
            }
            $series[$key] = ['sensor_identifier' => $spec['sensor_identifier'], 'sensor_type' => $spec['sensor_type'],
                'value' => $reading?->value, 'unit' => $spec['unit'], 'recorded_at' => $reading?->recorded_at->toISOString(),
                'device_id' => $reading?->device_id, 'batch_key' => $reading?->batch_key,
                'status' => $status, 'valid' => $status === 'fresh'];
        }
        $indoor = array_intersect_key($series, array_flip(SensorCatalog::indoorIdentifiers()));
        $fresh = array_filter($indoor, fn ($s) => $s['valid']);
        $count = count($fresh);
        $average = $count ? round(array_sum(array_column($fresh, 'value')) / $count, 4) : null;
        $consistent = $count === 5
            && count(array_unique(array_column($fresh, 'recorded_at'))) === 1
            && count(array_unique(array_column($fresh, 'device_id'))) === 1
            && count(array_unique(array_map(fn ($s) => $s['batch_key'] ?? 'legacy', $fresh))) === 1;
        $settings = $coop->settings()->firstOrFail();
        $status = fn ($series, $warning) => ! $series['valid'] ? 'unknown' : ($warning ? 'warning' : 'normal');

        $snapshot = [
            'coop_id' => $coop->id, 'as_of' => $now->toISOString(), 'stale_after_seconds' => max(1, config('monitoring.stale_after_seconds')),
            'indoor_temperature' => [
                'average' => $consistent ? $average : null, 'partial_average' => $average, 'unit' => 'celsius',
                'complete' => $consistent, 'expected_count' => 5, 'valid_count' => $count,
                'batch_consistent' => $consistent,
                'batch_provenance' => ! $consistent ? null : (reset($fresh)['batch_key'] === null ? 'legacy_exact_timestamp' : 'mqtt_v1_message'),
                'recorded_at' => $consistent ? reset($fresh)['recorded_at'] : null,
                'unavailable_reason' => $consistent ? null : ($count === 5 ? 'inconsistent_batch' : 'missing_stale_or_invalid'),
                'missing_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'missing')),
                'stale_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'stale')),
                'invalid_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'invalid')),
                'status' => ! $consistent ? 'unknown' : (($average > $settings->maximum_indoor_temperature || ($settings->minimum_indoor_temperature !== null && $average < $settings->minimum_indoor_temperature)) ? 'warning' : 'normal'),
                'high_condition' => ! $consistent ? 'unknown' : ($average > $settings->maximum_indoor_temperature ? 'warning' : 'normal'),
                'low_condition' => ! $consistent || $settings->minimum_indoor_temperature === null ? 'unknown' : ($average < $settings->minimum_indoor_temperature ? 'warning' : 'normal'),
                'sensors' => array_values($indoor),
            ],
            'outdoor_temperature' => $series['outdoor_temperature'] + ['condition' => $status($series['outdoor_temperature'], false), 'role' => 'reference_only'],
            'indoor_humidity' => $series['indoor_humidity'] + ['aggregation' => 'legacy_single_channel', 'five_sensor_verified' => false], 'outdoor_humidity' => $series['outdoor_humidity'],
            'gas' => $series['gas_adc'] + ['condition' => $status($series['gas_adc'], $series['gas_adc']['value'] > $settings->gas_threshold)],
            'thresholds' => ['minimum_indoor_temperature' => $settings->minimum_indoor_temperature, 'legacy_outdoor_minimum_alert_enabled' => false, 'minimum_outdoor_temperature' => $settings->minimum_outdoor_temperature, 'maximum_indoor_temperature' => $settings->maximum_indoor_temperature, 'gas_threshold' => $settings->gas_threshold],
        ];

        $snapshot['temperature_comparison'] = (new TemperatureComparison)->compare($snapshot['indoor_temperature'], $snapshot['outdoor_temperature']);
        $snapshot['outdoor_light_lux'] = (new Bh1750Telemetry)->latest($coop);
        $snapshot['indoor_humidity']['legacy'] = true;
        $snapshot['indoor_humidity']['unit_symbol'] = '%';
        $snapshot['indoor_humidity_aggregate'] = app(HumidityAggregation::class)->indoor($coop);
        $snapshot['outdoor_humidity_verified'] = app(HumidityAggregation::class)->channel($coop, 'outdoor_humidity');
        $snapshot['outdoor_temperature_verified'] = app(HumidityAggregation::class)->channel($coop, 'outdoor_temperature');
        $snapshot['outdoor_humidity']['source_verified'] = $snapshot['outdoor_humidity_verified']['source_verified'];
        $snapshot['outdoor_humidity']['sensor_model'] = $snapshot['outdoor_humidity_verified']['sensor_model'];

        return $snapshot;
    }
}
