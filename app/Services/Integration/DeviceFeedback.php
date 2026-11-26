<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Models\Device;
use App\Services\Firmware\StagingIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeviceFeedback
{
    // Internal adapter: explicit authenticated staging capability or unit simulation.
    // No live subscriber/HTTP route exposes these methods in this release.
    public function openSession(Device $authenticatedDevice, string $bootId, ?StagingIdentity $staging = null): void
    {
        $this->guard($authenticatedDevice, $staging);
        Validator::make(['boot_id' => $bootId], ['boot_id' => ['required', 'uuid']])->validate();
        DB::transaction(function () use ($authenticatedDevice, $bootId, $staging) {
            Coop::whereKey($authenticatedDevice->coop_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($authenticatedDevice->id)->lockForUpdate()->firstOrFail();
            $staging?->authorize($device);
            $this->require($device->coop_id === $authenticatedDevice->coop_id && IntegrationPolicy::target($device), 'Device reassigned or unsupported.');
            $previous = DB::table('integration_sessions')->where('device_id', $device->id)->where('boot_id', $bootId)->first();
            $this->require(! $previous || ($previous->current && $previous->coop_id === $device->coop_id), 'Retired boot replay.');
            if (! $previous) {
                DB::table('integration_sessions')->where('device_id', $device->id)->update(['current' => false]);
                DB::table('integration_sessions')->insert(['coop_id' => $device->coop_id, 'device_id' => $device->id,
                    'boot_id' => $bootId, 'current' => true, 'last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);
                // A new boot must re-ack current unexpired snapshots; never replay commands.
                DB::table('integration_deliveries')->where('device_id', $device->id)->where('coop_id', $device->coop_id)
                    ->whereIn('status', ['published', 'acknowledged', 'applied'])->where('expires_at', '>', now())
                    ->update(['status' => 'pending', 'attempts' => 0, 'boot_id' => null, 'acknowledged_at' => null,
                        'applied_at' => null, 'published_at' => null, 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now(), 'updated_at' => now()]);
            }
            $device->update(['connection_status' => 'online', 'last_seen_at' => now()]);
        }, 3);
    }

    public function ingest(Device $authenticatedDevice, string $topic, array $payload, bool $retained = false, ?StagingIdentity $staging = null): string
    {
        $this->guard($authenticatedDevice, $staging);
        $this->require(! $retained, 'Retained feedback rejected.');
        $kind = $payload['kind'] ?? null;
        $rules = ['schema_version' => ['required', 'integer', 'in:1'], 'kind' => ['required', 'in:ack,report'],
            'message_id' => ['required', 'uuid'], 'coop_id' => ['required', 'integer'], 'device_identifier' => ['required', 'string'],
            'boot_id' => ['required', 'uuid'], 'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'sent_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z']];
        $rules += $kind === 'ack' ? ['configuration_message_id' => ['required', 'string', 'size:64'],
            'domain' => ['required', 'in:operational,lighting_lux'], 'config_version' => ['required', 'integer', 'min:1'],
            'payload_hash' => ['required', 'string', 'size:64'], 'status' => ['required', 'in:acknowledged,applied,rejected']]
            : ['states' => ['required', 'array:heating_lamp,ventilation_fan_1,ventilation_fan_2,lighting_lamp'],
                'feedback_kind' => ['required', 'in:gpio,physical']];
        Validator::make($payload, $rules)->validate();
        $this->require(array_diff(array_keys($payload), array_keys($rules)) === [], 'Unknown feedback fields.');
        if ($kind === 'report') {
            foreach (['heating_lamp', 'ventilation_fan_1', 'ventilation_fan_2', 'lighting_lamp'] as $actuator) {
                $this->require(array_key_exists($actuator, $payload['states']) && (is_bool($payload['states'][$actuator]) || $payload['states'][$actuator] === null), 'Invalid reported state.');
            }
        }
        $sent = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $payload['sent_at'], 'UTC');
        $this->require($sent->lte(now()) && $sent->gte(now()->subSeconds(max(1, config('integration.report_seconds')))), 'Stale or future feedback.');

        return DB::transaction(function () use ($authenticatedDevice, $topic, $payload, $kind, $sent, $staging) {
            Coop::whereKey($authenticatedDevice->coop_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($authenticatedDevice->id)->lockForUpdate()->firstOrFail();
            $staging?->authorize($device);
            $expected = "smart-kandang/coops/{$device->coop_id}/devices/{$device->device_identifier}/integration/v1/$kind";
            $this->require($device->coop_id === $authenticatedDevice->coop_id && IntegrationPolicy::target($device)
                && $topic === $expected && $payload['coop_id'] === $device->coop_id
                && $payload['device_identifier'] === $device->device_identifier, 'Feedback scope mismatch.');
            $session = DB::table('integration_sessions')->where('device_id', $device->id)->where('coop_id', $device->coop_id)
                ->where('boot_id', $payload['boot_id'])->where('current', true)->lockForUpdate()->first();
            $this->require($session !== null, 'Unknown or retired session.');
            $hash = hash('sha256', CanonicalPayload::encode($payload));
            $receipt = DB::table('integration_receipts')->where('device_id', $device->id)->where('message_id', $payload['message_id'])->first();
            if ($receipt) {
                $this->require(hash_equals($receipt->payload_hash, $hash), 'Message ID collision.');

                return 'duplicate';
            }
            $this->require($payload['sequence'] > $session->last_sequence, 'Sequence replay.');
            if ($kind === 'ack') {
                $row = DB::table('integration_deliveries')->where('message_id', $payload['configuration_message_id'])->lockForUpdate()->first();
                $this->require($row && $row->coop_id === $device->coop_id && $row->device_id === $device->id
                    && $row->domain === $payload['domain'] && $row->config_version === $payload['config_version']
                    && hash_equals($row->payload_hash, $payload['payload_hash']) && $row->boot_id === $payload['boot_id']
                    && $row->attempts > 0 && CarbonImmutable::parse($row->expires_at)->isFuture()
                    && ($row->published_at || ($row->lease_token && $row->lease_until && CarbonImmutable::parse($row->lease_until)->isFuture()))
                    && app(ConfigurationDelivery::class)->isCurrent($row, $device)
                    && in_array($row->status, ['pending', 'published', 'acknowledged', 'applied'], true), 'ACK does not match current delivery.');
                $this->require($row->status !== 'applied' || $payload['status'] === 'applied', 'Applied state cannot regress.');
                $updates = ['status' => $payload['status'] === 'rejected' ? 'failed' : $payload['status'],
                    'reason' => $payload['status'] === 'rejected' ? 'device_rejected' : null,
                    'acknowledged_at' => $row->acknowledged_at ?? now(), 'updated_at' => now(), 'lease_token' => null, 'lease_until' => null];
                if ($payload['status'] === 'applied') {
                    $updates['applied_at'] = $row->applied_at ?? now();
                }
                DB::table('integration_deliveries')->where('id', $row->id)->update($updates);
            } else {
                $this->require($device->type === 'esp32', 'Outdoor node cannot report indoor actuators.');
                $previous = DB::table('actuator_reports')->where('device_id', $device->id)->first();
                $this->require(! $previous || $previous->boot_id !== $payload['boot_id']
                    || $sent->gte(CarbonImmutable::parse($previous->reported_at)), 'Report timestamp moved backwards.');
                DB::table('actuator_reports')->updateOrInsert(['device_id' => $device->id], ['coop_id' => $device->coop_id,
                    'boot_id' => $payload['boot_id'], 'sequence' => $payload['sequence'], 'states' => json_encode($payload['states'], JSON_THROW_ON_ERROR),
                    'feedback_kind' => $payload['feedback_kind'], 'reported_at' => $sent, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('integration_sessions')->where('id', $session->id)->update(['last_sequence' => $payload['sequence'], 'updated_at' => now()]);
            DB::table('integration_receipts')->insert(['device_id' => $device->id, 'message_id' => $payload['message_id'],
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $device->update(['connection_status' => 'online', 'last_seen_at' => now()]);

            return 'accepted';
        }, 3);
    }

    private function guard(Device $device, ?StagingIdentity $staging): void
    {
        if ($staging) {
            $staging->authorize($device);

            return;
        }
        $this->require(IntegrationPolicy::simulation(), 'Feedback adapter disabled pending authenticated firmware contract.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
