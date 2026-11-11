<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\SensorReading;
use App\Models\SystemEvent;
use App\Services\AlertEvaluationService;
use App\Services\Mqtt\CommitAwareMqttClient;
use App\Services\Mqtt\DeviceStatusService;
use App\Services\Mqtt\TelemetryIngestor;
use App\Services\SensorAggregationService;
use App\Services\SensorCatalog;
use InvalidArgumentException;
use PhpMqtt\Client\Message;
use PhpMqtt\Client\MessageType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class MqttIngestionTest extends ApiTestCase
{
    public function test_configured_sensor_mapping_supports_two_device_types_in_one_coop(): void
    {
        $node = $this->coop->devices()->create(['device_identifier' => 'NODEMCU_TEST', 'name' => 'NodeMCU', 'type' => 'nodemcu']);
        config(['mqtt.device_sensors' => [
            'ESP32_DEVICE_A' => SensorCatalog::indoorIdentifiers(),
            'NODEMCU_TEST' => ['outdoor_temperature', 'gas_adc'],
        ]]);
        $this->assertSame('accepted', $this->ingest($this->payload()));
        $topic = 'smart-kandang/coops/'.$this->coop->id.'/devices/'.$node->device_identifier.'/telemetry';
        $payload = $this->payload('node-1', null, ['outdoor_temperature' => 25, 'gas_adc' => 800]);
        $this->assertSame('accepted', app(TelemetryIngestor::class)->ingest($topic, json_encode($payload)));
        $this->assertSame('duplicate', app(TelemetryIngestor::class)->ingest($topic, json_encode($payload)));
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')->assertOk()
            ->assertJsonPath('data.indoor_temperature.average', 30.38)
            ->assertJsonPath('data.outdoor_temperature.value', 25);
        $this->expectException(InvalidArgumentException::class);
        app(TelemetryIngestor::class)->ingest($topic, json_encode($this->payload('wrong-device')));
    }

    private function topic(string $kind = 'telemetry'): string
    {
        return 'smart-kandang/coops/'.$this->coop->id.'/devices/ESP32_DEVICE_A/'.$kind;
    }

    private function payload(string $id = 'test-1', ?string $time = null, ?array $values = null): array
    {
        $values ??= array_combine(SensorCatalog::indoorIdentifiers(), [28, 29.2, 31, 33.5, 30.2]);

        return ['message_id' => $id, 'sent_at' => $time ?? now()->format('Y-m-d\TH:i:s.u\Z'),
            'readings' => array_map(fn ($key, $value) => ['sensor_identifier' => $key, 'value' => $value], array_keys($values), array_values($values))];
    }

    private function ingest(array $data, string $kind = 'telemetry'): string
    {
        return app(TelemetryIngestor::class)->ingest($this->topic($kind), json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function reject(string $payload, ?string $topic = null, bool $retained = false): void
    {
        try {
            app(TelemetryIngestor::class)->ingest($topic ?? $this->topic(), $payload, $retained);
            $this->fail('Expected invalid MQTT message to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('sensor_readings', 0);
            $this->assertDatabaseCount('mqtt_receipts', 0);
        }
    }

    public function test_valid_message_reuses_api_average_and_individual_sensors(): void
    {
        $this->assertSame('accepted', $this->ingest($this->payload()));
        $this->assertDatabaseCount('sensor_readings', 5);
        $this->assertDatabaseCount('mqtt_receipts', 1);
        $this->assertSame('online', $this->device->fresh()->connection_status);
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')
            ->assertOk()->assertJsonPath('data.indoor_temperature.average', 30.38)
            ->assertJsonPath('data.indoor_temperature.complete', true)
            ->assertJsonCount(5, 'data.indoor_temperature.sensors');
        $this->assertSame(1, SystemEvent::where('event_type', 'high_indoor_temperature')->count());
    }

    public function test_mock_mqtt_delivery_reaches_database_before_ack_and_redelivery_is_idempotent(): void
    {
        $client = new class('unused.invalid') extends CommitAwareMqttClient
        {
            public array $rowsAtAck = [];

            public function deliver(Message $message): void
            {
                $this->handleMessage($message);
            }

            protected function sendPublishAcknowledgement(int $messageId): void
            {
                $this->rowsAtAck[] = SensorReading::count();
            }
        };
        $client->receiveUsing(fn ($topic, $payload, $retained) => app(TelemetryIngestor::class)->ingest($topic, $payload, $retained));
        $message = (new Message(MessageType::PUBLISH(), 1))->setMessageId(42)->setTopic($this->topic())->setContent(json_encode($this->payload()));
        $client->deliver($message);
        $client->deliver($message);
        $this->assertSame([5, 5], $client->rowsAtAck);
        $this->assertDatabaseCount('mqtt_receipts', 1);
        $this->assertSame(30.38, app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature']['average']);
    }

    public function test_duplicate_delivery_is_noop_and_does_not_extend_last_seen(): void
    {
        $data = $this->payload();
        $this->ingest($data);
        $lastSeen = $this->device->fresh()->last_seen_at;
        $this->travel(180)->seconds();
        app(DeviceStatusService::class)->check();
        $this->assertSame('duplicate', $this->ingest($data));
        $this->assertDatabaseCount('sensor_readings', 5);
        $this->assertDatabaseCount('mqtt_receipts', 1);
        $this->assertTrue($lastSeen->eq($this->device->fresh()->last_seen_at));
        $this->assertSame('offline', $this->device->fresh()->connection_status);
        $this->assertSame(1, SystemEvent::where('event_type', 'high_indoor_temperature')->count());
    }

    public function test_reused_id_with_changed_content_and_timestamp_collision_preserve_existing_samples(): void
    {
        $data = $this->payload();
        $this->ingest($data);
        foreach ([$this->payload('test-1', values: ['gas_adc' => 1000]), $this->payload('another-id')] as $conflict) {
            try {
                $this->ingest($conflict);
                $this->fail('Expected conflict.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sensor_readings', 5);
                $this->assertDatabaseCount('mqtt_receipts', 1);
            }
        }
    }

    public function test_ids_are_case_sensitive_and_scoped_to_device(): void
    {
        $this->ingest($this->payload('Case', values: ['gas_adc' => 1000]));
        $this->ingest($this->payload('case', values: ['outdoor_temperature' => 28]));
        $device = $this->coop->devices()->create(['device_identifier' => 'ESP32_DEVICE_B', 'name' => 'B', 'type' => 'esp32']);
        $topic = str_replace('ESP32_DEVICE_A', $device->device_identifier, $this->topic());
        app(TelemetryIngestor::class)->ingest($topic, json_encode($this->payload('Case', values: ['indoor_humidity' => 70])));
        $this->assertDatabaseCount('mqtt_receipts', 3);
    }

    public function test_unknown_wrong_coop_case_mismatch_and_malformed_topics_are_rejected(): void
    {
        foreach ([str_replace('ESP32_DEVICE_A', 'UNKNOWN', $this->topic()), str_replace('/coops/'.$this->coop->id.'/', '/coops/'.$this->otherCoop->id.'/', $this->topic()), str_replace('ESP32_DEVICE_A', 'esp32_device_a', $this->topic()), $this->topic().'/extra'] as $topic) {
            $this->reject(json_encode($this->payload()), $topic);
        }
    }

    public static function invalidPayloads(): array
    {
        $base = ['message_id' => 'x', 'sent_at' => '2026-11-03T08:00:00Z', 'readings' => [['sensor_identifier' => 'gas_adc', 'value' => 1000]]];
        $cases = ['malformed' => '{', 'scalar' => 'true', 'oversized' => str_repeat('x', 16385), 'nan' => str_replace('1000', 'NaN', json_encode($base)), 'infinite exponent' => str_replace('1000', '1e999', json_encode($base))];
        foreach (['null' => null, 'string' => '29', 'boolean' => true, 'range' => 4096, 'negative' => -1] as $name => $value) {
            $data = $base;
            $data['readings'][0]['value'] = $value;
            $cases[$name] = json_encode($data);
        }
        foreach (['future' => '2026-11-03T08:05:00Z', 'bad calendar' => '2026-02-30T08:00:00Z', 'no timezone' => '2026-11-03T08:00:00', 'mysql range' => '1960-01-01T00:00:00Z'] as $name => $time) {
            $cases[$name] = json_encode(array_replace($base, ['sent_at' => $time]));
        }
        $cases['duplicate sensor'] = json_encode(array_replace($base, ['readings' => [$base['readings'][0], $base['readings'][0]]]));
        $cases['unknown sensor'] = json_encode(array_replace($base, ['readings' => [['sensor_identifier' => 'average', 'value' => 28]]]));
        $cases['empty readings'] = json_encode(array_replace($base, ['readings' => []]));
        $cases['missing id'] = json_encode(array_diff_key($base, ['message_id' => true]));
        $cases['bad id'] = json_encode(array_replace($base, ['message_id' => 'bad id']));
        $cases['payload spoofing'] = json_encode($base + ['coop_id' => 999]);

        return array_map(fn ($payload) => [$payload], $cases);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_atomic(string $payload): void
    {
        $this->reject($payload);
    }

    public function test_retained_message_rejected(): void
    {
        $this->reject(json_encode($this->payload()), retained: true);
    }

    public function test_partial_and_stale_messages_never_fabricate_a_complete_average(): void
    {
        $this->device->update(['last_seen_at' => null, 'connection_status' => 'unknown']);
        $this->ingest($this->payload('old', now()->subMinutes(10)->toIso8601ZuluString()));
        $data = app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
        $this->assertNull($data['average']);
        $this->assertCount(5, $data['stale_sensors']);
        $this->assertSame('offline', $this->device->fresh()->connection_status);
        $this->ingest($this->payload('partial', values: ['indoor_dht22_1' => 40]));
        $data = app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
        $this->assertNull($data['average']);
        $this->assertSame(1, $data['valid_count']);
        $this->assertSame(0, SystemEvent::where('event_type', 'high_indoor_temperature')->count());
    }

    public function test_out_of_order_samples_preserve_latest_and_microseconds(): void
    {
        $this->ingest($this->payload('new', '2026-11-03T07:59:59.900000Z', ['gas_adc' => 1000]));
        $this->ingest($this->payload('old', '2026-11-03T07:59:59.100000Z', ['gas_adc' => 3000]));
        $this->assertDatabaseCount('sensor_readings', 2);
        $this->assertSame(1000.0, app(SensorAggregationService::class)->latest($this->coop)['gas']['value']);
        $this->assertSame(0, SystemEvent::where('event_type', 'high_gas_level')->count());
        $query = http_build_query(['from' => '2026-11-03T07:59:59.050000Z', 'to' => '2026-11-03T07:59:59.950000Z']);
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?'.$query)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_all_threshold_transitions_recovery_and_notification_preferences(): void
    {
        $values = array_fill_keys(SensorCatalog::indoorIdentifiers(), 35) + ['outdoor_temperature' => 20, 'gas_adc' => 2500];
        $this->ingest($this->payload('warning-1', values: $values));
        $this->travel(1)->seconds();
        $this->ingest($this->payload('warning-2', values: $values));
        $this->assertDatabaseCount('system_events', 3);
        $this->assertDatabaseCount('notifications', 6);
        $this->travel(1)->seconds();
        $this->ingest($this->payload('normal', values: array_fill_keys(SensorCatalog::indoorIdentifiers(), 28) + ['outdoor_temperature' => 28, 'gas_adc' => 1000]));
        $this->assertSame(3, SystemEvent::where('event_type', 'sensor_recovered')->count());
        $this->coop->settings()->update(['notification_preferences' => ['push_notifications' => false]]);
        $count = Notification::count();
        $this->travel(1)->seconds();
        $this->ingest($this->payload('warning-3', values: $values));
        $this->assertSame($count, Notification::count());
        $this->assertDatabaseCount('system_events', 9);
    }

    public function test_heartbeat_offline_and_recovery_events_are_deduplicated(): void
    {
        $this->travel(121)->seconds();
        $this->artisan('mqtt:check-devices')->expectsOutput('Device status changes: 1')->assertSuccessful();
        $this->artisan('mqtt:check-devices')->expectsOutput('Device status changes: 0')->assertSuccessful();
        $this->ingest(['message_id' => 'heartbeat-1', 'sent_at' => now()->toIso8601ZuluString(), 'status' => 'online'], 'status');
        $this->assertSame('online', $this->device->fresh()->connection_status);
        $this->assertTrue($this->device->fresh()->last_seen_at->eq(now()));
        $this->assertSame(['device_disconnected', 'device_reconnected'], SystemEvent::orderBy('id')->pluck('event_type')->all());
        $this->assertDatabaseCount('sensor_readings', 0);
    }

    public function test_old_and_equal_timestamp_status_does_not_override_newer_status(): void
    {
        $this->device->update(['last_seen_at' => null]);
        foreach ([['new', '2026-11-03T07:59:59.900000Z', 'offline'], ['old', '2026-11-03T07:59:59.100000Z', 'online'], ['equal', '2026-11-03T07:59:59.900000Z', 'online']] as [$id, $time, $status]) {
            $this->ingest(['message_id' => $id, 'sent_at' => $time, 'status' => $status], 'status');
        }
        $this->assertSame('offline', $this->device->fresh()->connection_status);
        $this->assertSame(1, SystemEvent::where('event_type', 'device_disconnected')->count());
        $this->assertSame(0, SystemEvent::where('event_type', 'device_reconnected')->count());
    }

    public function test_unknown_device_without_last_seen_is_not_inferred_online(): void
    {
        $this->device->update(['last_seen_at' => null, 'connection_status' => 'online']);
        $this->readings();
        app(DeviceStatusService::class)->check();
        $this->assertSame('unknown', $this->device->fresh()->connection_status);
    }

    public function test_database_failure_rolls_back_readings_receipt_and_last_seen(): void
    {
        $this->device->update(['last_seen_at' => null, 'connection_status' => 'unknown']);
        $this->mock(AlertEvaluationService::class)->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Simulated persistence failure'));
        try {
            $this->ingest($this->payload());
            $this->fail('Expected database failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('sensor_readings', 0);
            $this->assertDatabaseCount('mqtt_receipts', 0);
            $this->assertNull($this->device->fresh()->last_seen_at);
        }
    }

    public function test_local_simulator_uses_real_ingestion_and_can_repeat_exact_message(): void
    {
        $this->artisan('mqtt:simulate', ['coop' => $this->coop->id, 'device' => 'ESP32_DEVICE_A', '--scenario' => 'sample', '--transport' => 'local', '--repeat' => 2])
            ->expectsOutput('Local ingestion: accepted')->expectsOutput('Local ingestion: duplicate')->assertSuccessful();
        $this->assertDatabaseCount('sensor_readings', 9);
        $this->assertDatabaseCount('mqtt_receipts', 1);
        $this->assertSame(30.38, app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature']['average']);
    }
}
