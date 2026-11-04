<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'device_id' => $this->device_id, 'coop_id' => $this->coop_id, 'sensor_identifier' => $this->sensor_identifier, 'sensor_type' => $this->sensor_type, 'value' => $this->value, 'unit' => $this->unit, 'recorded_at' => $this->recorded_at->toISOString()];
    }
}
