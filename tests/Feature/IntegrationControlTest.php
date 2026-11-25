<?php

namespace Tests\Feature;

use App\Services\Integration\CommandDraft;
use App\Services\Integration\ControlEngine;
use App\Services\Integration\DeviceFeedback;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\LcdPayload;
use App\Services\LightingContract;
use App\Services\SensorCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\ApiTestCase;

class IntegrationControlTest extends ApiTestCase
{
    private function seedControlReadings(float $temperature, float $gas): void
    {
        foreach (SensorCatalog::indoorIdentifiers() as $id) {
            $this->device->readings()->create(['coop_id' => $this->coop->id, 'sensor_identifier' => $id,
                'sensor_type' => 'indoor_temperature', 'unit' => 'celsius', 'value' => $temperature, 'recorded_at' => now(), 'batch_key' => 'synthetic-batch']);
        }
        $this->device->readings()->create(['coop_id' => $this->coop->id, 'sensor_identifier' => 'gas_adc',
            'sensor_type' => 'gas', 'unit' => 'adc', 'value' => $gas, 'recorded_at' => now()]);
        $this->coop->settings()->update(['minimum_indoor_temperature' => 20, 'maximum_indoor_temperature' => 30, 'gas_threshold' => 500]);
    }

    private function report(string $boot, array $changes = []): array
    {
        return $changes + ['schema_version' => 1, 'kind' => 'report', 'message_id' => (string) Str::uuid(),
            'coop_id' => $this->coop->id, 'device_identifier' => $this->device->device_identifier, 'boot_id' => $boot,
            'sequence' => 1, 'sent_at' => now()->format('Y-m-d\TH:i:s\Z'), 'feedback_kind' => 'gpio',
            'states' => ['heating_lamp' => false, 'ventilation_fan_1' => true, 'ventilation_fan_2' => true, 'lighting_lamp' => true]];
    }

    public function test_gas_override_both_fans_missing_temperature_and_unverified_policy_are_explicit(): void
    {
        $this->seedControlReadings(25, 700);
        $engine = app(ControlEngine::class);
        $safe = $engine->preview($this->coop);
        $this->assertNull($safe['demand']['ventilation_fan_1']);
        $this->assertTrue($safe['gas_comparison_preview']);
        $result = $engine->preview($this->coop, true);
        $this->assertFalse($result['demand']['heating_lamp']);
        $this->assertTrue($result['demand']['ventilation_fan_1']);
        $this->assertTrue($result['demand']['ventilation_fan_2']);
        $this->assertNull($result['actuator_commands']);
        $this->device->readings()->where('sensor_identifier', SensorCatalog::indoorIdentifiers()[0])->delete();
        $result = $engine->preview($this->coop, true);
        $this->assertNull($result['demand']['heating_lamp']);
        $this->assertTrue($result['demand']['ventilation_fan_2']);
        $this->travel(1000)->seconds();
        $this->assertNull($engine->preview($this->coop, true)['demand']['ventilation_fan_1']);
    }

    public function test_thermal_boundaries_and_wrong_gas_source_never_bypass_interlock(): void
    {
        $this->seedControlReadings(19, 300);
        $engine = app(ControlEngine::class);
        $this->assertTrue($engine->preview($this->coop, true)['demand']['heating_lamp']);
        foreach ([20 => false, 30 => false, 31 => true] as $temperature => $fan) {
            $this->device->readings()->where('sensor_type', 'indoor_temperature')->update(['value' => $temperature]);
            $result = $engine->preview($this->coop, true);
            $this->assertSame($fan, $result['demand']['ventilation_fan_1']);
            $this->assertFalse($result['automation_enabled']);
            $this->assertSame('pending_verification', $result['interlock_status']);
        }
        $this->device->update(['type' => 'nodemcu_esp8266']);
        $this->assertNull($engine->preview($this->coop, true)['demand']['ventilation_fan_1']);
    }

