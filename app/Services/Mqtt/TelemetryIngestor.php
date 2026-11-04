<?php

namespace App\Services\Mqtt;

use App\Models\Coop;
use App\Models\Device;
use App\Services\AlertEvaluationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TelemetryIngestor
{
    public function __construct(private DeviceResolver $devices, private PayloadValidator $validator, private AlertEvaluationService $alerts) {}

    public function ingest(string $topic, string $payload, bool $retained = false): string
    {
        // Retained snapshots must never impersonate a current heartbeat on reconnect.
        if ($retained) {
            throw new InvalidArgumentException('Retained messages are not accepted.');
        }
        [$device, $kind] = $this->devices->resolve($topic);
        $data = $this->validator->validate($payload, $kind);
        $key = hash('sha256', $data['message_id']);
        $hash = hash('sha256', $topic."\n".$payload);

        return DB::transaction(function () use ($device, $kind, $data, $key, $hash) {
            // Same lock order as HTTP settings/device updates and alert evaluation.
            $coop = Coop::whereKey($device->coop_id)->lockForUpdate()->firstOrFail();
            $locked = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($locked->coop_id !== $coop->id || $locked->device_identifier !== $device->device_identifier) {
                throw new InvalidArgumentException('Device mapping changed.');
            }
            $receipt = DB::table('mqtt_receipts')->where('device_id', $locked->id)->where('message_key', $key)->first();
            if ($receipt) {
                if ($receipt->payload_hash !== $hash) {
                    throw new InvalidArgumentException('Message ID reused with different content.');
                }

                return 'duplicate';
            }
            if ($kind === 'telemetry') {
                $timestamp = $data['sent_at']->format('Y-m-d H:i:s.u');
                // Never overwrite an existing sample or silently accept partial batches.
                if ($coop->readings()->where('recorded_at', $timestamp)->whereIn('sensor_identifier', array_column($data['readings'], 'sensor_identifier'))->exists()) {
                    throw new InvalidArgumentException('Sensor timestamp already exists.');
                }
                $rows = array_map(fn ($r) => $r + ['device_id' => $locked->id, 'coop_id' => $coop->id, 'recorded_at' => $timestamp], $data['readings']);
                DB::table('sensor_readings')->insert($rows);
            }
            $previousSent = DB::table('mqtt_receipts')->where('device_id', $locked->id)->max('sent_at');
            DB::table('mqtt_receipts')->insert(['device_id' => $locked->id, 'message_id' => $data['message_id'], 'message_key' => $key, 'payload_hash' => $hash, 'received_at' => now()->format('Y-m-d H:i:s.u'), 'sent_at' => $data['sent_at']->format('Y-m-d H:i:s.u')]);
            // Use sample time (capped at receipt time), not arrival time: queued old
            // messages cannot make an offline device appear freshly connected.
            $seen = $data['sent_at']->min(now());
            if ((! $previousSent || $data['sent_at']->gt($previousSent)) && (! $locked->last_seen_at || $seen->gte($locked->last_seen_at))) {
                $fresh = $seen->gte(now()->subSeconds(max(1, config('mqtt.offline_after_seconds'))));
                $locked->update(['last_seen_at' => $seen, 'connection_status' => $fresh ? ($data['status'] ?? 'online') : 'offline']);
            }
            $this->alerts->evaluate($coop);

            return 'accepted';
        }, 3);
    }
}
