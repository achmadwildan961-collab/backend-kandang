<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class CoopSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $targets = DB::table('operational_configuration_outbox')->where('coop_id', $this->coop_id)->where('config_version', $this->config_version)->count();

        return ['coop_id' => $this->coop_id, 'contract_version' => 2,
            'config_version' => $this->config_version,
            'requires_expected_config_version' => true,
            'configuration_status' => ['persistence' => 'stored', 'delivery' => 'disabled', 'outbox' => $targets ? 'blocked' : 'not_queued', 'target_count' => $targets, 'applied_config_version' => null, 'device_applied' => false],
            'minimum_indoor_temperature' => $this->minimum_indoor_temperature,
            'indoor_minimum_configured' => $this->minimum_indoor_temperature !== null,
            'legacy_outdoor_minimum_alert_enabled' => false,
            'minimum_outdoor_temperature' => $this->minimum_outdoor_temperature, 'maximum_indoor_temperature' => $this->maximum_indoor_temperature, 'gas_threshold' => $this->gas_threshold, 'notification_preferences' => $this->notification_preferences, 'updated_at' => $this->updated_at?->toISOString()];
    }
}
