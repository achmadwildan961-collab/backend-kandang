<?php

namespace App\Services;

use App\Models\Coop;
use App\Models\CoopSetting;
use App\Services\Integration\ConfigurationDelivery;
use Illuminate\Support\Facades\DB;
use LogicException;

class OperationalConfigurationOutbox
{
    public function stage(Coop $coop, CoopSetting $settings): void
    {
        if (DB::transactionLevel() < 1 || $settings->coop_id !== $coop->id) {
            throw new LogicException('Configuration staging requires the settings transaction and matching coop.');
        }
        // Internal draft envelope only, not an approved firmware wire payload.
        // No topic, publisher, retry worker or activation flag exists in this foundation.
        foreach ($coop->devices()->get() as $device) {
            $key = hash('sha256', "operational:{$coop->id}:{$device->id}:{$settings->config_version}");
            $payload = [
                'schema_version' => 2, 'contract_status' => 'draft', 'config_version' => $settings->config_version,
                'coop_id' => $coop->id, 'device_id' => $device->id, 'device_identifier' => $device->device_identifier,
                'message_id' => $key,
                'configuration' => ['minimum_indoor_temperature' => $settings->minimum_indoor_temperature,
                    'maximum_indoor_temperature' => $settings->maximum_indoor_temperature, 'temperature_unit' => 'celsius',
                    'gas_threshold' => $settings->gas_threshold, 'gas_unit' => 'adc'],
            ];
            // Same target/version always identifies the same immutable draft.
            $identity = ['coop_id' => $coop->id, 'device_id' => $device->id, 'config_version' => $settings->config_version];
            if (! DB::table('operational_configuration_outbox')->where($identity)->exists()) {
                DB::table('operational_configuration_outbox')->insert($identity +
                    ['idempotency_key' => $key, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'status' => 'blocked', 'blocked_reason' => 'firmware_contract_unverified', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(ConfigurationDelivery::class)->stage($coop, 'operational');
    }
}
