<?php

namespace App\Services;

class TemperatureComparison
{
    public function compare(array $indoor, array $outdoor): array
    {
        // Conservative software policy until cross-node timing tolerance is signed off.
        $reason = ! $indoor['complete'] ? 'indoor_batch_unavailable'
            : (! $outdoor['valid'] ? 'outdoor_unavailable'
                : ($indoor['recorded_at'] !== $outdoor['recorded_at'] ? 'timestamps_not_paired' : null));

        return ['delta_temp' => $reason === null ? round($indoor['average'] - $outdoor['value'], 4) : null,
            'unit' => 'celsius', 'available' => $reason === null, 'unavailable_reason' => $reason,
            'pairing_policy' => 'exact_timestamp', 'indoor_recorded_at' => $indoor['recorded_at'],
            'outdoor_recorded_at' => $outdoor['recorded_at']];
    }
}
