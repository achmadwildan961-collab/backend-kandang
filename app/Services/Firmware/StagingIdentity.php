<?php

namespace App\Services\Firmware;

use App\Models\Device;
use App\Services\Integration\IntegrationPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** A revocable, device-scoped capability. Never derived from MQTT payload fields. */
final class StagingIdentity
{
    private function __construct(public readonly int $deviceId, public readonly int $coopId,
        public readonly string $identifier, private readonly string $credentialHash) {}

    public static function authenticate(string $username, #[\SensitiveParameter] string $password): self
    {
        self::guard();
        $hash = config('firmware.staging.device_password_hashes')[$username] ?? null;
        if (! is_string($hash) || ! password_verify($password, $hash)) {
            throw new InvalidArgumentException('Staging device authentication rejected.');
        }
        $device = Device::where('device_identifier', $username)->first();
        if (! $device || $device->device_identifier !== $username) {
            throw new InvalidArgumentException('Staging device authentication rejected.');
        }
        $identity = new self($device->id, $device->coop_id, $username, $hash);
        $identity->authorize($device);

        return $identity;
    }

    public function authorize(Device $device): void
    {
        self::guard();
        if ($device->id !== $this->deviceId || $device->coop_id !== $this->coopId
            || $device->device_identifier !== $this->identifier || ! IntegrationPolicy::target($device)
            || (config('firmware.staging.scopes')[$this->identifier] ?? null) !== $this->coopId
            || (config('firmware.staging.device_password_hashes')[$this->identifier] ?? null) !== $this->credentialHash) {
            throw new InvalidArgumentException('Staging identity revoked or scope changed.');
        }
    }

    public function device(): Device
    {
        $device = Device::findOrFail($this->deviceId);
        $this->authorize($device);

        return $device;
    }

    private static function guard(): void
    {
        app(StagingMqttFactory::class)->validate();
        $connection = DB::connection();
        $isolated = ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:')
            || ($connection->getDriverName() === 'mysql' && $connection->getDatabaseName() === 'smart_kandang_test'
                && $connection->getConfig('host') === '127.0.0.1');
        if (config('firmware.staging.integration_enabled') !== true || ! $isolated) {
            throw new InvalidArgumentException('Staging integration requires an explicitly enabled isolated test database.');
        }
    }
}
