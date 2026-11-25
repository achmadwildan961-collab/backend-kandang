<?php

namespace App\Services\Integration;

interface MqttTransport
{
    public function publish(string $topic, array $payload, bool $retained, string $idempotencyKey): void;
}