    public function test_reported_states_require_authenticated_current_session_and_expire_on_offline_or_reboot(): void
    {
        config(['integration.simulation' => true]);
        $feedback = app(DeviceFeedback::class);
        $boot = (string) Str::uuid();
        $feedback->openSession($this->device, $boot);
        $topic = "smart-kandang/coops/{$this->coop->id}/devices/{$this->device->device_identifier}/integration/v1/report";
        $payload = $this->report($boot, ['feedback_kind' => 'physical']);
        $this->assertSame('accepted', $feedback->ingest($this->device, $topic, $payload));
        $this->assertSame('duplicate', $feedback->ingest($this->device, $topic, $payload));
        $report = app(IntegrationMonitor::class)->reports($this->coop)[0];
        $this->assertTrue($report['states']['lighting_lamp']);
        $this->assertFalse($report['physical_verified']);
        $this->device->update(['connection_status' => 'offline']);
        $this->assertNull(app(IntegrationMonitor::class)->reports($this->coop)[0]['states']['lighting_lamp']);
        $feedback->openSession($this->device, (string) Str::uuid());
        $this->assertNull(app(IntegrationMonitor::class)->reports($this->coop)[0]['states']['lighting_lamp']);
        try {
            $feedback->ingest($this->device, $topic, $this->report($boot, ['sequence' => 2]));
            $this->fail('Retired report accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('actuator_reports', 1);
        }
    }

    public function test_bh1750_hysteresis_reuses_verified_previous_state_and_lcd_nulls_missing_values(): void
    {
        $outdoor = $this->coop->devices()->create(['device_identifier' => 'OUTDOOR_BH', 'type' => 'nodemcu_esp8266', 'name' => 'Outdoor']);
        config(['lighting.bh1750.telemetry_verified' => true, 'lighting.bh1750.device_identifiers' => ['OUTDOOR_BH']]);
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $this->coop->id, 'lighting_mode' => 'auto', 'min_lux' => 10, 'max_lux' => 100, 'updated_at' => now()]);
        $reading = $outdoor->readings()->create(['coop_id' => $this->coop->id, 'sensor_identifier' => 'outdoor_light_lux', 'sensor_type' => 'outdoor_light',
            'value' => 9, 'unit' => 'lux', 'measurement_version' => LightingContract::BH1750_MEASUREMENT_VERSION, 'recorded_at' => now()]);
        $reading->forceFill(['measurement_version' => LightingContract::BH1750_MEASUREMENT_VERSION])->save();
        $engine = app(ControlEngine::class);
        $this->assertTrue($engine->preview($this->coop)['demand']['lighting_lamp']);
        $reading->update(['value' => 50]);
        $this->assertNull($engine->preview($this->coop)['demand']['lighting_lamp']);
        config(['integration.simulation' => true]);
        $boot = (string) Str::uuid();
        app(DeviceFeedback::class)->openSession($this->device, $boot);
        app(DeviceFeedback::class)->ingest($this->device,
            "smart-kandang/coops/{$this->coop->id}/devices/{$this->device->device_identifier}/integration/v1/report", $this->report($boot));
        foreach ([10, 50, 100] as $value) {
            $reading->update(['value' => $value]);
            $this->assertTrue($engine->preview($this->coop)['demand']['lighting_lamp']);
        }
        $reading->update(['value' => 101]);
        $this->assertFalse($engine->preview($this->coop)['demand']['lighting_lamp']);
        $lcd = app(LcdPayload::class)->generate($this->coop);
        $this->assertNull($lcd['fields']['indoor_temperature']['value']);
        $this->assertNull($lcd['fields']['gas']['value']);
        $this->assertSame('adc', $lcd['fields']['gas']['unit']);
        $this->assertSame(101.0, $lcd['fields']['outdoor_light_lux']['value']);
        $this->assertSame([$outdoor->id], $lcd['target_device_ids']);
        $this->assertFalse($lcd['publication_enabled']);
        $this->travel(1000)->seconds();
        $this->assertNull(app(LcdPayload::class)->generate($this->coop)['fields']['outdoor_light_lux']['value']);
    }

    public function test_command_drafts_are_scoped_expiring_nonretained_manual_and_idempotent(): void
    {
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $this->coop->id, 'lighting_mode' => 'manual', 'config_version' => 1, 'updated_at' => now()]);
        $data = ['request_id' => (string) Str::uuid(), 'actuator' => 'lighting_lamp', 'desired_state' => true,
            'expected_config_version' => 1, 'expires_at' => now()->addSeconds(20)->format('Y-m-d\TH:i:s\Z')];
        $drafts = app(CommandDraft::class);
        $one = $drafts->create($this->admin, $this->coop, $this->device, $data);
        $this->assertSame($one, $drafts->create($this->admin, $this->coop, $this->device, $data));
        $this->assertSame('blocked', $one['status']);
        $this->assertFalse($one['payload']['retained']);
        $this->assertDatabaseCount('actuator_reports', 0);
        foreach ([['desired_state' => false], ['expected_config_version' => 0],
            ['expires_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z')], ['expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z')]] as $change) {
            try {
                $drafts->create($this->admin, $this->coop, $this->device, array_replace($data, $change));
                $this->fail('Invalid/replayed command accepted.');
            } catch (HttpException) {
                $this->assertDatabaseCount('actuator_command_drafts', 1);
            }
        }
        $this->expectException(AuthorizationException::class);
        $drafts->create($this->user, $this->coop, $this->device, $data);
    }

    public function test_feedback_default_disabled_and_outdoor_cannot_report_indoor_actuators(): void
    {
        $boot = (string) Str::uuid();
        try {
            app(DeviceFeedback::class)->openSession($this->device, $boot);
            $this->fail('Default feedback enabled.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_sessions', 0);
        }
        config(['integration.simulation' => true]);
        $this->device->update(['type' => 'nodemcu_esp8266']);
        app(DeviceFeedback::class)->openSession($this->device, $boot);
        $this->expectException(\InvalidArgumentException::class);
        app(DeviceFeedback::class)->ingest($this->device,
            "smart-kandang/coops/{$this->coop->id}/devices/{$this->device->device_identifier}/integration/v1/report", $this->report($boot));
    }
}
