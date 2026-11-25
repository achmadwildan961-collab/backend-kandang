<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'device_id' => $this->device_id, 'coop_id' => $this->coop_id, 'sensor_identifier' => $this->sensor_identifier, 'sensor_type' => $this->sensor_type, 'value' => $this->value, 'unit' => $this->unit, 'recorded_at' => $this->recorded_at->toISOString(),
            'batch_key' => $this->batch_key,
            'sensor_model' => $this->when($this->sensor_identifier === 'outdoor_light_lux', 'BH1750'),
            'legacy' => $this->when($this->sensor_identifier === 'outdoor_light_adc', true),
            'measurement_version' => $this->when($this->sensor_type === 'outdoor_light', $this->measurement_version)];
    }
}
