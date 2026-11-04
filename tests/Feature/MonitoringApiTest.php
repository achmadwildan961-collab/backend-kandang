<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\SystemEvent;
use App\Services\AlertEvaluationService;
use App\Services\SensorAggregationService;
use Illuminate\Database\QueryException;
use Tests\ApiTestCase;

class MonitoringApiTest extends ApiTestCase
{
    public function test_latest_five_sensor_average_and_indoor_heatmap(): void
    {
        $this->readings();
        $this->asUser($this->user);
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')->assertOk()->assertJsonPath('data.indoor_temperature.average', 30.38)
            ->assertJsonPath('data.indoor_temperature.complete', true)->assertJsonPath('data.indoor_temperature.valid_count', 5)
            ->assertJsonPath('data.indoor_temperature.status', 'warning')->assertJsonPath('data.outdoor_temperature.condition', 'normal')->assertJsonPath('data.gas.condition', 'normal');
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/indoor')->assertOk()->assertJsonCount(5, 'data.sensors')->assertJsonPath('data.sensors.0.sensor_identifier', 'indoor_dht22_1');
    }

    public function test_missing_stale_invalid_and_future_readings_never_become_a_full_average(): void
    {
        $this->readings();
        $this->coop->readings()->where('sensor_identifier', 'indoor_dht22_5')->delete();
        $data = app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
        $this->assertNull($data['average']);
        $this->assertSame(4, $data['valid_count']);
        $this->assertSame(['indoor_dht22_5'], $data['missing_sensors']);
        $this->reading('indoor_dht22_5', 30, now()->subMinutes(6));
        $data = app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
        $this->assertSame(['indoor_dht22_5'], $data['stale_sensors']);
        $this->coop->readings()->where('sensor_identifier', 'indoor_dht22_5')->delete();
        $this->reading('indoor_dht22_5', 900);
        $this->reading('indoor_dht22_5', 30, now()->addMinute());
        $data = app(SensorAggregationService::class)->latest($this->coop)['indoor_temperature'];
        $this->assertSame(['indoor_dht22_5'], $data['invalid_sensors']);
        $this->assertSame('unknown', $data['status']);
    }

    public function test_latest_valid_value_is_selected_not_the_newest_invalid_value(): void
    {
        $this->readings();
        $this->reading('indoor_dht22_1', 900, now()->addSecond());
        $this->travel(2)->seconds();
        $data = app(SensorAggregationService::class)->latest($this->coop);
        $this->assertSame(30.38, $data['indoor_temperature']['average']);
    }

