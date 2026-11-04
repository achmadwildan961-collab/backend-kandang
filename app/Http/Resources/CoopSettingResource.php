<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoopSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['coop_id' => $this->coop_id, 'minimum_outdoor_temperature' => $this->minimum_outdoor_temperature, 'maximum_indoor_temperature' => $this->maximum_indoor_temperature, 'gas_threshold' => $this->gas_threshold, 'notification_preferences' => $this->notification_preferences, 'updated_at' => $this->updated_at?->toISOString()];
    }
}
