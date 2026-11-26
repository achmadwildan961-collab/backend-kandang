<?php

namespace App\Services\Firmware;

use App\Models\Coop;
use App\Services\Integration\CanonicalPayload;
use App\Services\Integration\DeviceFeedback;
use App\Services\Mqtt\TelemetryIngestor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Explicit trusted-adapter boundary, NOT a production subscriber or HTTP endpoint. */
class StagingIngress
{
    public function ingest(StagingIdentity $identity, string $topic, string $raw, bool $retained): string
    {
        $device = $identity->device();
        $prefix = "smart-kandang/coops/{$device->coop_id}/devices/{$device->device_identifier}/";
        if ($retained || ! str_starts_with($topic, $prefix) || strlen($raw) > 16384) {
            throw new InvalidArgumentException('Staging inbound metadata or scope rejected.');
        }
        $suffix = substr($topic, strlen($prefix));

        return DB::transaction(function () use ($identity, $device, $topic, $raw, $suffix) {
            Coop::whereKey($device->coop_id)->lockForUpdate()->firstOrFail();
            $locked = $device->newQuery()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $identity->authorize($locked);
            if (in_array($suffix, ['telemetry', 'status'], true)) {
                return app(TelemetryIngestor::class)->ingest($topic, $raw, false);
            }
            if (! in_array($suffix, ['integration/v1/session', 'integration/v1/ack', 'integration/v1/report'], true)) {
                throw new InvalidArgumentException('Device publication is outside staging ACL.');
            }
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $kind = substr($suffix, strlen('integration/v1/'));
            app(ContractSchema::class)->validate($kind === 'session' ? 'session-proposal' : $kind, $payload);
            if ($payload['coop_id'] !== $device->coop_id || $payload['device_identifier'] !== $device->device_identifier
                || $payload['kind'] !== $kind) {
                throw new InvalidArgumentException('Authenticated device does not match payload.');
            }
            if ($kind !== 'session') {
                return app(DeviceFeedback::class)->ingest($locked, $topic, $payload, false, $identity);
            }
            $sent = ContractTime::parse($payload['sent_at']);
            if ($sent->isFuture() || $sent->lt(now()->subSeconds(max(1, config('integration.report_seconds'))))) {
                throw new InvalidArgumentException('Stale or future session.');
            }
            $hash = hash('sha256', CanonicalPayload::encode($payload));
            $receipt = DB::table('integration_receipts')->where('device_id', $device->id)->where('message_id', $payload['message_id'])->first();
            if ($receipt) {
                $current = DB::table('integration_sessions')->where('device_id', $device->id)->where('coop_id', $device->coop_id)
                    ->where('boot_id', $payload['boot_id'])->where('current', true)->exists();
                if (! $current || ! hash_equals($receipt->payload_hash, $hash)) {
                    throw new InvalidArgumentException('Session collision or retired boot replay.');
                }

                return 'duplicate'; // A duplicate must not refresh online time.
            }
            $current = DB::table('integration_sessions')->where('device_id', $device->id)->where('current', true)->first();
            if ($current && $current->boot_id !== $payload['boot_id'] && $sent->lt($current->created_at)) {
                throw new InvalidArgumentException('Session predates current boot.');
            }
            app(DeviceFeedback::class)->openSession($locked, $payload['boot_id'], $identity);
            DB::table('integration_receipts')->insert(['device_id' => $device->id, 'message_id' => $payload['message_id'],
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);

            return 'accepted';
        }, 3);
    }
}
