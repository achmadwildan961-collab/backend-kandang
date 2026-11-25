<?php

namespace Tests\Feature;

use App\Services\Firmware\ContractSchema;
use App\Services\Firmware\VirtualDevice;
use App\Services\HumidityContract;
use App\Services\Integration\CanonicalPayload;
use App\Services\Integration\ConfigurationDelivery;
use App\Services\Integration\ConfigurationDispatcher;
use App\Services\Integration\DeviceFeedback;
use App\Services\Integration\FakeMqttTransport;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\LcdPayload;
use App\Services\Integration\MqttTransport;
use App\Services\Mqtt\PayloadValidator;
use App\Services\Mqtt\TelemetryIngestor;
use App\Services\SensorCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\ApiTestCase;

class FirmwareReadinessTest extends ApiTestCase
{
    private function virtual(): VirtualDevice
    {
        $this->device->update(['device_identifier' => 'VIRTUAL_INDOOR']);
        config(['integration.simulation' => true, 'mqtt.device_sensors.VIRTUAL_INDOOR' => [...SensorCatalog::indoorIdentifiers(), ...HumidityContract::indoorIdentifiers(), 'gas_adc']]);

        return new VirtualDevice($this->coop->id, 'VIRTUAL_INDOOR', 'esp32');
    }

