<?php

namespace Tests\Feature;

use App\Services\AlertEvaluationService;
use App\Services\Mqtt\TelemetryIngestor;
use App\Services\SensorAggregationService;
use App\Services\SensorCatalog;
use App\Services\SensorHistoryService;
use Tests\ApiTestCase;

class SensorBatchContractTest extends ApiTestCase
{
    private function ingest(string $message, array $values, $time = null, $device = null): void
    {
        $device ??= $this->device;
        $payload = ['message_id' => $message, 'sent_at' => ($time ?? now())->format('Y-m-d\TH:i:s.u\Z'),
            'readings' => array_map(fn ($id, $value) => ['sensor_identifier' => $id, 'value' => $value], array_keys($values), array_values($values))];
        app(TelemetryIngestor::class)->ingest('smart-kandang/coops/'.$this->coop->id.'/devices/'.$device->device_identifier.'/telemetry', json_encode($payload));
    }

    private function snapshot(): array
    {
        return app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
    }

    private function chartCount(): int
    {
        return app(SensorHistoryService::class)->chart($this->coop, now()->toImmutable()->subHour(), now()->toImmutable(), 3600, 100)['summary']['indoor_sample_count'];
    }

    public function test_five_fresh_but_asynchronous_readings_never_form_a_batch(): void
    {
        foreach (SensorCatalog::indoorIdentifiers() as $i => $id) {
            $this->ingest('partial-'.$i, [$id => 40], now()->subSeconds($i));
        }
        $data = $this->snapshot();
        $this->assertSame(5, $data['valid_count']);
        $this->assertSame(40.0, $data['partial_average']);
        $this->assertNull($data['average']);
        $this->assertSame('inconsistent_batch', $data['unavailable_reason']);
        $this->assertSame(0, $this->chartCount());
        $this->assertDatabaseCount('system_events', 0);
        $this->assertDatabaseCount('sensor_readings', 5);
    }

    public function test_same_timestamp_split_messages_are_not_one_accepted_batch(): void
    {
        $values = array_fill_keys(SensorCatalog::indoorIdentifiers(), 40);
        $this->ingest('one', array_slice($values, 0, 2));
        $this->ingest('two', array_slice($values, 2));
        $this->assertNull($this->snapshot()['average']);
        $this->assertSame(0, $this->chartCount());
        $this->assertDatabaseCount('system_events', 0);
    }

    public function test_same_timestamp_same_message_id_across_devices_cannot_form_one_batch(): void
    {
        $other = $this->coop->devices()->create(['device_identifier' => 'OTHER', 'name' => 'Other', 'type' => 'esp32']);
        $values = array_fill_keys(SensorCatalog::indoorIdentifiers(), 40);
        $this->ingest('same', array_slice($values, 0, 2));
        $this->ingest('same', array_slice($values, 2), device: $other);
        $this->assertNull($this->snapshot()['average']);
        $this->assertSame(0, $this->chartCount());
    }

    public function test_new_partial_invalidates_latest_without_erasing_complete_history_or_recovering_alert(): void
    {
        $this->ingest('complete', array_fill_keys(SensorCatalog::indoorIdentifiers(), 40), now()->subSecond());
        $this->assertSame('mqtt_v1_message', $this->snapshot()['batch_provenance']);
        $this->ingest('partial', ['indoor_dht22_1' => 20]);
        $this->assertNull($this->snapshot()['average']);
        $this->assertSame(1, $this->chartCount());
        app(AlertEvaluationService::class)->evaluate($this->coop);
        $this->assertDatabaseCount('system_events', 1);
    }

    public function test_out_of_order_complete_batch_is_history_only_and_stale_batch_is_unavailable(): void
    {
        $this->ingest('new', array_fill_keys(SensorCatalog::indoorIdentifiers(), 28));
        $this->ingest('old', array_fill_keys(SensorCatalog::indoorIdentifiers(), 40), now()->subSecond());
        $this->assertSame(28.0, $this->snapshot()['average']);
        $this->assertSame(2, $this->chartCount());
        $this->assertDatabaseCount('system_events', 0);
        $this->travel(301)->seconds();
        $this->assertNull($this->snapshot()['average']);
    }

    public function test_legacy_rows_require_same_device_and_timestamp_and_expose_provenance(): void
    {
        $this->readings();
        $this->assertSame('legacy_exact_timestamp', $this->snapshot()['batch_provenance']);
        $other = $this->coop->devices()->create(['device_identifier' => 'LEGACY_OTHER', 'name' => 'Other', 'type' => 'esp32']);
        $this->coop->readings()->where('sensor_identifier', 'indoor_dht22_5')->update(['device_id' => $other->id]);
        $this->assertNull($this->snapshot()['average']);
        $this->assertSame(0, $this->chartCount());
    }
}
