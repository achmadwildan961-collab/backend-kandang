<?php

namespace App\Services\Firmware;

use App\Services\HumidityContract;
use App\Services\Integration\CanonicalPayload;
use App\Services\SensorCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Pure in-memory firmware reference simulator. No GPIO, network or database. */
class VirtualDevice
{
    public string $bootId;

    private int $sequence = 0;

    private array $stored = [];

    public function __construct(public readonly int $coopId, public readonly string $identifier, public readonly string $board)
    {
        $this->require(app()->environment(['local', 'testing']), 'Simulator requires local/testing environment.');
        $this->require($coopId > 0 && preg_match('/\AVIRTUAL_[A-Z0-9_]{1,80}\z/', $identifier) === 1
            && in_array($board, ['esp32', 'nodemcu_esp8266'], true), 'Invalid virtual identity.');
        $this->reboot();
    }

    public function reboot(): string
    {
        $this->bootId = (string) Str::uuid();
        $this->sequence = 0;

        return $this->bootId; // Stored configuration simulates NVS, never relay state.
    }

    public function topic(string $suffix): string
    {
        return "smart-kandang/coops/{$this->coopId}/devices/{$this->identifier}/$suffix";
    }

    public function telemetry(string $scenario = 'complete'): array
    {
        $this->require(in_array($scenario, ['complete', 'partial', 'invalid-unit', 'stale', 'out-of-order'], true), 'Unknown scenario.');
        $values = $this->board === 'esp32'
            ? array_fill_keys(SensorCatalog::indoorIdentifiers(), 28) + array_fill_keys(HumidityContract::indoorIdentifiers(), 65) + ['gas_adc' => 1000]
            : ['outdoor_temperature' => 27, 'outdoor_humidity' => 70, 'outdoor_light_lux' => 120];
        if ($scenario === 'partial') {
            unset($values[$this->board === 'esp32' ? 'indoor_dht22_5_humidity' : 'outdoor_humidity']);
        }
        $rows = array_map(fn ($id, $value) => ['sensor_identifier' => $id, 'value' => $value], array_keys($values), array_values($values));
        if ($scenario === 'invalid-unit') {
            $rows[0]['unit'] = 'wrong';
        }
        $time = match ($scenario) {
            'stale' => now()->subSeconds(301), 'out-of-order' => now()->subSecond(), default => now()
        };

        return ['message_id' => (string) Str::uuid(), 'sent_at' => $time->utc()->format('Y-m-d\TH:i:s.u\Z'), 'readings' => $rows];
    }

    public function status(bool $online): array
    {
        return ['message_id' => (string) Str::uuid(), 'sent_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => $online ? 'online' : 'offline'];
    }

    public function receiveConfiguration(array $payload, bool $reject = false): array
    {
        $domain = $payload['domain'] ?? '';
        $this->require(in_array($domain, ['operational', 'lighting_lux'], true), 'Unknown domain.');
        app(ContractSchema::class)->validate('config-'.str_replace('_', '-', $domain), $payload);
        $this->require($payload['coop_id'] === $this->coopId && $payload['device_identifier'] === $this->identifier, 'Config scope mismatch.');
        $raw = $payload;
        unset($raw['payload_hash']);
        $this->require(hash_equals($payload['payload_hash'], hash('sha256', CanonicalPayload::encode($raw))), 'Config hash mismatch.');
        $this->require(ContractTime::parse($payload['generated_at'])->lte(now()) && ContractTime::parse($payload['expires_at'])->isFuture(), 'Config time invalid.');
        $old = $this->stored[$domain] ?? null;
        $this->require(! $old || $payload['config_version'] > $old['config_version']
            || ($payload['config_version'] === $old['config_version'] && $payload['payload_hash'] === $old['payload_hash']), 'Config version replay/collision.');
        $settings = $payload['settings'];
        if ($domain === 'operational') {
            $this->require($settings['minimum_indoor_temperature'] === null || $settings['minimum_indoor_temperature'] < $settings['maximum_indoor_temperature'], 'Invalid temperature order.');
        } else {
            $this->require(($settings['min_lux'] === null && $settings['max_lux'] === null)
                || ($settings['min_lux'] !== null && $settings['max_lux'] !== null && $settings['min_lux'] < $settings['max_lux']), 'Invalid lux order.');
        }
        if (! $reject) {
            $this->stored[$domain] = $payload;
        }

        return $this->ack($payload, $reject ? 'rejected' : 'acknowledged');
    }

    public function applied(string $domain): array
    {
        $stored = $this->stored[$domain] ?? null;
        $this->require($stored !== null && ContractTime::parse($stored['expires_at'])->isFuture(), 'No valid stored configuration.');

        return $this->ack($stored, 'applied'); // Simulated NVS acceptance, not physical actuation.
    }

    public function report(array $states = []): array
    {
        $this->require($this->board === 'esp32', 'Outdoor cannot report indoor actuators.');
        $payload = $this->common('report') + ['feedback_kind' => 'gpio', 'states' => $states + array_fill_keys(['heating_lamp', 'ventilation_fan_1', 'ventilation_fan_2', 'lighting_lamp'], null)];
        app(ContractSchema::class)->validate('report', $payload);

        return $payload;
    }

    public function receiveDisplay(array $payload, int $registeredDeviceId): array
    {
        app(ContractSchema::class)->validate('display', $payload);
        $this->require($this->board === 'nodemcu_esp8266' && $payload['coop_id'] === $this->coopId
            && in_array($registeredDeviceId, $payload['target_device_ids'], true), 'Display scope mismatch.');
        $this->require(ContractTime::parse($payload['generated_at'])->lte(now()) && ContractTime::parse($payload['expires_at'])->isFuture(), 'Display expired/future.');

        return $payload['fields']; // Layout intentionally left to hardware decision.
    }

    private function ack(array $config, string $status): array
    {
        return $this->common('ack') + ['configuration_message_id' => $config['message_id'], 'domain' => $config['domain'],
            'config_version' => $config['config_version'], 'payload_hash' => $config['payload_hash'], 'status' => $status];
    }

    private function common(string $kind): array
    {
        return ['schema_version' => 1, 'kind' => $kind, 'message_id' => (string) Str::uuid(), 'coop_id' => $this->coopId,
            'device_identifier' => $this->identifier, 'boot_id' => $this->bootId, 'sequence' => ++$this->sequence,
            'sent_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