    private function deliver(VirtualDevice $v): array
    {
        $fake = new FakeMqttTransport;
        $this->app->instance(MqttTransport::class, $fake);
        app(DeviceFeedback::class)->openSession($this->device, $v->bootId);
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->coop, 'operational'));
        $row = DB::table('integration_deliveries')->where('device_id', $this->device->id)->orderByDesc('id')->first();
        $this->assertSame('published', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $lock = json_decode(file_get_contents(base_path('contracts/firmware/contract-lock.json')), true);
        $this->assertSame($v->topic(ltrim($lock['topics']['operational']['suffix'], '/')), $fake->messages[0]['topic']);

        return $fake->messages[0]['payload'];
    }

    private function feedback(VirtualDevice $v, array $payload): string
    {
        return app(DeviceFeedback::class)->ingest($this->device, $v->topic('integration/v1/'.$payload['kind']), $payload);
    }

    public function test_lock_schemas_fixtures_and_catalog_match_real_contract(): void
    {
        $this->artisan('firmware:contract-check')->assertSuccessful();
        $lock = json_decode(file_get_contents(base_path('contracts/firmware/contract-lock.json')), true);
        $this->assertFalse($lock['hardware_approved']);
        $this->assertSame('phase8_draft', $lock['integration_contract']);
        foreach ($lock['sensors'] as $id => $spec) {
            $this->assertSame($spec, array_intersect_key(SensorCatalog::series()[$id], $spec));
        }
        foreach (['telemetry-indoor', 'telemetry-outdoor', 'status'] as $name) {
            $bytes = file_get_contents(base_path("contracts/firmware/fixtures/$name.json"));
            // Fixture time is fixed for review. Move the test clock, never rewrite fixtures.
            $this->travelTo(now()->setDate(2026, 11, 25)->setTime(8, 0));
            $result = app(PayloadValidator::class)->validate($bytes, $name === 'status' ? 'status' : 'telemetry');
            $this->assertNotEmpty($result['message_id']);
        }
    }

    public function test_schema_rejects_drift_unknown_fields_wrong_types_and_unsupported_keywords(): void
    {
        $payload = json_decode(file_get_contents(base_path('contracts/firmware/fixtures/report.json')), true);
        foreach ([['states' => ['heater' => true]], ['schema_version' => 2], ['sequence' => '1'], ['extra' => true]] as $change) {
            try {
                app(ContractSchema::class)->validate('report', array_replace($payload, $change));
                $this->fail('Wire drift accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        app(ContractSchema::class)->check(['unimplementedKeyword' => true], []);
    }

    public function test_virtual_config_ack_apply_report_reboot_and_lost_ack_recovery(): void
    {
        $v = $this->virtual();
        $wire = $this->deliver($v);
        app(ContractSchema::class)->validate('config-operational', $wire);
        $ack = $v->receiveConfiguration($wire);
        app(ContractSchema::class)->validate('ack', $ack);
        $this->assertSame('accepted', $this->feedback($v, $ack));
        $this->assertFalse(app(IntegrationMonitor::class)->deliveries($this->coop)['deliveries'][0]['applied_current']);
        $applied = $v->applied('operational');
        $this->assertSame('accepted', $this->feedback($v, $applied));
        $this->assertSame('duplicate', $this->feedback($v, $applied));
        $this->assertTrue(app(IntegrationMonitor::class)->deliveries($this->coop)['deliveries'][0]['applied_current']);
        $report = $v->report(['ventilation_fan_1' => true]);
        app(ContractSchema::class)->validate('report', $report);
        $this->feedback($v, $report);
        $this->assertFalse(app(IntegrationMonitor::class)->reports($this->coop)[0]['physical_verified']);
        $oldBoot = $v->bootId;
        $v->reboot();
        $this->assertNotSame($oldBoot, $v->bootId);
        app(DeviceFeedback::class)->openSession($this->device, $v->bootId);
        $this->assertNull(app(IntegrationMonitor::class)->reports($this->coop)[0]['states']['ventilation_fan_1']);
        $this->assertSame('pending', DB::table('integration_deliveries')->first()->status);
        app(ConfigurationDispatcher::class)->run();
        $this->feedback($v, $v->receiveConfiguration($wire));
        $this->feedback($v, $v->applied('operational'));
        $this->assertTrue(app(IntegrationMonitor::class)->deliveries($this->coop)['deliveries'][0]['applied_current']);
        $this->expectException(InvalidArgumentException::class);
        $this->feedback($v, $applied); // Retired boot cannot reassert current application.
    }

    public function test_virtual_config_rejects_hash_scope_version_expiry_and_collision(): void
    {
        $v = $this->virtual();
        $wire = $this->deliver($v);
        $v->receiveConfiguration($wire);
        foreach ([['payload_hash' => str_repeat('0', 64)], ['coop_id' => $this->otherCoop->id], ['config_version' => 0],
            ['expires_at' => now()->subSecond()->toISOString()], ['settings' => ['bad' => true]]] as $change) {
            $candidate = array_replace($wire, $change);
            if (! isset($change['payload_hash'])) {
                unset($candidate['payload_hash']);
                $candidate['payload_hash'] = hash('sha256', CanonicalPayload::encode($candidate));
            }
            try {
                $v->receiveConfiguration($candidate);
                $this->fail('Invalid virtual configuration accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $collision = $wire;
        unset($collision['payload_hash']);
        $collision['settings']['gas_threshold'] = 2000;
        $collision['payload_hash'] = hash('sha256', CanonicalPayload::encode($collision));
        $this->expectException(InvalidArgumentException::class);
        $v->receiveConfiguration($collision);
    }

    public function test_virtual_rejection_is_terminal_and_new_version_can_recover(): void
    {
        $v = $this->virtual();
        $wire = $this->deliver($v);
        $this->feedback($v, $v->receiveConfiguration($wire, true));
        $this->assertDatabaseHas('integration_deliveries', ['status' => 'failed', 'reason' => 'device_rejected']);
        $this->coop->settings()->increment('config_version');
        $next = $this->deliver($v);
        $this->feedback($v, $v->receiveConfiguration($next));
        $this->feedback($v, $v->applied('operational'));
        $this->assertDatabaseHas('integration_deliveries', ['config_version' => 2, 'status' => 'applied']);
        $this->expectException(InvalidArgumentException::class);
        $v->receiveConfiguration($wire);
    }

    public function test_virtual_indoor_telemetry_partial_duplicate_out_of_order_stale_and_disconnect(): void
    {
        $v = $this->virtual();
        $ingestor = app(TelemetryIngestor::class);
        $wire = json_encode($v->telemetry());
        $this->assertSame('accepted', $ingestor->ingest($v->topic('telemetry'), $wire));
        $this->assertSame('duplicate', $ingestor->ingest($v->topic('telemetry'), $wire));
        $this->assertDatabaseCount('sensor_readings', 11);
        $ingestor->ingest($v->topic('telemetry'), json_encode($v->telemetry('out-of-order')));
        $ingestor->ingest($v->topic('telemetry'), json_encode($v->telemetry('stale')));
        $this->travel(1)->seconds();
        $ingestor->ingest($v->topic('telemetry'), json_encode($v->telemetry('partial')));
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest?schema_version=2')->assertOk()
            ->assertJsonPath('data.indoor_temperature.complete', true)->assertJsonPath('data.indoor_humidity.complete', false);
        $this->travel(1)->seconds();
        $ingestor->ingest($v->topic('status'), json_encode($v->status(false)));
        $this->assertSame('offline', $this->device->fresh()->connection_status);
        $this->travel(1)->seconds();
        $ingestor->ingest($v->topic('status'), json_encode($v->status(true)));
        $this->assertSame('online', $this->device->fresh()->connection_status);
    }

    public function test_virtual_spoofed_coop_unknown_device_and_invalid_unit_are_rejected(): void
    {
        $v = $this->virtual();
        foreach ([[$v->topic('telemetry'), $v->telemetry('invalid-unit')],
            [str_replace('/coops/'.$this->coop->id.'/', '/coops/'.$this->otherCoop->id.'/', $v->topic('telemetry')), $v->telemetry()],
            [str_replace('VIRTUAL_INDOOR', 'VIRTUAL_UNKNOWN', $v->topic('telemetry')), $v->telemetry()]] as [$topic, $data]) {
            try {
                app(TelemetryIngestor::class)->ingest($topic, json_encode($data));
                $this->fail('Spoof accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sensor_readings', 0);
            }
        }
    }

    public function test_virtual_outdoor_receives_config_and_lcd_without_relay_states(): void
    {
        $this->device->update(['device_identifier' => 'VIRTUAL_OUTDOOR', 'type' => 'nodemcu_esp8266']);
        $v = new VirtualDevice($this->coop->id, 'VIRTUAL_OUTDOOR', 'nodemcu_esp8266');
        config(['integration.simulation' => true, 'mqtt.device_sensors.VIRTUAL_OUTDOOR' => ['outdoor_temperature', 'outdoor_humidity', 'outdoor_light_lux'],
            'lighting.bh1750.telemetry_verified' => true, 'lighting.bh1750.device_identifiers' => ['VIRTUAL_OUTDOOR']]);
        app(TelemetryIngestor::class)->ingest($v->topic('telemetry'), json_encode($v->telemetry()));
        $wire = $this->deliver($v);
        $this->feedback($v, $v->receiveConfiguration($wire));
        $this->feedback($v, $v->applied('operational'));
        $lcd = app(LcdPayload::class)->generate($this->coop);
        app(ContractSchema::class)->validate('display', $lcd);
        $fields = $v->receiveDisplay($lcd, $this->device->id);
        $this->assertSame(70.0, $fields['outdoor_humidity']['value']);
        $this->assertSame(120.0, $fields['outdoor_light_lux']['value']);
        $this->assertNull($fields['indoor_humidity']['value']);
        $this->travel(31)->seconds();
        $this->expectException(InvalidArgumentException::class);
        $v->receiveDisplay($lcd, $this->device->id);
    }

    public function test_crashed_claim_recovers_same_immutable_payload_after_lease_expires(): void
    {
        $v = $this->virtual();
        $wire = $this->deliver($v);
        DB::table('integration_deliveries')->update(['lease_token' => 'simulated-crash', 'lease_until' => now()->addSeconds(5), 'next_attempt_at' => now()]);
        $row = DB::table('integration_deliveries')->first();
        $this->assertSame('skipped', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $this->travel(6)->seconds();
        $this->assertSame('published', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $fake = app(MqttTransport::class);
        $this->assertSame($wire, $fake->messages[1]['payload']);
        $this->feedback($v, $v->receiveConfiguration($wire));
        $this->feedback($v, $v->applied('operational'));
        $this->assertDatabaseHas('integration_deliveries', ['attempts' => 2, 'status' => 'applied']);
    }

    public function test_preview_cli_is_database_and_network_free_and_production_blocked(): void
    {
        $this->artisan('firmware:simulate indoor')->expectsOutputToContain('indoor_dht22_5_humidity')->assertSuccessful();
        $this->artisan('firmware:simulate outdoor')->expectsOutputToContain('outdoor_light_lux')->assertSuccessful();
        $this->assertDatabaseCount('sensor_readings', 0);
        $this->app['env'] = 'production';
        $this->artisan('firmware:simulate indoor')->assertFailed();
        $this->artisan('firmware:broker-smoke')->assertFailed();
    }

    public function test_lighting_wire_uses_independent_version_and_virtual_manual_mode_without_commands(): void
    {
        $v = $this->virtual();
        app(DeviceFeedback::class)->openSession($this->device, $v->bootId);
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $this->coop->id, 'lighting_mode' => 'manual',
            'min_lux' => null, 'max_lux' => null, 'config_version' => 1, 'updated_at' => now()]);
        $fake = new FakeMqttTransport;
        $this->app->instance(MqttTransport::class, $fake);
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->coop, 'lighting_lux'));
        $row = DB::table('integration_deliveries')->first();
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $wire = $fake->messages[0]['payload'];
        app(ContractSchema::class)->validate('config-lighting-lux', $wire);
        $this->feedback($v, $v->receiveConfiguration($wire));
        $this->feedback($v, $v->applied('lighting_lux'));
        $this->assertSame('manual', $wire['settings']['lighting_mode']);
        $this->assertDatabaseHas('integration_deliveries', ['domain' => 'lighting_lux', 'config_version' => 1, 'status' => 'applied']);
        $this->assertDatabaseCount('actuator_reports', 0);
        $this->asUser($this->admin)->postJson('/api/v1/coops/'.$this->coop->id.'/actuator/commands', [])->assertConflict();
    }
}
