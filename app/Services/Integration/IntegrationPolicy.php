<?php

namespace App\Services\Integration;

use App\Models\Device;

class IntegrationPolicy
{
    public static function simulation(): bool
    {
        return app()->runningUnitTests() && config('integration.simulation') === true;
    }

    public static function online(Device $device): bool
    {
        return $device->connection_status === 'online' && $device->last_seen_at
            && $device->last_seen_at->lte(now())
            && $device->last_seen_at->gte(now()->subSeconds(max(1, config('mqtt.offline_after_seconds'))));
    }

    public static function target(Device $device): bool
    {
        return in_array($device->type, ['esp32', 'nodemcu_esp8266'], true)
            && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $device->device_identifier) === 1;
    }
}
