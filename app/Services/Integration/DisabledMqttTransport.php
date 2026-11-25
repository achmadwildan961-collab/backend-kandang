<?php

namespace App\Services\Integration;

class DisabledMqttTransport implements MqttTransport
{
    public function publish(string $topic, array $payload, bool $retained, string $idempotencyKey): void
    {
        throw new \LogicException('Physical integration is disabled.');
    }
}
