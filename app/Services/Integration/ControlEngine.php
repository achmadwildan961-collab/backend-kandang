<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Services\ControlDemandPreview;
use App\Services\LightingDemandPreview;
use App\Services\LuxLightingSettings;
use App\Services\SensorAggregationService;

class ControlEngine
{
    public function preview(Coop $coop, bool $gasPolicyVerified = false): array
    {
        $snapshot = app(SensorAggregationService::class)->latest($coop);
        $settings = $coop->settings()->firstOrFail();
        $indoorIds = $coop->devices()->where('type', 'esp32')->get()->filter(fn ($d) => $d->type === 'esp32')->modelKeys();
        $indoor = $snapshot['indoor_temperature'];
        $sourceValid = $indoor['complete'] && collect($indoor['sensors'])->every(fn ($s) => in_array($s['device_id'], $indoorIds, true));
        $gas = $snapshot['gas'];
        $gasValid = $gas['valid'] && $gas['unit'] === 'adc' && in_array($gas['device_id'], $indoorIds, true)
            && is_finite((float) $settings->gas_threshold) && $settings->gas_threshold >= 0 && $settings->gas_threshold <= 4095;
        $gasDemand = $gasValid && $gasPolicyVerified ? $gas['value'] > $settings->gas_threshold : null;
        $thermal = app(ControlDemandPreview::class)->evaluate($sourceValid ? $indoor['average'] : null,
            $settings->minimum_indoor_temperature, $settings->maximum_indoor_temperature, $gasDemand);
        // A verified gas demand still needs ventilation with missing temperature;
        // unknown heater/interlock remains unknown, never converted into a relay command.
        $fan = $gasDemand === true ? true : $thermal['ventilation_demand'];
        $lux = app(LuxLightingSettings::class)->snapshot($coop);
        $reports = app(IntegrationMonitor::class)->reports($coop);
        $previous = count($reports) === 1 && $reports[0]['status'] === 'fresh' ? $reports[0]['states']['lighting_lamp'] : null;
        $light = $snapshot['outdoor_light_lux'];
        $lighting = app(LightingDemandPreview::class)->evaluate($light['value'], $lux['min_lux'], $lux['max_lux'],
            $previous, $lux['lighting_mode'] ?? 'unconfigured', $light['valid']);

        return ['automation_enabled' => false, 'actuator_commands' => null, 'thermal' => $thermal,
            'gas_policy_verified' => $gasPolicyVerified, 'gas_comparison_preview' => $gasValid ? $gas['value'] > $settings->gas_threshold : null,
            'demand' => ['heating_lamp' => $thermal['temperature_heating_demand'], 'ventilation_fan_1' => $fan,
                'ventilation_fan_2' => $fan, 'lighting_lamp' => $lighting['lighting_demand']],
            'lighting' => $lighting, 'interlock_status' => 'pending_verification'];
    }
}
