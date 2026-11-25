<?php

namespace Tests\Feature;

use App\Models\SystemEvent;
use App\Services\AlertEvaluationService;
use App\Services\SensorAggregationService;
use Tests\ApiTestCase;

class IndoorThresholdContractTest extends ApiTestCase
{
    public function test_new_minimum_is_unconfigured_and_legacy_outdoor_rule_is_retired(): void
    {
        $this->coop->settings()->update(['alert_state' => ['outdoor' => true]]);
        $this->reading('outdoor_temperature', -10);
        app(AlertEvaluationService::class)->evaluate($this->coop);
        $this->assertDatabaseCount('system_events', 0);
        $this->assertTrue($this->coop->settings()->first()->alert_state['outdoor']);
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/settings')
            ->assertOk()->assertJsonPath('data.minimum_indoor_temperature', null)
            ->assertJsonPath('data.minimum_outdoor_temperature', 25)
            ->assertJsonPath('data.legacy_outdoor_minimum_alert_enabled', false);
        $this->assertSame('normal', app(SensorAggregationService::class)->latest($this->coop)['outdoor_temperature']['condition']);
    }

    public function test_indoor_minimum_validation_uses_merged_settings_and_accepts_explicit_disable(): void
    {
        $url = '/api/v1/coops/'.$this->coop->id.'/settings';
        $this->asUser($this->admin)->putJson($url, $this->settings(['minimum_indoor_temperature' => 26]))
            ->assertOk()->assertJsonPath('data.minimum_indoor_temperature', 26);
        foreach ([26, 25] as $maximum) {
            $this->putJson($url, $this->settings(['maximum_indoor_temperature' => $maximum]))->assertUnprocessable();
        }
        $this->putJson($url, $this->settings(['minimum_indoor_temperature' => 30]))->assertUnprocessable();
        $this->putJson($url, $this->settings(['minimum_indoor_temperature' => null]))->assertOk()
            ->assertJsonPath('data.indoor_minimum_configured', false);
    }

    public function test_low_indoor_alert_deduplicates_recovers_and_obeys_preferences(): void
    {
        $this->coop->settings()->update(['minimum_indoor_temperature' => 26, 'notification_preferences' => ['temperature_alerts' => false]]);
        $this->readings([25, 25, 25, 25, 25]);
        $alerts = app(AlertEvaluationService::class);
        $alerts->evaluate($this->coop);
        $alerts->evaluate($this->coop);
        $this->assertSame(['low_indoor_temperature'], SystemEvent::pluck('event_type')->all());
        $this->assertDatabaseCount('notifications', 0);
        $this->travel(301)->seconds();
        $alerts->evaluate($this->coop);
        $this->assertDatabaseCount('system_events', 1);
        $this->coop->readings()->where('sensor_type', 'indoor_temperature')->update(['value' => 26, 'recorded_at' => now()]);
        $alerts->evaluate($this->coop);
        $this->assertSame(['low_indoor_temperature', 'sensor_recovered'], SystemEvent::orderBy('id')->pluck('event_type')->all());
    }
}
