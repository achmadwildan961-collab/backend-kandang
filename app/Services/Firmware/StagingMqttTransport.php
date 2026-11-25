<?php

namespace App\Services\Firmware;

use App\Services\Integration\CanonicalPayload;
use App\Services\Integration\MqttTransport;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/** Unbound config-only transport for a dedicated loopback virtual broker. */
class StagingMqttTransport implements MqttTransport
{
    public function __construct(private StagingMqttFactory $factory) {}

    public function publish(string $topic, array $payload, bool $retained, string $idempotencyKey): void
    {
        $this->factory->validate();
        $scope = config('firmware.staging.scopes');
        $device = $payload['device_identifier'] ?? '';
        $domain = $payload['domain'] ?? '';
        $coop = $payload['coop_id'] ?? null;
        if (! is_string($device) || ! isset($scope[$device]) || $scope[$device] !== $coop
            || ! in_array($domain, ['operational', 'lighting_lux'], true)
            || $topic !== "smart-kandang/coops/$coop/devices/$device/integration/v1/config/$domain"
            || ($retained && config('firmware.staging.retained_authorized') !== true)) {
            throw new InvalidArgumentException('Staging topic, scope or retained policy rejected.');
        }
        app(ContractSchema::class)->validate('config-'.str_replace('_', '-', $domain), $payload);
        $raw = $payload;
        unset($raw['payload_hash']);
        if ($payload['message_id'] !== $idempotencyKey || ! hash_equals($payload['payload_hash'], hash('sha256', CanonicalPayload::encode($raw)))
            || ContractTime::parse($payload['generated_at'])->isFuture() || ContractTime::parse($payload['expires_at'])->lte(now())) {
            throw new InvalidArgumentException('Staging configuration integrity or expiry rejected.');
        }
        $json = CanonicalPayload::encode($payload);
        for ($attempt = 1; $attempt <= min(3, max(1, config('firmware.staging.attempts'))); $attempt++) {
            $client = null;
            try {
                $client = $this->factory->make();
                $client->connect($this->factory->settings(), true);
                $client->publish($topic, $json, 1, $retained);
                $client->loop(true, true, min(10, max(1, config('firmware.staging.timeout_seconds'))));
                if (! $client->brokerAcknowledged()) {
                    throw new RuntimeException('Broker acknowledgement timeout.');
                }
                Log::info('Virtual staging MQTT broker acknowledged.', ['coop_id' => $coop, 'device' => $device, 'attempt' => $attempt]);

                return; // Broker receipt only. Never change any applied/reported DB state.
            } catch (\Throwable) {
                Log::warning('Virtual staging MQTT attempt failed.', ['coop_id' => $coop, 'device' => $device, 'attempt' => $attempt]);
            } finally {
                try {
                    if ($client?->isConnected()) {
                        $client->disconnect();
                    }
                } catch (\Throwable) {
                }
            }
        }
        throw new RuntimeException('Isolated staging publish failed within its retry budget.');
    }
}
