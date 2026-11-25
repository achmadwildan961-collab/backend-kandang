<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\Bh1750Telemetry;
use App\Services\LightingContract;
use App\Services\Mqtt\TelemetryIngestor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\ApiTestCase;

class Bh1750TelemetryTest extends ApiTestCase
{
    private Device $outdoor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outdoor = $this->coop->devices()->create(['device_identifier' => 'OUTDOOR_BH1750_TEST', 'name' => 'Outdoor NodeMCU', 'type' => 'nodemcu_esp8266', 'connection_status' => 'online']);
        config(['lighting.bh1750.telemetry_verified' => true, 'lighting.bh1750.device_identifiers' => [$this->outdoor->device_identifier]]);
    }

    private function payload(mixed $value, string $message = 'lux-1', array $extra = []): string
    {
        return json_encode(['message_id' => $message, 'sent_at' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'readings' => [['sensor_identifier' => 'outdoor_light_lux', 'value' => $value] + $extra]], JSON_THROW_ON_ERROR);
    }

    private function ingest(string $payload, ?Device $device = null, bool $retained = false): string
    {
        $device ??= $this->outdoor;

        return app(TelemetryIngestor::class)->ingest('smart-kandang/coops/'.$this->coop->id.'/devices/'.$device->device_identifier.'/telemetry', $payload, $retained);
    }

    public function test_valid_lux_reuses_v1_dedup_snapshot_history_chart_and_decimal_precision(): void
    {
        $payload = $this->payload(123.45, extra: ['unit' => 'lux']);
        $this->assertSame('accepted', $this->ingest($payload));
        $this->assertSame('duplicate', $this->ingest($payload));
        $this->assertDatabaseCount('mqtt_receipts', 1);
        $this->assertDatabaseHas('sensor_readings', ['sensor_identifier' => 'outdoor_light_lux', 'unit' => 'lux',
            'measurement_version' => LightingContract::BH1750_MEASUREMENT_VERSION, 'device_id' => $this->outdoor->id]);
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')->assertOk()
            ->assertJsonPath('data.outdoor_light_lux.value', 123.45)->assertJsonPath('data.outdoor_light_lux.sensor_model', 'BH1750')
            ->assertJsonPath('data.outdoor_light_lux.valid', true)->assertJsonPath('data.outdoor_light_lux.device_id', $this->outdoor->id);
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?sensor_identifier=outdoor_light_lux')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit', 'lux')->assertJsonPath('data.0.sensor_model', 'BH1750');
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/chart')->assertOk()->assertJsonPath('summary.outdoor_light_lux', 123.45);
    }

    public function test_zero_and_normal_contract_upper_boundary_are_valid(): void
    {
        foreach ([0, 65535] as $value) {
            $this->travel(1)->seconds();
            $this->assertSame('accepted', $this->ingest($this->payload($value, 'boundary-'.$value)));
            $this->assertSame((float) $value, app(Bh1750Telemetry::class)->latest($this->coop)['value']);
        }
    }

    public function test_negative_nonfinite_null_malformed_wrong_unit_and_out_of_range_are_atomic(): void
    {
        $payloads = array_map(fn ($value) => $this->payload($value), [-0.01, 65535.01, 100000, null, true, '120']);
        $payloads[] = str_replace('"value":120', '"value":NaN', $this->payload(120));
        $payloads[] = str_replace('"value":120', '"value":1e999', $this->payload(120));
        $payloads[] = $this->payload(120, extra: ['unit' => 'adc']);
        $payloads[] = $this->payload(120, extra: ['unit' => null]);
        $payloads[] = $this->payload(120, extra: ['sensor_type' => 'indoor_temperature']);
        $payloads[] = '{';
        foreach ($payloads as $payload) {
            try {
                $this->ingest($payload);
                $this->fail('Invalid BH1750 payload accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sensor_readings', 0);
                $this->assertDatabaseCount('mqtt_receipts', 0);
            }
        }
    }

    public function test_wrong_mapping_indoor_board_wrong_coop_unverified_and_retained_are_rejected(): void
    {
        foreach (['unverified', 'unmapped', 'indoor', 'wrong_coop', 'retained', 'general_allowlist'] as $case) {
            config(['lighting.bh1750.telemetry_verified' => $case !== 'unverified',
                'lighting.bh1750.device_identifiers' => $case === 'unmapped' ? [] : [$this->outdoor->device_identifier, $this->device->device_identifier],
                'mqtt.device_sensors' => $case === 'general_allowlist' ? [$this->outdoor->device_identifier => ['outdoor_temperature']] : []]);
            $target = $case === 'indoor' ? $this->device : $this->outdoor;
            if ($case === 'wrong_coop') {
                $target = $this->otherCoop->devices()->create(['device_identifier' => 'FOREIGN', 'name' => 'Foreign', 'type' => 'nodemcu_esp8266']);
            }
            try {
                $this->ingest($this->payload(200), $target, $case === 'retained');
                $this->fail('Unapproved BH1750 source accepted: '.$case);
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sensor_readings', 0);
                $this->assertDatabaseCount('mqtt_receipts', 0);
            }
        }
    }

    public function test_adc_history_is_never_lux_and_remains_queryable_with_legacy_gate_off(): void
    {
        DB::table('sensor_readings')->insert(['coop_id' => $this->coop->id, 'device_id' => $this->outdoor->id,
            'sensor_identifier' => 'outdoor_light_adc', 'sensor_type' => 'outdoor_light', 'unit' => 'adc', 'value' => 900,
            'measurement_version' => 'legacy-ldr', 'recorded_at' => now()]);
        $this->assertSame('missing', app(Bh1750Telemetry::class)->latest($this->coop)['status']);
        $this->ingest($this->payload(25));
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?sensor_identifier=outdoor_light_adc')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.value', 900)->assertJsonPath('data.0.unit', 'adc')->assertJsonPath('data.0.legacy', true);
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/chart')->assertOk()->assertJsonPath('summary.outdoor_light_lux', 25);
    }

    public function test_missing_stale_invalid_future_and_binding_revocation_fail_closed(): void
    {
        $service = app(Bh1750Telemetry::class);
        $this->assertSame('missing', $service->latest($this->coop)['status']);
        $this->ingest($this->payload(20));
        $this->travel(301)->seconds();
        $this->assertSame('stale', $service->latest($this->coop)['status']);
        $this->assertFalse($service->latest($this->coop)['valid']);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_light_lux')->update(['unit' => 'adc']);
        $this->assertSame('invalid', $service->latest($this->coop)['status']);
        $this->assertNull($service->latest($this->coop)['value']);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_light_lux')->update(['unit' => 'lux', 'recorded_at' => now()->addSecond()]);
        $this->assertSame('invalid', $service->latest($this->coop)['status']);
        config(['lighting.bh1750.device_identifiers' => []]);
        $this->assertSame('unsupported', $service->latest($this->coop)['status']);
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/chart')->assertJsonPath('summary.outdoor_light_lux', null);
    }

    public function test_lighting_v2_and_latest_share_source_policy_and_out_of_order_does_not_replace_newer_lux(): void
    {
        $this->ingest($this->payload(50));
        $older = json_decode($this->payload(10, 'older'), true);
        $older['sent_at'] = now()->subMinute()->format('Y-m-d\TH:i:s.u\Z');
        $this->assertSame('accepted', $this->ingest(json_encode($older)));
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/lighting?schema_version=2')
            ->assertOk()->assertJsonPath('data.measurement.value', 50)->assertJsonPath('data.measurement.device_id', $this->outdoor->id);
        config(['lighting.bh1750.device_identifiers' => [strtolower($this->outdoor->device_identifier)]]);
        $this->assertSame('unsupported', app(Bh1750Telemetry::class)->latest($this->coop)['status']);
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/chart')->assertJsonPath('summary.outdoor_light_lux', null);
        config(['lighting.bh1750.device_identifiers' => [$this->outdoor->device_identifier],
            'mqtt.device_sensors' => [$this->outdoor->device_identifier => ['outdoor_temperature']]]);
        $this->assertSame('unsupported', app(Bh1750Telemetry::class)->latest($this->coop)['status']);
        $this->assertDatabaseCount('sensor_readings', 2); // Historical evidence preserved on revocation.
    }
}
