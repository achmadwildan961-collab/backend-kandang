<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Models\Device;
use App\Models\User;
use App\Services\LuxLightingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CommandDraft
{
    // Internal simulation foundation only; public command endpoints always return 409.
    public function create(User $actor, Coop $coop, Device $device, array $data): array
    {
        return DB::transaction(function () use ($actor, $coop, $device, $data) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('updateSettings', $coop);
            abort_unless($actor->status === 'active', 403);
            $coop = Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->coop_id === $coop->id && $device->type === 'esp32', 422);
            $validated = Validator::make($data, ['request_id' => ['required', 'uuid'],
                'actuator' => ['required', 'in:heating_lamp,ventilation_fan_1,ventilation_fan_2,lighting_lamp'],
                'desired_state' => ['required', 'boolean'], 'expected_config_version' => ['required', 'integer', 'min:0'],
                'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z']])->validate();
            abort_unless(count($validated) === count($data) && is_bool($data['desired_state']), 422);
            $expiry = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['expires_at'], 'UTC');
            abort_unless($expiry->gt(now()) && $expiry->lte(now()->addSeconds(max(1, config('integration.command_seconds')))), 422);
            $lighting = app(LuxLightingSettings::class)->snapshot($coop);
            $version = $data['actuator'] === 'lighting_lamp' ? $lighting['config_version'] : $coop->settings()->firstOrFail()->config_version;
            abort_unless($version === $data['expected_config_version'], 409, 'Configuration changed.');
            abort_if($data['actuator'] === 'lighting_lamp' && $lighting['lighting_mode'] !== 'manual', 409, 'Manual mode required.');
            $session = DB::table('integration_sessions')->where('device_id', $device->id)->where('coop_id', $coop->id)->where('current', true)->first();
            $payload = $validated + ['schema_version' => 1, 'contract' => 'phase8_draft', 'coop_id' => $coop->id,
                'device_identifier' => $device->device_identifier, 'boot_id' => $session?->boot_id, 'retained' => false];
            $json = CanonicalPayload::encode($payload);
            $hash = hash('sha256', $json);
            $old = DB::table('actuator_command_drafts')->where('user_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Command request replay/collision.');

                return ['id' => $old->id, 'status' => 'blocked', 'payload' => $payload];
            }
            $id = DB::table('actuator_command_drafts')->insertGetId(['coop_id' => $coop->id, 'device_id' => $device->id,
                'user_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'payload' => $json,
                'status' => 'blocked', 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);

            return ['id' => $id, 'status' => 'blocked', 'payload' => $payload];
        }, 3);
    }
}
