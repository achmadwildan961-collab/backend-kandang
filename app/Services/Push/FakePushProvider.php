<?php

namespace App\Services\Push;

final class FakePushProvider implements PushProvider
{
    public array $messages = [];

    public int $failures = 0;

    public bool $invalidToken = false;

    public function send(string $token, array $payload, string $idempotencyKey): string
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException('Fake push requires tests.');
        }
        if ($this->invalidToken) {
            throw new InvalidPushToken('Synthetic invalid token.');
        }
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('Synthetic provider failure.');
        }
        $this->messages[] = compact('token', 'payload', 'idempotencyKey');

        return 'fake-'.$idempotencyKey;
    }
}