    public function test_temperature_and_gas_transitions_recovery_and_strict_boundaries(): void
    {
        $this->readings([30, 30, 30, 30, 30]);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['value' => 25]);
        $this->coop->readings()->where('sensor_identifier', 'gas_adc')->update(['value' => 1500]);
        $alerts = app(AlertEvaluationService::class);
        $alerts->evaluate($this->coop);
        $this->assertSame(0, SystemEvent::count());
        $this->coop->readings()->where('sensor_type', 'indoor_temperature')->update(['value' => 31]);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['value' => 24]);
        $this->coop->readings()->where('sensor_identifier', 'gas_adc')->update(['value' => 1501]);
        $alerts->evaluate($this->coop);
        $alerts->evaluate($this->coop);
        $this->assertSame(3, SystemEvent::count());
        $this->assertSame(6, Notification::count());
        $this->assertEqualsCanonicalizing(['high_indoor_temperature', 'low_outdoor_temperature', 'high_gas_level'], SystemEvent::pluck('event_type')->all());
        $this->travel(6)->minutes();
        $alerts->evaluate($this->coop);
        $this->assertSame(3, SystemEvent::count()); // Unknown/stale is not a recovery.
        $this->coop->readings()->where('sensor_type', 'indoor_temperature')->update(['value' => 28, 'recorded_at' => now()]);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['value' => 28, 'recorded_at' => now()]);
        $this->coop->readings()->where('sensor_identifier', 'gas_adc')->update(['value' => 1100, 'recorded_at' => now()]);
        $alerts->evaluate($this->coop);
        $alerts->evaluate($this->coop);
        $this->assertSame(3, SystemEvent::where('event_type', 'sensor_recovered')->count());
    }

    public function test_partial_average_does_not_raise_an_indoor_warning(): void
    {
        $this->reading('indoor_dht22_1', 40);
        app(AlertEvaluationService::class)->evaluate($this->coop);
        $this->assertSame(0, SystemEvent::count());
    }

    public function test_device_disconnect_and_reconnect_are_deduplicated(): void
    {
        $alerts = app(AlertEvaluationService::class);
        $alerts->evaluate($this->coop);
        $this->device->update(['connection_status' => 'offline']);
        $alerts->evaluate($this->coop);
        $alerts->evaluate($this->coop);
        $this->device->update(['connection_status' => 'online']);
        $alerts->evaluate($this->coop);
        $alerts->evaluate($this->coop);
        $this->assertEquals(['device_disconnected', 'device_reconnected'], SystemEvent::orderBy('id')->pluck('event_type')->all());
    }

    public function test_settings_reevaluate_and_preferences_do_not_disable_status_or_events(): void
    {
        $this->readings();
        $this->asUser($this->user);
        $uri = '/api/v1/coops/'.$this->coop->id.'/settings';
        $this->putJson($uri, $this->settings(['notification_preferences' => ['push_notifications' => false], 'gas_threshold' => 1000]))->assertOk()->assertJsonMissingPath('data.alert_state');
        $this->assertSame(3, SystemEvent::count());
        $this->assertSame(0, Notification::count());
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')->assertJsonPath('data.gas.condition', 'warning');
        $this->putJson($uri, $this->settings(['notification_preferences' => ['push_notifications' => false], 'gas_threshold' => 1000]))->assertOk();
        $this->assertSame(3, SystemEvent::count());
        $this->putJson($uri, $this->settings(['maximum_indoor_temperature' => 40, 'gas_threshold' => 2000]))->assertOk();
        $this->assertSame(2, SystemEvent::where('event_type', 'sensor_recovered')->count());
        $this->putJson($uri, $this->settings(['gas_threshold' => 0]))->assertUnprocessable();
        $this->putJson($uri, $this->settings(['alert_state' => ['indoor' => false]]))->assertUnprocessable();
    }

    public function test_history_date_sensor_and_event_filters_are_real(): void
    {
        $this->readings();
        $this->reading('indoor_dht22_1', 20, now()->subDays(10));
        $this->asUser($this->user);
        $uri = '/api/v1/coops/'.$this->coop->id.'/sensors/history';
        $this->getJson($uri.'?from=2026-11-03&to=2026-11-03&sensor_type=indoor_temperature')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($uri.'?from=2026-10-20&to=2026-11-03&sensor_identifier=indoor_dht22_1&per_page=1')->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($uri.'?from=2025-10-01&to=2025-10-02')->assertJsonCount(0, 'data');
        app(AlertEvaluationService::class)->evaluate($this->coop);
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/events?event_type=high_indoor_temperature')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/events?from=2025-10-01&to=2025-10-02')->assertJsonCount(0, 'data');
    }

    public function test_notification_ownership_read_counts_and_event_identity(): void
    {
        $this->readings();
        app(AlertEvaluationService::class)->evaluate($this->coop);
        $mine = $this->user->notifications()->firstOrFail();
        $other = $this->admin->notifications()->firstOrFail();
        $this->assertSame($mine->system_event_id, $other->system_event_id);
        $this->asUser($this->user)->getJson('/api/v1/notifications?filter=unread')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/notifications/'.$other->id.'/read')->assertNotFound();
        $this->patchJson('/api/v1/notifications/'.$mine->id.'/read')->assertOk()->assertJsonPath('data.is_read', true);
        $this->getJson('/api/v1/notifications?filter=unread')->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');
        $this->assertNull($other->fresh()->read_at);
        $this->assertSame(1, SystemEvent::count());
        $this->asUser($this->admin)->patchJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated_count', 1);
        $this->coop->users()->detach($this->user);
        $this->asUser($this->user)->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
    }

    public function test_database_rejects_cross_coop_reading_device_mismatch(): void
    {
        $this->expectException(QueryException::class);
        $this->otherCoop->readings()->create(['device_id' => $this->device->id, 'sensor_identifier' => 'gas_adc', 'sensor_type' => 'gas', 'value' => 1000, 'unit' => 'adc', 'recorded_at' => now()]);
    }

    public function test_average_is_not_rounded_down_before_threshold_comparison(): void
    {
        $this->readings([30.01, 30, 30, 30, 30]);
        $data = app(SensorAggregationService::class)->latest($this->coop);
        $this->assertSame(30.002, $data['indoor_temperature']['average']);
        $this->assertSame('warning', $data['indoor_temperature']['status']);
    }
}
