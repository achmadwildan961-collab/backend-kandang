<?php

namespace App\Services\Push;

interface PushProvider
{
    // Receipt means provider accepted, not that a handset received/read the push.
    public function send(string $token, array $payload, string $idempotencyKey): string;
}
