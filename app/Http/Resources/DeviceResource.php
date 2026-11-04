<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'coop_id' => $this->coop_id, 'device_identifier' => $this->device_identifier, 'name' => $this->name, 'type' => $this->type, 'connection_status' => $this->connection_status, 'last_seen_at' => $this->last_seen_at?->toISOString()];
    }
}
