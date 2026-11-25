<?php

namespace App\Services;

/**
 * Pure planning logic only. Not registered with routes, queues, MQTT or actuators.
 * Caller must supply a valid, fresh, complete indoor batch (otherwise null),
 * and an independently verified same-unit gas safety decision (otherwise null).
 */
class ControlDemandPreview
{
    public function evaluate(?float $indoorAverage, ?float $minimumIndoor, ?float $maximumIndoor, ?bool $gasSafetyDemand): array
    {
        $base = ['automation_enabled' => false, 'actuator_commands' => null,
            'lighting_lamp' => null, 'gas_safety_demand' => $gasSafetyDemand,
            'interlock_status' => 'pending_verification'];
        foreach ([$indoorAverage, $minimumIndoor, $maximumIndoor] as $input) {
            if ($input === null || ! is_finite($input)) {
                return $base + $this->unavailable('temperature_or_threshold_unavailable');
            }
        }
        if ($minimumIndoor >= $maximumIndoor) {
            return $base + $this->unavailable('invalid_threshold_order');
        }
        if ($gasSafetyDemand === null) {
            return $base + $this->unavailable('gas_safety_unverified');
        }
        $temperatureFan = $indoorAverage > $maximumIndoor;

        return $base + ['available' => true, 'unavailable_reason' => null,
            'temperature_heating_demand' => $indoorAverage < $minimumIndoor,
            'temperature_ventilation_demand' => $temperatureFan,
            // This common ventilation demand targets both fans only after interlock acceptance.
            'ventilation_demand' => $temperatureFan || $gasSafetyDemand];
    }

    private function unavailable(string $reason): array
    {
        // Unknown never invents an OFF command or a sensor-fault fallback policy.
        return ['available' => false, 'unavailable_reason' => $reason,
            'temperature_heating_demand' => null, 'temperature_ventilation_demand' => null, 'ventilation_demand' => null];
    }
}
