<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Models\Device;
use App\Services\Firmware\StagingIdentity;
use App\Services\LuxLightingSettings;
use Illuminate\Support\Facades\DB;

class ConfigurationDelivery
{
    public function current(Coop $coop, string $domain): ?array
    {
        if ($domain === 'operational') {
            $settings = $coop->settings()->firstOrFail();

            return ['version' => $settings->config_version, 'settings' => $settings->only([
                'minimum_indoor_temperature', 'maximum_indoor_temperature', 'gas_threshold',
            ]) + ['temperature_unit' => 'celsius', 'gas_unit' => 'adc']];
        }
        if ($domain === 'lighting_lux') {
            $settings = app(LuxLightingSettings::class)->snapshot($coop);

            return $settings['config_version'] > 0 ? ['version' => $settings['config_version'],
                'settings' => array_intersect_key($settings, array_flip(['lighting_mode', 'min_lux', 'max_lux', 'threshold_unit', 'measurement_version']))] : null;
        }
        throw new \InvalidArgumentException('Unknown configuration domain.');
    }

    public function stage(Coop $coop, string $domain, ?StagingIdentity $staging = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Stage configuration within its settings transaction.');
        }
        Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
        if ($staging && $staging->device()->coop_id !== $coop->id) {
            throw new \InvalidArgumentException('Staging configuration coop mismatch.');
        }
        $current = $this->current($coop, $domain);
        if (! $current) {
            return;
        }
        foreach ($coop->devices()->get() as $device) {
            if ($staging && $device->id !== $staging->deviceId) {
                continue;
            }
            $identity = ['coop_id' => $coop->id, 'device_id' => $device->id, 'domain' => $domain, 'config_version' => $current['version']];
            if (DB::table('integration_deliveries')->where($identity)->exists()) {
                continue;
            }
            // New versions supersede older work; blocked Phase 7 rows are never drained.
            DB::table('integration_deliveries')->where('coop_id', $coop->id)->where('device_id', $device->id)
                ->where('domain', $domain)->whereIn('status', ['pending', 'published', 'acknowledged'])
                ->update(['status' => 'failed', 'reason' => 'superseded', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            $message = hash('sha256', implode(':', $identity));
            $expires = now()->addSeconds(max(1, config('integration.snapshot_seconds')));
            $payload = ['schema_version' => 1, 'contract' => 'phase8_draft', 'message_id' => $message,
                'coop_id' => $coop->id, 'device_identifier' => $device->device_identifier,
                'domain' => $domain, 'config_version' => $current['version'], 'settings' => $current['settings'],
                'generated_at' => now()->toISOString(), 'expires_at' => $expires->toISOString()];
            $json = CanonicalPayload::encode($payload);
            $enabled = ($staging !== null || IntegrationPolicy::simulation()) && IntegrationPolicy::target($device);
            DB::table('integration_deliveries')->insert($identity + ['message_id' => $message, 'payload_hash' => hash('sha256', $json),
                'payload' => $json, 'status' => $enabled ? 'pending' : 'blocked', 'reason' => $enabled ? null : 'firmware_contract_unverified',
                'expires_at' => $expires, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function isCurrent(object $delivery, Device $device): bool
    {
        if ($device->coop_id !== $delivery->coop_id || ! IntegrationPolicy::target($device)) {
            return false;
        }
        $payload = json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
        $current = $this->current(Coop::findOrFail($delivery->coop_id), $delivery->domain);

        return $current && $current['version'] === $delivery->config_version
            && $payload['device_identifier'] === $device->device_identifier;
    }
}
