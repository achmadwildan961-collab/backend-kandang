<?php

namespace Tests\Feature;

use App\Services\EventRecorder;
use App\Services\LightingContract;
use App\Services\Mqtt\MqttConnectionFactory;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class Bh1750LightingSettingsTest extends ApiTestCase
{
    private function url(string $suffix = ''): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/lighting'.$suffix;
    }

    private function data(array $overrides = []): array
    {
        // Synthetic test thresholds, not recommendations for real lighting.
        return $overrides + ['schema_version' => 2, 'lighting_mode' => 'auto', 'min_lux' => 10, 'max_lux' => 100,
            'threshold_unit' => 'lux', 'measurement_version' => LightingContract::BH1750_MEASUREMENT_VERSION, 'expected_config_version' => 0];
    }

    public function test_schema_two_is_unconfigured_and_can_only_store_drafts_without_verified_hardware(): void
    {
        $this->mock(MqttConnectionFactory::class)->shouldNotReceive('make');
        $this->asUser($this->user)->getJson($this->url('?schema_version=2'))->assertOk()
            ->assertJsonPath('data.schema_version', 2)->assertJsonPath('data.measurement.sensor_model', 'BH1750')
            ->assertJsonPath('data.measurement.unit', 'lux')->assertJsonPath('data.measurement.status', 'unsupported')
            ->assertJsonPath('data.settings.min_lux', null)->assertJsonPath('data.settings.max_lux', null)
            ->assertJsonPath('data.settings.policy_status', 'unconfigured')->assertJsonPath('data.settings.config_version', 0)
            ->assertJsonPath('data.capabilities.configuration_storage', true)->assertJsonPath('data.capabilities.configuration', false)
            ->assertJsonPath('data.capabilities.commands', false);
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertOk()
            ->assertJsonPath('data.lighting_mode', 'auto')->assertJsonPath('data.min_lux', 10)->assertJsonPath('data.max_lux', 100)
            ->assertJsonPath('data.config_version', 1)->assertJsonPath('data.policy_status', 'draft')
            ->assertJsonPath('data.delivery_status', 'blocked')->assertJsonPath('data.device_applied', false)
            ->assertJsonPath('data.applied_config_version', null);
        $this->assertDatabaseHas('lighting_lux_configuration_outbox', ['config_version' => 1, 'status' => 'blocked']);
        $payload = json_decode(DB::table('lighting_lux_configuration_outbox')->first()->payload, true);
        $this->assertSame('lux', $payload['settings']['threshold_unit']);
        $this->assertSame($this->coop->id, $payload['coop_id']);
        $this->assertNull($payload['actuator_device_id']);
        $this->assertSame('lighting_lamp', $payload['actuator_identifier']);
        $this->assertArrayNotHasKey('dark_threshold', $payload['settings']);
        $this->assertDatabaseCount('coop_lighting_settings', 0);
        $this->assertDatabaseCount('lighting_configuration_outbox', 0);
        $this->postJson($this->url('/commands'), ['schema_version' => 2, 'desired_state' => 'on'])->assertConflict()
            ->assertJsonPath('code', 'lighting_commands_unsupported');
        $this->getJson($this->url('?schema_version=2'))->assertJsonPath('data.actuator.desired_state', null)->assertJsonPath('data.actuator.reported_state', null);
    }

    public function test_legacy_settings_and_outbox_are_preserved_with_independent_versions(): void
    {
        config(['lighting.telemetry_verified' => true, 'lighting.adc_min' => 0, 'lighting.adc_max' => 4095,
            'lighting.polarity' => 'increasing', 'lighting.measurement_version' => 'legacy-test',
            'lighting.device_identifiers' => [$this->device->device_identifier], 'lighting.configuration_storage' => true]);
        $legacy = ['lighting_mode' => 'automatic', 'dark_threshold' => 200, 'bright_threshold' => 800, 'threshold_unit' => 'adc',
            'measurement_version' => 'legacy-test', 'expected_config_version' => 0];
        $this->asUser($this->admin)->putJson($this->url('/settings'), $legacy)->assertOk();
        $oldRow = DB::table('coop_lighting_settings')->first();
        $oldOutbox = DB::table('lighting_configuration_outbox')->first();
        $this->getJson($this->url('?schema_version=2'))->assertJsonPath('data.settings.min_lux', null)->assertJsonPath('data.settings.config_version', 0);
        $this->putJson($this->url('/settings'), $this->data())->assertOk()->assertJsonPath('data.config_version', 1);
        $this->assertEquals($oldRow, DB::table('coop_lighting_settings')->first());
        $this->assertEquals($oldOutbox, DB::table('lighting_configuration_outbox')->first());
        $this->getJson($this->url())->assertJsonPath('data.schema_version', 1)->assertJsonPath('data.deprecated', true)
            ->assertJsonPath('data.settings.threshold_unit', 'adc')->assertJsonPath('data.settings.dark_threshold', 200);
    }

    public function test_stale_draft_conflict_returns_current_and_noop_keeps_version_and_outbox(): void
    {
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertOk();
        $this->putJson($this->url('/settings'), $this->data(['min_lux' => 20]))->assertConflict()
            ->assertJsonPath('code', 'lighting_version_conflict')->assertJsonPath('current.config_version', 1)->assertJsonPath('current.min_lux', 10);
        $this->putJson($this->url('/settings'), $this->data(['expected_config_version' => 1]))->assertOk()->assertJsonPath('data.config_version', 1);
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 1);
        $this->assertDatabaseCount('system_events', 1);
        $this->putJson($this->url('/settings'), $this->data(['expected_config_version' => 1, 'lighting_mode' => 'manual']))->assertOk()->assertJsonPath('data.config_version', 2);
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 2);
        $this->assertSame(1, $this->coop->settings()->first()->config_version); // Separate operational settings version.
    }

    public function test_lux_validation_unknown_fields_and_explicit_unconfigured_state(): void
    {
        $this->asUser($this->admin);
        foreach ([['min_lux' => 100], ['min_lux' => 101], ['min_lux' => -1], ['max_lux' => 65536],
            ['min_lux' => null], ['threshold_unit' => 'adc'], ['lighting_mode' => 'automatic'], ['measurement_version' => 'legacy-adc'],
            ['expected_config_version' => -1], ['expected_config_version' => null], ['dark_threshold' => 10], ['desired_state' => 'on'], ['schema_version' => 3]] as $invalid) {
            $this->putJson($this->url('/settings'), $this->data($invalid))->assertUnprocessable();
        }
        $data = $this->data();
        unset($data['expected_config_version']);
        $this->putJson($this->url('/settings'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('coop_lighting_lux_settings', 0);
        $this->putJson($this->url('/settings'), $this->data(['min_lux' => null, 'max_lux' => null]))->assertOk()
            ->assertJsonPath('data.policy_configured', false)->assertJsonPath('data.automation_enabled', false);
        $this->getJson($this->url('?schema_version=7'))->assertUnprocessable();
    }

    public function test_draft_authorization_matrix_and_inactive_admin_remain_enforced(): void
    {
        $this->getJson($this->url('?schema_version=2'))->assertUnauthorized();
        $this->putJson($this->url('/settings'), $this->data())->assertUnauthorized();
        $this->asUser($this->user)->putJson($this->url('/settings'), $this->data())->assertForbidden();
        $this->asUser($this->outsider)->getJson($this->url('?schema_version=2'))->assertForbidden();
        $this->putJson($this->url('/settings'), $this->data())->assertForbidden();
        $this->admin->update(['status' => 'inactive']);
        $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data())->assertForbidden();
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 0);
    }

    public function test_audit_failure_rolls_back_lux_settings_version_and_outbox(): void
    {
        $this->mock(EventRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('lux transaction probe'));
        $this->withoutExceptionHandling();
        try {
            $this->asUser($this->admin)->putJson($this->url('/settings'), $this->data());
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('lux transaction probe', $error->getMessage());
        }
        $this->assertDatabaseCount('coop_lighting_lux_settings', 0);
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 0);
        $this->assertDatabaseCount('system_events', 0);
    }
}
