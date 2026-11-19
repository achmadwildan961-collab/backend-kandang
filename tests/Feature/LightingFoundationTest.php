<?php

namespace Tests\Feature;

use App\Services\LightingContract;
use App\Services\Mqtt\TelemetryIngestor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\ApiTestCase;

class LightingFoundationTest extends ApiTestCase
{
    private function verifyContract(): void
    {
        // Synthetic test contract only; these values do not approve real hardware.
        config(['lighting.telemetry_verified' => true, 'lighting.adc_min' => 0, 'lighting.adc_max' => 4095,
            'lighting.polarity' => 'increasing', 'lighting.measurement_version' => 'test-v1',
            'lighting.device_identifiers' => [$this->device->device_identifier], 'lighting.configuration_storage' => true]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/lighting'.$suffix;
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['lighting_mode' => 'manual', 'dark_threshold' => 200, 'bright_threshold' => 800,
            'threshold_unit' => 'adc', 'measurement_version' => 'test-v1', 'expected_config_version' => 0], $overrides);
    }

    private function ingest(string $id = 'ldr-1', int $value = 400, ?string $time = null, bool $retained = false): string
    {
        return app(TelemetryIngestor::class)->ingest('smart-kandang/coops/'.$this->coop->id.'/devices/'.$this->device->device_identifier.'/telemetry',
            json_encode(['message_id' => $id, 'sent_at' => $time ?? now()->format('Y-m-d\TH:i:s.u\Z'),
                'readings' => [['sensor_identifier' => 'outdoor_light_adc', 'value' => $value]]], JSON_THROW_ON_ERROR), $retained);
    }

    public function test_default_capabilities_fail_closed_and_preserve_access_rules(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->asUser($this->user)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.capabilities.telemetry', false)->assertJsonPath('data.capabilities.commands', false)
            ->assertJsonPath('data.actuator.reported_state', null);
        $this->putJson($this->url('/settings'), $this->data())->assertForbidden();
        $this->postJson($this->url('/commands'), [])->assertForbidden();
        $this->asUser($this->outsider)->getJson($this->url())->assertForbidden();
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertConflict();
        $this->postJson($this->url('/commands'), [])->assertConflict()->assertJsonPath('code', 'lighting_commands_unsupported');
        $this->assertDatabaseCount('lighting_configuration_outbox', 0);
        $this->expectException(InvalidArgumentException::class);
        $this->ingest();
    }

    public function test_verified_raw_adc_is_deduplicated_freshness_checked_and_not_lux(): void
    {
        $this->verifyContract();
        $this->assertSame('accepted', $this->ingest());
        $this->assertSame('duplicate', $this->ingest());
        $this->asUser($this->user)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.measurement.value', 400)->assertJsonPath('data.measurement.unit', 'adc')
            ->assertJsonPath('data.measurement.calibrated', false)->assertJsonPath('data.measurement.status', 'fresh');
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?sensor_type=outdoor_light')
            ->assertOk()->assertJsonPath('data.0.measurement_version', 'test-v1');
        $this->travel(6)->minutes();
        $this->getJson($this->url())->assertJsonPath('data.measurement.status', 'stale');
        $this->assertSame('accepted', $this->ingest('older', 100, now()->subHour()->format('Y-m-d\TH:i:s.u\Z')));
        $this->getJson($this->url())->assertJsonPath('data.measurement.value', 400);
        config(['lighting.measurement_version' => 'test-v2']);
        $this->getJson($this->url())->assertJsonPath('data.measurement.status', 'missing');
    }

    public function test_bad_range_device_mapping_retained_and_future_samples_are_rejected(): void
    {
        $this->verifyContract();
        foreach (['range', 'device', 'retained', 'future'] as $case) {
            config(['lighting.device_identifiers' => [$case === 'device' ? 'NOT_THIS_DEVICE' : $this->device->device_identifier]]);
            try {
                $this->ingest($case, $case === 'range' ? 5000 : 400,
                    $case === 'future' ? now()->addDay()->format('Y-m-d\TH:i:s.u\Z') : null, $case === 'retained');
                $this->fail('Invalid sample accepted: '.$case);
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sensor_readings', 0);
                $this->assertDatabaseCount('mqtt_receipts', 0);
            }
        }
    }

    public function test_versioned_settings_and_blocked_outbox_commit_together(): void
    {
        $this->verifyContract();
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertOk()
            ->assertJsonPath('data.config_version', 1)->assertJsonPath('data.updated_by', $this->admin->id)
            ->assertJsonPath('data.delivery_status', 'blocked');
        $this->putJson($this->url('/settings'), $this->data())->assertConflict();
        $this->assertDatabaseCount('lighting_configuration_outbox', 1);
        $this->assertDatabaseHas('lighting_configuration_outbox', ['status' => 'blocked', 'config_version' => 1]);
        $this->putJson($this->url('/settings'), $this->data(['expected_config_version' => 1, 'lighting_mode' => 'automatic']))->assertOk()->assertJsonPath('data.config_version', 2);
        $this->assertDatabaseCount('lighting_configuration_outbox', 2);
        $this->getJson($this->url())->assertJsonPath('data.capabilities.configuration', false)->assertJsonPath('data.capabilities.commands', false)
            ->assertJsonPath('data.actuator.desired_state', null)->assertJsonPath('data.actuator.reported_state', null);
        $this->postJson($this->url('/commands'), ['desired_state' => 'on'])->assertConflict();
    }

    public function test_thresholds_units_versions_and_polarity_are_validated(): void
    {
        $this->verifyContract();
        $this->asUser($this->admin);
        foreach ([['dark_threshold' => 800], ['dark_threshold' => -1], ['bright_threshold' => 5000],
            ['threshold_unit' => 'lux'], ['measurement_version' => 'unverified'], ['calibration_version' => 'unverified'], ['desired_state' => 'on']] as $invalid) {
            $this->putJson($this->url('/settings'), $this->data($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('coop_lighting_settings', 0);
        config(['lighting.polarity' => 'decreasing']);
        $this->assertTrue(LightingContract::verified());
        $this->putJson($this->url('/settings'), $this->data())->assertConflict();
        config(['lighting.adc_max' => null]);
        $this->assertFalse(LightingContract::verified());
    }

    public function test_outbox_failure_rolls_back_settings(): void
    {
        $this->verifyContract();
        DB::table('lighting_configuration_outbox')->insert(['coop_id' => $this->coop->id, 'config_version' => 1, 'payload' => '{}']);
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertServerError();
        $this->assertDatabaseCount('coop_lighting_settings', 0);
    }

    public function test_migration_preserves_old_rows_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_18_000000_add_lighting_foundation.php');
        $this->readings();
        $migration->down();
        $this->assertDatabaseCount('sensor_readings', 7);
        $migration->up();
        $this->assertDatabaseCount('sensor_readings', 7);
        $this->verifyContract();
        $this->ingest();
        try {
            $migration->down();
            $this->fail('Rollback must not erase lighting data.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Lighting data exists', $error->getMessage());
        }
        $this->assertDatabaseCount('sensor_readings', 8);
    }
}
