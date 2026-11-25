<?php

namespace App\Services;

use App\Models\Coop;

class MonitoringContract
{
    public function snapshot(Coop $coop, array $snapshot, int $version): array
    {
        $snapshot['schema_version'] = $version;
        if ($version === 1) {
            return $snapshot;
        }
        foreach (['indoor_humidity', 'outdoor_humidity', 'outdoor_temperature'] as $id) {
            $snapshot['legacy_'.$id] = $snapshot[$id] + ['legacy' => true];
            $snapshot[$id] = $snapshot[$id === 'indoor_humidity' ? 'indoor_humidity_aggregate' : $id.'_verified'];
        }
        $snapshot['outdoor_temperature']['role'] = 'reference_only';
        $indoorIds = $coop->devices()->get()->filter(fn ($d) => $d->type === 'esp32')->modelKeys();
        $indoor = &$snapshot['indoor_temperature'];
        $indoor['source_verified'] = $indoor['complete'] && collect($indoor['sensors'])->every(fn ($s) => in_array($s['device_id'], $indoorIds, true));
        if ($indoor['complete'] && ! $indoor['source_verified']) {
            $indoor['average'] = null;
            $indoor['complete'] = false;
            $indoor['status'] = $indoor['high_condition'] = $indoor['low_condition'] = 'unknown';
            $indoor['unavailable_reason'] = 'unverified_source';
        }
        $gas = &$snapshot['gas'];
        $gas['source_verified'] = in_array($gas['device_id'], $indoorIds, true);
        if (! $gas['source_verified']) {
            $gas['valid'] = false;
            $gas['status'] = 'unverified_source';
            $gas['condition'] = 'unknown';
        }
        foreach (['gas', 'outdoor_light_lux'] as $id) {
            $snapshot[$id]['diagnostic_value'] = $snapshot[$id]['value'];
            $snapshot[$id]['value'] = $snapshot[$id]['valid'] ? $snapshot[$id]['value'] : null;
            $snapshot[$id]['unavailable_reason'] = $snapshot[$id]['valid'] ? null : $snapshot[$id]['status'];
        }
        $snapshot['temperature_comparison'] = app(TemperatureComparison::class)->compare($indoor, $snapshot['outdoor_temperature']);

        return $snapshot;
    }
}
