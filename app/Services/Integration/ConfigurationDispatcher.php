<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Models\Device;
use App\Services\Firmware\StagingIdentity;
use App\Services\Firmware\StagingMqttTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfigurationDispatcher
{
    public function __construct(private MqttTransport $transport, private ConfigurationDelivery $configurations) {}

    public function dispatch(int $id, ?StagingIdentity $staging = null): string
    {
        if ($staging) {
            $staging->device();
        }
        $allowed = $staging ? $this->transport instanceof StagingMqttTransport
            : IntegrationPolicy::simulation() && $this->transport instanceof FakeMqttTransport;
        if (! $allowed) {
            return 'blocked';
        }
        $claim = DB::transaction(function () use ($id, $staging) {
            $candidate = DB::table('integration_deliveries')->find($id);
            if (! $candidate) {
                return null;
            }
            Coop::whereKey($candidate->coop_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            $staging?->authorize($device);
            $row = DB::table('integration_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! in_array($row->status, ['pending', 'published', 'acknowledged'], true)
                || ($row->lease_until && CarbonImmutable::parse($row->lease_until)->isFuture())) {
                return null;
            }
            $reason = ! $this->configurations->isCurrent($row, $device) ? 'superseded_or_reassigned'
                : (CarbonImmutable::parse($row->expires_at)->lte(now()) ? 'expired' : null);
            if ($reason) {
                DB::table('integration_deliveries')->where('id', $id)->update(['status' => 'failed', 'reason' => $reason, 'updated_at' => now()]);

                return null;
            }
            if ($row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at)->isFuture()) {
                return null;
            }
            $session = DB::table('integration_sessions')->where('device_id', $device->id)->where('coop_id', $device->coop_id)->where('current', true)->first();
            if (! IntegrationPolicy::online($device) || ! $session) {
                DB::table('integration_deliveries')->where('id', $id)->update(['reason' => 'offline_or_session_unverified',
                    'next_attempt_at' => now()->addSeconds(max(1, config('integration.retry_seconds'))), 'updated_at' => now()]);

                return null;
            }
            if ($row->attempts >= max(1, config('integration.max_attempts'))) {
                DB::table('integration_deliveries')->where('id', $id)->update(['status' => 'failed', 'reason' => 'attempts_exhausted', 'updated_at' => now()]);

                return null;
            }
            $lease = (string) Str::uuid();
            DB::table('integration_deliveries')->where('id', $id)->update(['attempts' => $row->attempts + 1,
                'boot_id' => $session->boot_id, 'lease_token' => $lease,
                'lease_until' => now()->addSeconds(max(1, config('integration.lease_seconds'))), 'reason' => null, 'updated_at' => now()]);

            return [$row, $device, $lease];
        }, 3);
        if (! $claim) {
            return 'skipped';
        }
        [$row, $device, $lease] = $claim;
        try {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            // Only explicit virtual staging or unit simulation; publication is never application.
            $this->transport->publish("smart-kandang/coops/{$row->coop_id}/devices/{$device->device_identifier}/integration/v1/config/{$row->domain}",
                $payload + ['payload_hash' => $row->payload_hash], true, $row->message_id);
            $changes = ['status' => $row->status === 'acknowledged' ? 'acknowledged' : 'published', 'published_at' => now(),
                'next_attempt_at' => now()->addSeconds(max(1, config('integration.ack_seconds'))), 'reason' => null];
            $result = 'published';
        } catch (\Throwable) {
            $exhausted = $row->attempts + 1 >= max(1, config('integration.max_attempts'));
            $changes = ['status' => $exhausted ? 'failed' : $row->status, 'reason' => $exhausted ? 'attempts_exhausted' : 'transport_failure',
                'next_attempt_at' => now()->addSeconds(min(3600, max(1, config('integration.retry_seconds')) * (2 ** min(10, $row->attempts))))];
            $result = $exhausted ? 'failed' : 'retry';
        }
        // ACK can arrive during publish. Do not downgrade an acknowledged/applied row.
        DB::table('integration_deliveries')->where('id', $id)->where('lease_token', $lease)->where('status', $row->status)
            ->update($changes + ['lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);

        return $result;
    }

    public function run(int $limit = 100): int
    {
        $ids = DB::table('integration_deliveries')->whereIn('status', ['pending', 'published', 'acknowledged'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')->orderBy('id')->limit(min(100, max(1, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $this->dispatch($id);
        }

        return $ids->count();
    }
}
