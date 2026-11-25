<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\HumidityAggregation;
use App\Services\HumidityContract;
use App\Services\Integration\LcdPayload;
use App\Services\Mqtt\TelemetryIngestor;
use App\Services\SensorCatalog;
use App\Services\SensorHistoryService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\ApiTestCase;

class HumidityContractTest extends ApiTestCase
{
    private Device $outdoor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outdoor = $this->coop->devices()->create(['device_identifier' => 'OUTDOOR_AHT20', 'name' => 'Outdoor', 'type' => 'nodemcu_esp8266']);
        config(['mqtt.device_sensors' => [
            $this->device->device_identifier => [...SensorCatalog::indoorIdentifiers(), ...HumidityContract::indoorIdentifiers(), 'gas_adc', 'indoor_humidity'],
            $this->outdoor->device_identifier => ['outdoor_temperature', 'outdoor_humidity', 'outdoor_light_lux'],
        ]]);
    }

    private function values(float $value = 60): array
    {
        return array_fill_keys(HumidityContract::indoorIdentifiers(), $value);
    }

    private function payload(array $values, string $message = 'humidity-1', $time = null): string
    {
        return json_encode(['message_id' => $message, 'sent_at' => ($time ?? now())->format('Y-m-d\TH:i:s.u\Z'),
            'readings' => array_map(fn ($id, $value) => ['sensor_identifier' => $id, 'value' => $value], array_keys($values), array_values($values))], JSON_THROW_ON_ERROR);
    }

    private function ingest(string $payload, ?Device $device = null, bool $retained = false): string
    {
        $device ??= $this->device;

        return app(TelemetryIngestor::class)->ingest('smart-kandang/coops/'.$this->coop->id.'/devices/'.$device->device_identifier.'/telemetry', $payload, $retained);
    }

    private function humidity(): array
    {
        return app(HumidityAggregation::class)->indoor($this->coop);
    }

    private function chart(int $version = 2): array
    {
        return app(SensorHistoryService::class)->chart($this->coop, now()->toImmutable()->subDay(), now()->toImmutable(), 3600, 100, schemaVersion: $version);
    }

    private function uri(string $endpoint, array $query = []): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/sensors/'.$endpoint.'?'.http_build_query($query + ['schema_version' => 2]);
    }

    private function reject(string $payload, ?Device $device = null, bool $retained = false): void
    {
        $before = DB::table('sensor_readings')->count();
        $receipts = DB::table('mqtt_receipts')->count();
        try {
            $this->ingest($payload, $device, $retained);
            $this->fail('Invalid telemetry accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('sensor_readings', $before);
            $this->assertDatabaseCount('mqtt_receipts', $receipts);
        }
    }

    public function test_complete_humidity_is_independent_from_temperature_and_legacy(): void
    {
        $values = array_combine(HumidityContract::indoorIdentifiers(), [50, 60, 70, 80, 90]);
        $this->ingest($this->payload($values + ['indoor_humidity' => 99]));
        $h = $this->humidity();
        $this->assertSame(70.0, $h['average']);
        $this->assertSame(5, $h['valid_count']);
        $this->assertTrue($h['complete']);
        $this->assertSame($this->device->id, $h['device_id']);
        $this->assertSame('mqtt_v1_message', $h['batch_provenance']);
        $this->assertSame('indoor_dht22_1', $h['sensors'][0]['paired_temperature_identifier']);
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertOk()
            ->assertJsonPath('data.indoor_humidity.average', 70)->assertJsonPath('data.indoor_humidity.unit_symbol', '%')
            ->assertJsonPath('data.legacy_indoor_humidity.value', 99)->assertJsonPath('data.indoor_temperature.complete', false);
        $this->getJson($this->uri('latest', ['schema_version' => 1]))->assertOk()
            ->assertJsonPath('data.indoor_humidity.value', 99)->assertJsonPath('data.indoor_humidity.legacy', true)
            ->assertJsonPath('data.indoor_humidity_aggregate.average', 70);
        $this->assertDatabaseHas('sensor_readings', ['sensor_identifier' => 'indoor_dht22_5_humidity', 'measurement_version' => HumidityContract::DHT22_VERSION, 'unit' => 'percent']);
    }

    public function test_complete_temperature_never_completes_partial_humidity(): void
    {
        $this->ingest($this->payload(array_fill_keys(SensorCatalog::indoorIdentifiers(), 28) + array_slice($this->values(), 0, 3)));
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertOk()
            ->assertJsonPath('data.indoor_temperature.complete', true)->assertJsonPath('data.indoor_humidity.complete', false)
            ->assertJsonPath('data.indoor_humidity.valid_count', 3)->assertJsonPath('data.indoor_humidity.average', null)
            ->assertJsonPath('data.indoor_humidity.partial_average', 60)->assertJsonCount(2, 'data.indoor_humidity.missing_sensors');
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_split_messages_and_asynchronous_samples_never_form_official_or_partial_average(): void
    {
        $this->ingest($this->payload(array_slice($this->values(), 0, 2), 'one'));
        $this->ingest($this->payload(array_slice($this->values(), 2), 'two'));
        $this->assertSame(5, $this->humidity()['valid_count']);
        $this->assertNull($this->humidity()['average']);
        $this->assertNull($this->humidity()['partial_average']);
        $this->assertSame('inconsistent_batch', $this->humidity()['unavailable_reason']);
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
        foreach (HumidityContract::indoorIdentifiers() as $i => $id) {
            $this->ingest($this->payload([$id => 40], 'async-'.$i, now()->subSeconds($i + 1)));
        }
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_devices_cannot_complete_each_others_batch_even_with_same_message_id(): void
    {
        $other = $this->coop->devices()->create(['device_identifier' => 'OTHER_INDOOR', 'name' => 'Other', 'type' => 'esp32']);
        config(['mqtt.device_sensors.OTHER_INDOOR' => HumidityContract::indoorIdentifiers()]);
        $this->ingest($this->payload(array_slice($this->values(), 0, 2)));
        $this->ingest($this->payload(array_slice($this->values(), 2)), $other);
        $this->assertNull($this->humidity()['average']);
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_out_of_order_samples_are_history_only_and_stale_values_are_unknown(): void
    {
        $this->ingest($this->payload($this->values(70), 'new'));
        $this->ingest($this->payload($this->values(20), 'old', now()->subSecond()));
        $this->assertSame(70.0, $this->humidity()['average']);
        $this->assertSame(2, $this->chart()['summary']['indoor_humidity_sample_count']);
        $this->assertSame(45.0, $this->chart()['summary']['indoor_humidity']);
        $this->travel(301)->seconds();
        $this->assertNull($this->humidity()['average']);
        $this->assertCount(5, $this->humidity()['stale_sensors']);
        $this->assertNull($this->humidity()['sensors'][0]['value']);
        $this->assertSame(70.0, $this->humidity()['sensors'][0]['diagnostic_value']);
        $this->assertSame(2, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_duplicate_replay_timestamp_collision_and_retained_are_protected(): void
    {
        $payload = $this->payload($this->values());
        $this->assertSame('accepted', $this->ingest($payload));
        $this->assertSame('duplicate', $this->ingest($payload));
        $this->reject($this->payload($this->values(80)));
        $this->reject($this->payload($this->values(), 'different-message'));
        $this->reject($payload, retained: true);
        $this->assertDatabaseCount('sensor_readings', 5);
        $this->assertDatabaseCount('mqtt_receipts', 1);
    }

    public function test_invalid_values_units_duplicate_channels_and_nonfinite_json_are_atomic(): void
    {
        $id = HumidityContract::indoorIdentifiers()[0];
        foreach ([-0.01, 100.01, null, true, '60'] as $value) {
            $this->reject($this->payload([$id => $value]));
        }
        foreach (['NaN', '1e999'] as $invalid) {
            $this->reject(str_replace('"value":60', '"value":'.$invalid, $this->payload([$id => 60])));
        }
        $payload = json_decode($this->payload([$id => 60]), true);
        $payload['readings'][0]['unit'] = 'adc';
        $this->reject(json_encode($payload));
        unset($payload['readings'][0]['unit']);
        $payload['readings'][] = $payload['readings'][0];
        $this->reject(json_encode($payload));
    }

    public function test_percent_alias_and_zero_hundred_boundaries_are_valid_without_conversion(): void
    {
        foreach ([0, 100] as $value) {
            $payload = json_decode($this->payload($this->values($value), 'boundary-'.$value), true);
            foreach ($payload['readings'] as &$reading) {
                $reading['unit'] = $value === 0 ? '%' : 'percent';
            }
            unset($reading);
            $this->ingest(json_encode($payload));
            $this->assertSame((float) $value, $this->humidity()['average']);
            $this->travel(1)->seconds();
        }
    }

    public function test_new_channels_require_explicit_mapping_correct_board_and_coop(): void
    {
        $mapping = config('mqtt.device_sensors');
        config(['mqtt.device_sensors' => []]);
        $this->reject($this->payload($this->values()));
        config(['mqtt.device_sensors' => $mapping]);
        $this->device->update(['type' => 'nodemcu_esp8266']);
        $this->reject($this->payload($this->values()));
        $this->device->update(['type' => 'ESP32']);
        $this->reject($this->payload($this->values()));
        $this->device->update(['type' => 'esp32', 'coop_id' => $this->otherCoop->id]);
        $this->reject($this->payload($this->values()));
    }

    public function test_revoked_mapping_and_unproven_rows_cannot_be_aggregated(): void
    {
        $this->ingest($this->payload($this->values()));
        $mapping = config('mqtt.device_sensors');
        config(['mqtt.device_sensors' => []]);
        $this->assertNull($this->humidity()['average']);
        $this->assertCount(5, $this->humidity()['unverified_sensors']);
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
        config(['mqtt.device_sensors' => $mapping]);
        DB::table('sensor_readings')->update(['batch_key' => null]);
        $this->assertNull($this->humidity()['average']);
        $this->assertSame(0, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_new_invalid_or_future_sample_cannot_expose_previous_official_average(): void
    {
        $this->ingest($this->payload($this->values(), time: now()->subSecond()));
        $this->reading('indoor_dht22_1_humidity', 101);
        $this->assertNull($this->humidity()['average']);
        $this->assertContains('indoor_dht22_1_humidity', $this->humidity()['invalid_sensors']);
        $this->reading('indoor_dht22_2_humidity', 60, now()->addSecond());
        $this->assertContains('indoor_dht22_2_humidity', $this->humidity()['invalid_sensors']);
        $this->assertSame(1, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_aht20_outdoor_is_independent_fresh_and_explicitly_mapped(): void
    {
        $this->ingest($this->payload($this->values(40)));
        $payload = json_decode($this->payload(['outdoor_humidity' => 85, 'outdoor_temperature' => 29], 'outdoor'), true);
        $payload['readings'][0]['unit'] = '%';
        $this->ingest(json_encode($payload), $this->outdoor);
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertOk()
            ->assertJsonPath('data.indoor_humidity.average', 40)->assertJsonPath('data.outdoor_humidity.value', 85)
            ->assertJsonPath('data.outdoor_humidity.sensor_model', 'AHT20')->assertJsonPath('data.outdoor_humidity.source_verified', true)
            ->assertJsonPath('data.outdoor_temperature.value', 29)->assertJsonPath('data.outdoor_temperature.measurement_version', 'aht20_v1');
        $lcd = app(LcdPayload::class)->generate($this->coop);
        $this->assertSame(40.0, $lcd['fields']['indoor_humidity']['value']);
        $this->assertSame(85.0, $lcd['fields']['outdoor_humidity']['value']);
        $this->assertFalse($lcd['publication_enabled']);
        $this->travel(301)->seconds();
        $this->assertNull(app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['value']);
        $this->assertSame('stale', app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['status']);
    }

    public function test_nodemcu_label_and_legacy_humidity_never_claim_verified_aht20_or_five_dht22(): void
    {
        config(['mqtt.device_sensors' => []]);
        $this->ingest($this->payload(['outdoor_humidity' => 80], 'old-outdoor'), $this->outdoor);
        $this->ingest($this->payload(['indoor_humidity' => 70], 'old-indoor'));
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertOk()
            ->assertJsonPath('data.outdoor_humidity.value', null)->assertJsonPath('data.outdoor_humidity.sensor_model', null)
            ->assertJsonPath('data.outdoor_humidity.status', 'unverified_source')->assertJsonPath('data.indoor_humidity.average', null)
            ->assertJsonPath('data.legacy_indoor_humidity.value', 70)->assertJsonPath('data.legacy_outdoor_humidity.value', 80);
        config(['mqtt.device_sensors' => [$this->outdoor->device_identifier => ['outdoor_humidity']]]);
        $this->assertNull(app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['value']);
        $this->assertDatabaseHas('sensor_readings', ['sensor_identifier' => 'outdoor_humidity', 'value' => 80, 'measurement_version' => null]);
        $this->getJson($this->uri('history', ['sensor_identifier' => 'indoor_humidity']))->assertOk()
            ->assertJsonPath('data.0.legacy', true)->assertJsonPath('data.0.aggregation_eligible', false);
    }

    public function test_outdoor_invalid_wrong_unit_wrong_mapping_and_retained_remain_rejected(): void
    {
        foreach ([-1, 101, null] as $value) {
            $this->reject($this->payload(['outdoor_humidity' => $value]), $this->outdoor);
        }
        $payload = json_decode($this->payload(['outdoor_humidity' => 80]), true);
        $payload['readings'][0]['unit'] = 'celsius';
        $this->reject(json_encode($payload), $this->outdoor);
        $this->reject($this->payload(['outdoor_humidity' => 80]), $this->device);
        $this->reject($this->payload(['outdoor_humidity' => 80]), $this->outdoor, true);
    }

    public function test_history_chart_csv_versioning_and_pagination_keep_complete_batches(): void
    {
        config(['lighting.bh1750.telemetry_verified' => true, 'lighting.bh1750.device_identifiers' => [$this->outdoor->device_identifier]]);
        $this->ingest($this->payload($this->values(60) + array_fill_keys(SensorCatalog::indoorIdentifiers(), 28) + ['gas_adc' => 1200, 'indoor_humidity' => 99]));
        $this->ingest($this->payload(['outdoor_humidity' => 80, 'outdoor_temperature' => 27, 'outdoor_light_lux' => 123.45], 'outdoor'), $this->outdoor);
        $this->ingest($this->payload($this->values(20), 'old', now()->subHour()));
        $this->asUser($this->user)->getJson($this->uri('history', ['sensor_identifier' => 'indoor_dht22_1_humidity', 'per_page' => 1]))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.measurement_version', 'dht22_humidity_v1');
        $this->getJson($this->uri('chart', ['from' => now()->subHours(2)->toISOString(), 'interval_seconds' => 3600, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('summary.indoor_humidity', 40)->assertJsonPath('summary.indoor_humidity_sample_count', 2)
            ->assertJsonPath('summary.outdoor_humidity', 80)->assertJsonPath('summary.legacy_indoor_humidity', 99);
        $this->getJson($this->uri('chart', ['schema_version' => 1]))->assertOk()
            ->assertJsonPath('summary.indoor_humidity', 99)->assertJsonPath('summary.indoor_humidity_average', 40);
        $csv = $this->getJson($this->uri('export', ['from' => now()->toISOString()]))->assertOk()->assertHeader('X-Schema-Version', '2')->getContent();
        $lines = explode("\r\n", substr($csv, 3));
        $header = str_getcsv($lines[0], ',', '"', '');
        $this->assertSame('Indoor Average Humidity (%)', $header[3]);
        $this->assertSame('Outdoor Humidity (%)', $header[4]);
        $this->assertSame(['2026-11-03T08:00:00.000000Z', '28', '27', '60', '80', '1200', '123.45', '1', '1', '99'], str_getcsv($lines[1], ',', '"', ''));
        $legacy = $this->getJson($this->uri('export', ['schema_version' => 1, 'from' => now()->toISOString()]))->assertOk()->getContent();
        $this->assertSame(['2026-11-03T08:00:00.000000Z', '28', '27', '99', '1200'], str_getcsv(explode("\r\n", $legacy)[1], ',', '"', ''));
    }

    public function test_partial_csv_is_empty_not_zero_and_historical_legacy_remains_separate(): void
    {
        $this->ingest($this->payload(array_slice($this->values(), 0, 4) + ['indoor_humidity' => 70]));
        $csv = $this->asUser($this->user)->getJson($this->uri('export'))->assertOk()->getContent();
        $row = str_getcsv(explode("\r\n", $csv)[1], ',', '"', '');
        $this->assertSame('', $row[3]);
        $this->assertSame('', $row[4]);
        $this->assertSame('0', $row[8]);
        $this->assertSame('70', $row[9]);
    }

    public function test_rbac_multicoop_and_schema_validation_are_preserved(): void
    {
        $this->ingest($this->payload($this->values()));
        foreach (['latest', 'chart', 'export', 'history'] as $endpoint) {
            $this->asUser($this->outsider)->getJson($this->uri($endpoint))->assertForbidden();
            $this->asUser($this->admin)->getJson(str_replace('/coops/'.$this->coop->id.'/', '/coops/'.$this->otherCoop->id.'/', $this->uri($endpoint)))->assertOk();
        }
        foreach (['latest', 'chart', 'export'] as $endpoint) {
            $this->asUser($this->user)->getJson($this->uri($endpoint, ['schema_version' => 3]))->assertUnprocessable();
        }
        $this->asUser($this->admin)->getJson('/api/v1/coops/'.$this->otherCoop->id.'/sensors/latest?schema_version=2')->assertOk()
            ->assertJsonPath('data.indoor_humidity.average', null)->assertJsonPath('data.indoor_humidity.valid_count', 0);
        $this->user->update(['status' => 'inactive']);
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertForbidden();
    }

    public function test_future_samples_are_not_official_even_when_history_range_includes_the_future(): void
    {
        $this->ingest($this->payload($this->values(), time: now()->addSeconds(10)));
        $this->ingest($this->payload(['outdoor_humidity' => 80, 'outdoor_temperature' => 28], 'outdoor-future', now()->addSeconds(10)), $this->outdoor);
        $this->assertNull($this->humidity()['average']);
        $this->asUser($this->user)->getJson($this->uri('chart', ['from' => now()->toISOString(), 'to' => now()->addMinute()->toISOString()]))->assertOk()
            ->assertJsonPath('summary.indoor_humidity', null)->assertJsonPath('summary.indoor_humidity_sample_count', 0)
            ->assertJsonPath('summary.outdoor_humidity', null)->assertJsonPath('summary.outdoor_temperature', null);
        $this->travel(10)->seconds();
        $this->assertSame(60.0, $this->humidity()['average']);
        $this->assertSame(1, $this->chart()['summary']['indoor_humidity_sample_count']);
    }

    public function test_schema_two_and_lcd_reject_wrong_temperature_and_gas_sources_without_changing_legacy(): void
    {
        $this->readings();
        $this->device->update(['type' => 'nodemcu_esp8266']);
        $this->asUser($this->user)->getJson($this->uri('latest'))->assertOk()
            ->assertJsonPath('data.indoor_temperature.average', null)->assertJsonPath('data.indoor_temperature.unavailable_reason', 'unverified_source')
            ->assertJsonPath('data.gas.value', null)->assertJsonPath('data.outdoor_temperature.value', null);
        $this->getJson($this->uri('latest', ['schema_version' => 1]))->assertOk()
            ->assertJsonPath('data.indoor_temperature.average', 30.38)->assertJsonPath('data.gas.value', 1200);
        $this->assertNull($this->chart()['summary']['indoor_average']);
        $this->assertNull($this->chart()['summary']['gas_adc']);
        $lcd = app(LcdPayload::class)->generate($this->coop);
        foreach (['indoor_temperature', 'outdoor_temperature', 'gas', 'indoor_humidity', 'outdoor_humidity'] as $id) {
            $this->assertNull($lcd['fields'][$id]['value']);
        }
    }

    public function test_aht20_mapping_revocation_wrong_board_and_invalid_latest_are_explicit(): void
    {
        $this->ingest($this->payload(['outdoor_humidity' => 80], 'outdoor'), $this->outdoor);
        $this->outdoor->update(['type' => 'esp32']);
        $this->assertSame('unverified_source', app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['status']);
        $this->assertNull($this->chart()['summary']['outdoor_humidity']);
        $this->outdoor->update(['type' => 'nodemcu_esp8266']);
        config(['mqtt.device_sensors.OUTDOOR_AHT20' => ['outdoor_temperature']]);
        $this->assertNull(app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['value']);
        config(['mqtt.device_sensors.OUTDOOR_AHT20' => ['outdoor_humidity']]);
        $this->travel(1)->seconds();
        $this->reading('outdoor_humidity', 101);
        $this->assertSame('invalid', app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['status']);
        $this->assertNull(app(HumidityAggregation::class)->channel($this->coop, 'outdoor_humidity')['value']);
    }
}
