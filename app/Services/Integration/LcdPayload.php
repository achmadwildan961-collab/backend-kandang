<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Services\SensorAggregationService;

class LcdPayload
{
    public function generate(Coop $coop): array
    {
        $s = app(SensorAggregationService::class)->latest($coop);
        $fields = [];
        foreach (['outdoor_temperature', 'gas', 'outdoor_light_lux'] as $key) {
            $reading = $s[$key];
            $fields[$key] = ['value' => $reading['valid'] ? $reading['value'] : null,
                'unit' => $reading['unit'], 'status' => $reading['status'], 'recorded_at' => $reading['recorded_at']];
        }
        $fields['indoor_temperature'] = ['value' => $s['indoor_temperature']['complete'] ? $s['indoor_temperature']['average'] : null,
            'unit' => 'celsius', 'status' => $s['indoor_temperature']['complete'] ? 'fresh' : 'unavailable',
            'recorded_at' => $s['indoor_temperature']['recorded_at']];

        return ['schema_version' => 1, 'contract' => 'phase8_draft', 'coop_id' => $coop->id,
            'generated_at' => now()->toISOString(), 'expires_at' => now()->addSeconds(max(1, config('integration.display_seconds')))->toISOString(),
            'target_device_ids' => $coop->devices()->get()->filter(fn ($d) => $d->type === 'nodemcu_esp8266')->values()->modelKeys(),
            'fields' => $fields, 'actuators' => app(IntegrationMonitor::class)->reports($coop),
            'display_geometry' => null, 'publication_enabled' => false];
    }
}
