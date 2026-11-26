<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\Firmware\StagingIdentity;
use App\Services\Firmware\StagingIngress;
use App\Services\Firmware\StagingMqttClient;
use App\Services\Firmware\StagingMqttFactory;
use App\Services\Firmware\StagingMqttTransport;
use App\Services\HumidityContract;
use App\Services\Integration\ConfigurationDelivery;
use App\Services\Integration\ConfigurationDispatcher;
use App\Services\Integration\DisabledMqttTransport;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\LcdPayload;
use App\Services\Integration\MqttTransport;
use App\Services\SensorCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\ApiTestCase;

class FirmwareStagingIntegrationTest extends ApiTestCase
{
    private Device $outdoor;

    private StagingIdentity $identity;

    private StagingMqttTransport $transport;

    private array $published = [];

    private string $boot = '12345678-1234-4234-8234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->device->update(['device_identifier' => 'VIRTUAL_INDOOR']);
        $this->outdoor = $this->coop->devices()->create(['device_identifier' => 'VIRTUAL_OUTDOOR', 'name' => 'Outdoor', 'type' => 'nodemcu_esp8266']);
        config(['integration.simulation' => false, 'firmware.staging.enabled' => true,
            'lighting.bh1750.telemetry_verified' => true, 'lighting.bh1750.device_identifiers' => ['VIRTUAL_OUTDOOR'],
            'firmware.staging.virtual_only' => true, 'firmware.staging.integration_enabled' => true,
            'firmware.staging.username' => 'virtual-backend', 'firmware.staging.password' => 'test-backend-only',
            'firmware.staging.retained_authorized' => true,
            'firmware.staging.scopes' => ['VIRTUAL_INDOOR' => $this->coop->id, 'VIRTUAL_OUTDOOR' => $this->coop->id],
            'firmware.staging.device_password_hashes' => [
                'VIRTUAL_INDOOR' => password_hash('test-indoor-only', PASSWORD_BCRYPT, ['cost' => 4]),
                'VIRTUAL_OUTDOOR' => password_hash('test-outdoor-only', PASSWORD_BCRYPT, ['cost' => 4]),
            ], 'mqtt.device_sensors' => [
                'VIRTUAL_INDOOR' => [...SensorCatalog::indoorIdentifiers(), ...HumidityContract::indoorIdentifiers(), 'gas_adc'],
                'VIRTUAL_OUTDOOR' => ['outdoor_temperature', 'outdoor_humidity', 'outdoor_light_lux'],
            ]]);
        $this->coop->settings()->update(['minimum_indoor_temperature' => 20, 'maximum_indoor_temperature' => 30, 'gas_threshold' => 1500]);
        $this->identity = StagingIdentity::authenticate('VIRTUAL_INDOOR', 'test-indoor-only');
        // Exercise the real bounded staging transport; only broker IO is mocked.
        $client = Mockery::mock(StagingMqttClient::class);
        $client->shouldReceive('connect')->andReturnNull();
        $client->shouldReceive('publish')->andReturnUsing(function ($topic, $json, $qos, $retained) {
            $this->published[] = compact('topic', 'json', 'qos', 'retained');
        });
        $client->shouldReceive('loop')->andReturnNull();
        $client->shouldReceive('brokerAcknowledged')->andReturnTrue();
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('disconnect')->andReturnNull();
        $factory = Mockery::mock(StagingMqttFactory::class)->makePartial();
        $factory->shouldReceive('make')->andReturn($client);
        $this->transport = new StagingMqttTransport($factory);
    }

    private function native(array $input): array
    {
        $runner = getenv('POULTRIQ_NATIVE_RUNNER') ?: base_path('.runtime/phase8-3-tools/native_runner.exe');
        if (! is_file($runner)) {
            $this->markTestSkipped('Build firmware/tests/native_runner.cpp and set POULTRIQ_NATIVE_RUNNER; native integration NOT VERIFIED.');
        }
        $process = new Process([$runner], base_path(), input: json_encode($input, JSON_THROW_ON_ERROR)."\n", timeout: 30);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok'], $process->getOutput());

        return $result;
    }

    private function topic(string $suffix, ?StagingIdentity $identity = null): string
    {
        $identity ??= $this->identity;

        return "smart-kandang/coops/{$identity->coopId}/devices/{$identity->identifier}/$suffix";
    }

    private function sessionEnvelope(?string $boot = null): array
    {
        return ['schema_version' => 1, 'kind' => 'session', 'message_id' => (string) Str::uuid(),
            'coop_id' => $this->coop->id, 'device_identifier' => 'VIRTUAL_INDOOR', 'boot_id' => $boot ?? $this->boot,
            'sent_at' => now()->format('Y-m-d\TH:i:s\Z')];
    }

    private function ingest(array $payload, string $suffix, bool $retained = false): string
    {
        return app(StagingIngress::class)->ingest($this->identity, $this->topic($suffix), json_encode($payload, JSON_THROW_ON_ERROR), $retained);
    }

    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsafe staging operation accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function stage(string $domain = 'operational'): object
    {
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->coop, $domain, $this->identity));

        return DB::table('integration_deliveries')->where('device_id', $this->device->id)->where('domain', $domain)->orderByDesc('id')->first();
    }

    private function dispatch(object $row): string
    {
        return (new ConfigurationDispatcher($this->transport, app(ConfigurationDelivery::class)))->dispatch($row->id, $this->identity);
    }

    public function test_authentication_scope_revocation_and_production_gates(): void
    {
        $this->assertInstanceOf(DisabledMqttTransport::class, app(MqttTransport::class));
        $this->reject(fn () => StagingIdentity::authenticate('VIRTUAL_INDOOR', 'test-outdoor-only'));
        $this->reject(fn () => StagingIdentity::authenticate('UNKNOWN', 'test-indoor-only'));
        $this->reject(fn () => $this->identity->authorize($this->outdoor));
        $this->reject(fn () => DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->otherCoop, 'operational', $this->identity)));
        $this->reject(fn () => app(StagingIngress::class)->ingest($this->identity, 'smart-kandang/#', '{}', false));
        $this->reject(fn () => $this->ingest($this->sessionEnvelope(), 'integration/v1/actuator/set'));
        config(['firmware.staging.device_password_hashes.VIRTUAL_INDOOR' => 'revoked']);
        $this->reject(fn () => $this->identity->device());
        config(['firmware.staging.integration_enabled' => false]);
        $this->reject(fn () => StagingIdentity::authenticate('VIRTUAL_OUTDOOR', 'test-outdoor-only'));
        config(['firmware.staging.integration_enabled' => true]);
        $this->app['env'] = 'production';
        $this->reject(fn () => StagingIdentity::authenticate('VIRTUAL_OUTDOOR', 'test-outdoor-only'));
        $this->assertSame([], $this->published);
        $this->assertDatabaseCount('integration_sessions', 0);
    }

    public function test_actual_cpp_telemetry_ingestion_six_metrics_and_lcd_roundtrip(): void
    {
        $inside = $this->native(['mode' => 'telemetry', 'now' => now()->timestamp, 'values' => [25, 26, 27, 28, 29, 50, 60, 70, 80, 90, 1200]]);
        $this->assertSame('accepted', app(StagingIngress::class)->ingest($this->identity, $this->topic('telemetry'), $inside['wire'], false));
        $this->assertSame('duplicate', app(StagingIngress::class)->ingest($this->identity, $this->topic('telemetry'), $inside['wire'], false));
        $outdoor = StagingIdentity::authenticate('VIRTUAL_OUTDOOR', 'test-outdoor-only');
        $outside = $this->native(['mode' => 'telemetry', 'board' => 'outdoor', 'now' => now()->timestamp, 'values' => [28, 65, 100]]);
        $this->assertSame('accepted', app(StagingIngress::class)->ingest($outdoor, $this->topic('telemetry', $outdoor), $outside['wire'], false));
        $this->reject(fn () => app(StagingIngress::class)->ingest($this->identity, $this->topic('telemetry', $outdoor), $outside['wire'], false));
        $this->reject(fn () => app(StagingIngress::class)->ingest($this->identity, $this->topic('telemetry'), $inside['wire'], true));
        $this->assertDatabaseCount('sensor_readings', 14);
        $this->asUser($this->user)->getJson("/api/v1/coops/{$this->coop->id}/sensors/latest?schema_version=2")->assertOk()
            ->assertJsonPath('data.indoor_temperature.average', 27)->assertJsonPath('data.indoor_humidity.average', 70)
            ->assertJsonPath('data.outdoor_temperature.value', 28)->assertJsonPath('data.outdoor_humidity.value', 65)
            ->assertJsonPath('data.gas.value', 1200)->assertJsonPath('data.outdoor_light_lux.value', 100);
        $lcd = app(LcdPayload::class)->generate($this->coop);
        $this->transport->publishDisplay($outdoor, $lcd);
        $this->assertFalse($this->published[0]['retained']);
        $this->assertSame(1, $this->published[0]['qos']);
        $parsed = $this->native(['mode' => 'display', 'raw' => $this->published[0]['json'], 'coop' => $this->coop->id,
            'registry' => $this->outdoor->id, 'indoor' => $this->device->id, 'now_us' => now()->timestamp * 1000000]);
        $this->assertEquals([27, 28, 70, 65, 1200, 100], $parsed['values']);
        $this->assertSame([null, null, null, null], $parsed['actuators']);
        $this->assertFalse($lcd['publication_enabled']);
        $this->asUser($this->outsider)->getJson("/api/v1/coops/{$this->coop->id}/sensors/latest?schema_version=2")->assertForbidden();
    }

    public function test_native_partial_invalid_humidity_never_becomes_verified_average(): void
    {
        $wire = $this->native(['mode' => 'telemetry', 'now' => now()->timestamp, 'values' => [25, 26, 27, 28, 29, 50, null, 101, 80, 90, null]]);
        $this->assertSame('accepted', $this->ingest($wire['payload'], 'telemetry'));
        $this->assertDatabaseCount('sensor_readings', 8);
        $this->asUser($this->user)->getJson("/api/v1/coops/{$this->coop->id}/sensors/latest?schema_version=2")->assertOk()
            ->assertJsonPath('data.indoor_temperature.average', 27)->assertJsonPath('data.indoor_humidity.average', null)
            ->assertJsonPath('data.indoor_humidity.valid_count', 3);
        $this->assertNull(app(LcdPayload::class)->generate($this->coop)['fields']['indoor_humidity']['value']);
    }

    public function test_native_configuration_acknowledgement_and_report_are_not_applied_or_physical_feedback(): void
    {
        $this->assertSame('accepted', $this->ingest($this->sessionEnvelope(), 'integration/v1/session'));
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $this->coop->id, 'lighting_mode' => 'auto',
            'min_lux' => 10, 'max_lux' => 100, 'threshold_unit' => 'lux', 'measurement_version' => 'bh1750_lux_v1',
            'config_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['operational', 'lighting_lux'] as $i => $domain) {
            $row = $this->stage($domain);
            $this->stage($domain);
            $this->assertSame('pending', $row->status);
            $this->assertSame('blocked', app(ConfigurationDispatcher::class)->dispatch($row->id));
            $this->assertSame('published', $this->dispatch($row));
            $this->assertTrue($this->published[$i]['retained']);
            $native = $this->native(['mode' => 'config', 'steps' => [$this->published[$i]['json']], 'light' => $i === 1,
                'coop' => $this->coop->id, 'device' => $this->identity->identifier, 'boot' => $this->boot,
                'sequence' => $i + 1, 'message_id' => (string) Str::uuid(), 'now_us' => now()->timestamp * 1000000]);
            $this->assertSame([true], $native['accepted']);
            $this->assertSame(1, $native['writes']);
            $this->assertSame('accepted', $this->ingest($native['payload'], 'integration/v1/ack'));
            $this->assertSame('duplicate', $this->ingest($native['payload'], 'integration/v1/ack'));
            $this->reject(fn () => $this->ingest($native['payload'], 'integration/v1/ack', true));
            $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'acknowledged', 'applied_at' => null]);
        }
        $this->assertDatabaseCount('integration_deliveries', 2); // Device-specific staging does not stage Outdoor.
        $report = $this->native(['mode' => 'report', 'coop' => $this->coop->id, 'sequence' => 3,
            'message_id' => (string) Str::uuid(), 'now' => now()->timestamp]);
        $this->assertSame('accepted', $this->ingest($report['payload'], 'integration/v1/report'));
        $monitor = app(IntegrationMonitor::class)->reports($this->coop)[0];
        $this->assertFalse($monitor['physical_verified']);
        $this->assertSame([null, null, null, null], array_values($monitor['states']));
        $lcd = app(LcdPayload::class)->generate($this->coop);
        $this->transport->publishDisplay(StagingIdentity::authenticate('VIRTUAL_OUTDOOR', 'test-outdoor-only'), $lcd);
        $parsed = $this->native(['mode' => 'display', 'raw' => end($this->published)['json'],
            'coop' => $this->coop->id, 'registry' => $this->outdoor->id, 'indoor' => $this->device->id,
            'now_us' => now()->timestamp * 1000000]);
        $this->assertSame([null, null, null, null], $parsed['actuators']);
        foreach (app(IntegrationMonitor::class)->deliveries($this->coop)['deliveries'] as $delivery) {
            $this->assertFalse($delivery['applied_current']);
        }
    }

    public function test_session_duplicate_reboot_retired_replay_identity_and_offline(): void
    {
        $session = $this->sessionEnvelope();
        $this->assertSame('accepted', $this->ingest($session, 'integration/v1/session'));
        $row = $this->stage();
        $this->assertSame('published', $this->dispatch($row));
        $this->travel(5)->seconds();
        $lastSeen = $this->device->fresh()->last_seen_at;
        $this->assertSame('duplicate', $this->ingest($session, 'integration/v1/session'));
        $this->assertTrue($lastSeen->eq($this->device->fresh()->last_seen_at));
        $this->reject(fn () => $this->ingest($session, 'integration/v1/session', true));
        $foreign = $this->sessionEnvelope();
        $foreign['coop_id'] = $this->otherCoop->id;
        $this->reject(fn () => $this->ingest($foreign, 'integration/v1/session'));
        $this->assertSame('accepted', $this->ingest($this->sessionEnvelope((string) Str::uuid()), 'integration/v1/session'));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'pending', 'attempts' => 0]);
        $this->reject(fn () => $this->ingest($session, 'integration/v1/session'));
        $this->travel(121)->seconds();
        $this->assertSame('skipped', $this->dispatch($row));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'reason' => 'offline_or_session_unverified']);
        $this->reject(fn () => $this->ingest($this->sessionEnvelope() + ['extra' => true], 'integration/v1/session'));
    }

    public function test_staging_does_not_drain_blocked_rows_or_cross_scope_and_expiry_fails_closed(): void
    {
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->coop, 'operational'));
        $row = $this->stage();
        $this->assertSame('blocked', $row->status);
        $this->assertSame('skipped', $this->dispatch($row));
        $this->coop->settings()->increment('config_version');
        $current = $this->stage();
        $this->assertSame('pending', $current->status);
        $this->identity->device()->update(['coop_id' => $this->otherCoop->id]);
        $this->reject(fn () => $this->dispatch($current));
        $this->device->fresh()->update(['coop_id' => $this->coop->id]);
        $this->travel(3601)->seconds();
        $this->assertSame('skipped', $this->dispatch($current));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $current->id, 'status' => 'failed', 'reason' => 'expired']);
        $this->assertSame([], $this->published);
    }

    public function test_display_retained_foreign_device_and_expired_snapshots_never_publish(): void
    {
        $outdoor = StagingIdentity::authenticate('VIRTUAL_OUTDOOR', 'test-outdoor-only');
        $lcd = app(LcdPayload::class)->generate($this->coop);
        $this->reject(fn () => $this->transport->publishDisplay($outdoor, $lcd, true));
        $this->reject(fn () => $this->transport->publishDisplay($this->identity, $lcd));
        $foreign = $lcd;
        $foreign['coop_id'] = $this->otherCoop->id;
        $this->reject(fn () => $this->transport->publishDisplay($outdoor, $foreign));
        $this->travel(31)->seconds();
        $this->reject(fn () => $this->transport->publishDisplay($outdoor, $lcd));
        $this->assertSame([], $this->published);
    }

    public function test_native_ack_identity_hash_version_and_sequence_replays_are_atomic(): void
    {
        $this->ingest($this->sessionEnvelope(), 'integration/v1/session');
        $row = $this->stage();
        $this->assertSame('published', $this->dispatch($row));
        $native = $this->native(['mode' => 'config', 'steps' => [$this->published[0]['json']],
            'coop' => $this->coop->id, 'now_us' => now()->timestamp * 1000000]);
        foreach (['coop_id' => $this->otherCoop->id, 'device_identifier' => 'VIRTUAL_OUTDOOR',
            'payload_hash' => str_repeat('0', 64), 'config_version' => 999, 'sequence' => 0,
            'boot_id' => (string) Str::uuid(), 'sent_at' => now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z')] as $key => $value) {
            $bad = $native['payload'];
            $bad[$key] = $value;
            $this->reject(fn () => $this->ingest($bad, 'integration/v1/ack'));
            $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'published']);
        }
        $this->assertSame('accepted', $this->ingest($native['payload'], 'integration/v1/ack'));
        $replay = $native['payload'];
        $replay['message_id'] = (string) Str::uuid();
        $this->reject(fn () => $this->ingest($replay, 'integration/v1/ack'));
        $replay = $native['payload'];
        $replay['status'] = 'applied';
        $this->reject(fn () => $this->ingest($replay, 'integration/v1/ack')); // Same ID, changed body.
        $this->coop->settings()->increment('config_version');
        $this->stage();
        $replay['message_id'] = (string) Str::uuid();
        $replay['sequence'] = 2;
        $this->reject(fn () => $this->ingest($replay, 'integration/v1/ack')); // Superseded version.
        $this->assertDatabaseCount('actuator_reports', 0);
    }

    public function test_delayed_preboot_and_expired_session_cannot_replace_current_boot(): void
    {
        $old = $this->sessionEnvelope();
        $this->travel(2)->seconds();
        $new = $this->sessionEnvelope((string) Str::uuid());
        $this->ingest($new, 'integration/v1/session');
        $this->reject(fn () => $this->ingest($old, 'integration/v1/session'));
        $this->travel(61)->seconds();
        $this->reject(fn () => $this->ingest($new, 'integration/v1/session'));
        $this->assertDatabaseCount('integration_sessions', 1);
        $this->assertDatabaseHas('integration_sessions', ['boot_id' => $new['boot_id'], 'current' => true]);
    }
}
