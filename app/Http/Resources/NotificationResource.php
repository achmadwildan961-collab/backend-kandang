<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'coop_id' => $this->coop_id, 'system_event_id' => $this->system_event_id, 'type' => $this->type, 'title' => $this->title, 'message' => $this->message, 'severity' => $this->severity, 'read_at' => $this->read_at?->toISOString(), 'is_read' => $this->read_at !== null, 'created_at' => $this->created_at?->toISOString()];
    }
}
