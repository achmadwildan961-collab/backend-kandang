<?php

namespace App\Services\Integration;

final class FakeMqttTransport implements MqttTransport
{
    public array $messages = [];

    public int $failures = 0;

    public function publish(string $topic, array $payload, bool $retained, string $idempotencyKey): void
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException('Fake transport requires testing environment.');
        }
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('Synthetic transport failure');
        }
        $this->messages[] = compact('topic', 'payload', 'retained', 'idempotencyKey');
    }
}
