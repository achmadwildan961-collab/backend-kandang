<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'coop_id' => $this->coop_id, 'device_id' => $this->device_id, 'event_type' => $this->event_type, 'severity' => $this->severity, 'description' => $this->description, 'metadata' => $this->metadata, 'occurred_at' => $this->occurred_at->toISOString()];
    }
}
