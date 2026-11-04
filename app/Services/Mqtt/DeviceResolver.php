<?php

namespace App\Services\Mqtt;

use App\Models\Device;
use InvalidArgumentException;

class DeviceResolver
{
    public function resolve(string $topic): array
    {
        $prefix = preg_quote(config('mqtt.topic_prefix'), '~');
        if (! preg_match('~\A'.$prefix.'/coops/([1-9][0-9]*)/devices/([A-Za-z0-9_-]{1,100})/(telemetry|status)\z~', $topic, $parts)) {
            throw new InvalidArgumentException('Invalid topic.');
        }
        $device = Device::where('coop_id', $parts[1])->where('device_identifier', $parts[2])->first();
        if (! $device || $device->device_identifier !== $parts[2]) {
            throw new InvalidArgumentException('Unregistered device or coop mapping.');
        }

        return [$device, $parts[3]];
    }
}
