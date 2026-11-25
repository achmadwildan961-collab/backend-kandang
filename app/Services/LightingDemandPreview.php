<?php

namespace App\Services;

class LightingDemandPreview
{
    // Isolated preview only. Caller provides freshness and reported prior state;
    // this function neither reads nor writes hardware or reported-state storage.
    public function evaluate(?float $lux, ?float $minimum, ?float $maximum, ?bool $previousState, string $mode, bool $fresh, string $unit = 'lux'): array
    {
        $base = ['automation_enabled' => false, 'actuator_commands' => null, 'actuator_identifier' => 'lighting_lamp'];
        if ($mode !== 'auto') {
            return $base + $this->unavailable('manual_or_unknown_mode_not_approved');
        }
        if (! $fresh || $unit !== 'lux') {
            return $base + $this->unavailable('measurement_unavailable');
        }
        foreach ([$lux, $minimum, $maximum] as $value) {
            if ($value === null || ! is_finite($value) || $value < 0 || $value > LightingContract::BH1750_MAX_LUX) {
                return $base + $this->unavailable('measurement_or_policy_invalid');
            }
        }
        if ($minimum >= $maximum) {
            return $base + $this->unavailable('invalid_threshold_order');
        }
        $demand = $lux < $minimum ? true : ($lux > $maximum ? false : $previousState);
        if ($demand === null) {
            return $base + $this->unavailable('previous_state_unknown');
        }

        return $base + ['available' => true, 'lighting_demand' => $demand, 'unavailable_reason' => null];
    }

    private function unavailable(string $reason): array
    {
        return ['available' => false, 'lighting_demand' => null, 'unavailable_reason' => $reason];
    }
}
