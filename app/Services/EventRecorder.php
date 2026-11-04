<?php

namespace App\Services;

use App\Models\Coop;
use App\Models\SystemEvent;
use App\Models\User;

class EventRecorder
{
    public function record(Coop $coop, string $type, string $severity, string $title, string $description, array $metadata = [], ?int $deviceId = null): SystemEvent
    {
        $event = $coop->events()->create(['device_id' => $deviceId, 'event_type' => $type, 'severity' => $severity, 'description' => $description, 'metadata' => $metadata, 'occurred_at' => now()]);
        $preferences = $coop->settings()->firstOrFail()->notification_preferences;
        $source = $metadata['source'] ?? 'configuration';
        $visible = ($preferences['push_notifications'] ?? true) && match ($source) {
            'indoor','outdoor' => ($preferences['critical_alerts'] ?? true) && ($preferences['temperature_alerts'] ?? true),
            'gas' => ($preferences['critical_alerts'] ?? true) && ($preferences['gas_alerts'] ?? true),
            'configuration' => $preferences['maintenance_alerts'] ?? false,
            default => true,
        };
        if ($visible) {
            User::where('status', 'active')->where(fn ($q) => $q->where('role', 'admin')->orWhereHas('coops', fn ($c) => $c->whereKey($coop->id)))
                ->each(function (User $user) use ($coop, $event, $type, $severity, $title, $description) {
                    $user->notifications()->create(['coop_id' => $coop->id, 'system_event_id' => $event->id, 'type' => $type, 'title' => $title, 'message' => $description, 'severity' => $severity]);
                });
        }

        return $event;
    }
}
