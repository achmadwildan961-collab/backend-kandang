<?php

namespace Tests\Feature;

use App\Services\AlertEvaluationService;
use Tests\ApiTestCase;

class SettingsVersionContractTest extends ApiTestCase
{
    private function url(): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/settings';
    }

    public function test_two_drafts_from_same_version_cannot_overwrite_each_other(): void
    {
        $this->asUser($this->admin);
        $version = $this->getJson($this->url())->assertOk()->json('data.config_version');
        $draft = $this->settings(['expected_config_version' => $version, 'minimum_indoor_temperature' => 24]);
        $this->putJson($this->url(), $draft)->assertOk()->assertJsonPath('data.config_version', $version + 1)
            ->assertJsonPath('data.configuration_status.device_applied', false);
        $this->putJson($this->url(), array_replace($draft, ['gas_threshold' => 900]))->assertConflict()
            ->assertJsonPath('code', 'configuration_version_conflict')
            ->assertJsonPath('current.config_version', $version + 1)
            ->assertJsonPath('current.minimum_indoor_temperature', 24);
        $this->assertSame(1500, $this->coop->settings()->first()->gas_threshold);
        $this->assertDatabaseCount('system_events', 1);
    }

    public function test_legacy_unversioned_write_cannot_bypass_concurrency_check(): void
    {
        $data = $this->settings(['gas_threshold' => 900]);
        unset($data['expected_config_version']);
        $this->asUser($this->admin)->putJson($this->url(), $data)->assertStatus(428)
            ->assertJsonPath('code', 'configuration_version_required')->assertJsonPath('current.config_version', 1);
        $this->assertSame(1500, $this->coop->settings()->first()->gas_threshold);
        $this->assertDatabaseCount('system_events', 0);
    }

    public function test_noop_does_not_increment_version_and_preferences_merge(): void
    {
        $this->asUser($this->admin)->putJson($this->url(), $this->settings())->assertOk()->assertJsonPath('data.config_version', 1);
        $this->putJson($this->url(), $this->settings(['notification_preferences' => ['temperature_alerts' => false]]))
            ->assertOk()->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.notification_preferences.temperature_alerts', false)
            ->assertJsonPath('data.notification_preferences.gas_alerts', true);
        $this->assertDatabaseHas('system_events', ['event_type' => 'configuration_changed']);
        $metadata = $this->coop->events()->first()->metadata;
        $this->assertSame(1, $metadata['previous_config_version']);
        $this->assertSame(2, $metadata['config_version']);
        $this->putJson($this->url(), $this->settings(['expected_config_version' => 1]))->assertConflict();
    }

    public function test_version_and_settings_and_audit_roll_back_when_evaluation_fails(): void
    {
        $this->mock(AlertEvaluationService::class)->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Test failure'));
        $this->withoutExceptionHandling();
        try {
            $this->asUser($this->admin)->putJson($this->url(), $this->settings(['gas_threshold' => 900]));
            $this->fail('Expected exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test failure', $exception->getMessage());
        }
        $this->assertSame(1, $this->coop->settings()->first()->config_version);
        $this->assertSame(1500, $this->coop->settings()->first()->gas_threshold);
        $this->assertDatabaseCount('system_events', 0);
    }

    public function test_versions_are_scoped_to_coop_and_invalid_versions_cannot_be_written(): void
    {
        $this->asUser($this->admin)->putJson($this->url(), $this->settings(['gas_threshold' => 900]))->assertOk();
        $this->assertSame(1, $this->otherCoop->settings()->first()->config_version);
        foreach ([null, 0, -1, 1.5, 'garbage'] as $version) {
            $this->putJson($this->url(), $this->settings(['expected_config_version' => $version]))->assertUnprocessable();
        }
        $this->putJson($this->url(), $this->settings(['config_version' => 99]))->assertUnprocessable();
        $this->asUser($this->user)->putJson($this->url(), $this->settings())->assertForbidden();
        $this->asUser($this->outsider)->putJson($this->url(), $this->settings())->assertForbidden();
        $this->admin->update(['status' => 'inactive']);
        $this->asUser($this->admin)->putJson($this->url(), $this->settings())->assertForbidden();
    }
}
