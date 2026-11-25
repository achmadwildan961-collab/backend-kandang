<?php

namespace App\Services;

use App\Models\Coop;
use App\Models\Device;

class HumidityContract
{
    public const DHT22_VERSION = 'dht22_humidity_v1';

    public const AHT20_VERSION = 'aht20_v1';

    public static function indoorIdentifiers(): array
    {
        return array_map(fn ($id) => $id.'_humidity', SensorCatalog::indoorIdentifiers());
    }

    public static function version(string $id): ?string
    {
        return in_array($id, self::indoorIdentifiers(), true) ? self::DHT22_VERSION
            : (in_array($id, ['outdoor_temperature', 'outdoor_humidity'], true) ? self::AHT20_VERSION : null);
    }

    public static function accepts(Device $device, string $id): bool
    {
        $version = self::version($id);
        $mapping = config('mqtt.device_sensors');
        $allowed = is_array($mapping) ? ($mapping[$device->device_identifier] ?? null) : null;

        return $version !== null && is_array($allowed) && in_array($id, $allowed, true)
            && $device->type === ($version === self::DHT22_VERSION ? 'esp32' : 'nodemcu_esp8266');
    }

    public static function deviceIds(Coop $coop, string $id): array
    {
        return $coop->devices()->get()->filter(fn ($device) => self::accepts($device, $id))->modelKeys();
    }

    public static function sqlConstraint(Coop $coop, string $id): string
    {
        $ids = implode(',', array_map('intval', self::deviceIds($coop, $id))) ?: '0';
        $version = self::version($id);
        $now = now()->format('Y-m-d H:i:s.u');

        return "device_id IN ($ids) AND measurement_version = '$version' AND batch_key IS NOT NULL AND recorded_at <= '$now'";
    }
}
