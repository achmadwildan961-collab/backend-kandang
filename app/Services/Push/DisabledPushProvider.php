<?php

namespace App\Services\Push;

class DisabledPushProvider implements PushProvider
{
    public function send(string $token, array $payload, string $idempotencyKey): string
    {
        throw new \LogicException('External push delivery disabled.');
    }
}
