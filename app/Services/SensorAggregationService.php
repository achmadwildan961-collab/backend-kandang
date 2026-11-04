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
            $query = $coop->readings()->where('sensor_identifier', $spec['sensor_identifier'])->where('sensor_type', $spec['sensor_type']);
            $reading = (clone $query)->where('recorded_at', '<=', $now->format('Y-m-d H:i:s.u'))->whereBetween('value', [$spec['min'], $spec['max']])->where('unit', $spec['unit'])
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $status = $reading ? ($reading->recorded_at->lt($cutoff) ? 'stale' : 'fresh') : ((clone $query)->exists() ? 'invalid' : 'missing');
            $series[$key] = ['sensor_identifier' => $spec['sensor_identifier'], 'sensor_type' => $spec['sensor_type'],
                'value' => $reading?->value, 'unit' => $spec['unit'], 'recorded_at' => $reading?->recorded_at->toISOString(),
                'device_id' => $reading?->device_id, 'status' => $status, 'valid' => $status === 'fresh'];
        }
        $indoor = array_intersect_key($series, array_flip(SensorCatalog::indoorIdentifiers()));
        $fresh = array_filter($indoor, fn ($s) => $s['valid']);
        $count = count($fresh);
        $average = $count ? round(array_sum(array_column($fresh, 'value')) / $count, 4) : null;
        $settings = $coop->settings()->firstOrFail();
        $status = fn ($series, $warning) => ! $series['valid'] ? 'unknown' : ($warning ? 'warning' : 'normal');

        return [
            'coop_id' => $coop->id, 'as_of' => $now->toISOString(), 'stale_after_seconds' => max(1, config('monitoring.stale_after_seconds')),
            'indoor_temperature' => [
                'average' => $count === 5 ? $average : null, 'partial_average' => $average, 'unit' => 'celsius',
                'complete' => $count === 5, 'expected_count' => 5, 'valid_count' => $count,
                'missing_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'missing')),
                'stale_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'stale')),
                'invalid_sensors' => array_keys(array_filter($indoor, fn ($s) => $s['status'] === 'invalid')),
                'status' => $count !== 5 ? 'unknown' : ($average > $settings->maximum_indoor_temperature ? 'warning' : 'normal'),
                'sensors' => array_values($indoor),
            ],
            'outdoor_temperature' => $series['outdoor_temperature'] + ['condition' => $status($series['outdoor_temperature'], $series['outdoor_temperature']['value'] < $settings->minimum_outdoor_temperature)],
            'indoor_humidity' => $series['indoor_humidity'], 'outdoor_humidity' => $series['outdoor_humidity'],
            'gas' => $series['gas_adc'] + ['condition' => $status($series['gas_adc'], $series['gas_adc']['value'] > $settings->gas_threshold)],
            'thresholds' => ['minimum_outdoor_temperature' => $settings->minimum_outdoor_temperature, 'maximum_indoor_temperature' => $settings->maximum_indoor_temperature, 'gas_threshold' => $settings->gas_threshold],
        ];
    }
}
