<?php

namespace Tests\Feature;

use App\Services\SensorAggregationService;
use Tests\ApiTestCase;

class TemperatureComparisonTest extends ApiTestCase
{
    private function comparison(): array
    {
        return app(SensorAggregationService::class)->latest($this->coop)['temperature_comparison'];
    }

    public function test_exact_fresh_pair_returns_signed_delta_without_changing_chart_or_csv(): void
    {
        $this->readings();
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/latest')
            ->assertOk()->assertJsonPath('data.temperature_comparison.delta_temp', 1.18)
            ->assertJsonPath('data.temperature_comparison.available', true)
            ->assertJsonPath('data.temperature_comparison.pairing_policy', 'exact_timestamp');
        $this->coop->readings()->where('sensor_type', 'indoor_temperature')->update(['value' => 20]);
        $this->assertSame(-9.2, $this->comparison()['delta_temp']);
    }

    public function test_fresh_but_unpaired_values_are_unavailable_including_microsecond_difference(): void
    {
        $this->readings();
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['recorded_at' => now()->subMicrosecond()->format('Y-m-d H:i:s.u')]);
        $this->assertFalse($this->comparison()['available']);
        $this->assertNull($this->comparison()['delta_temp']);
        $this->assertSame('timestamps_not_paired', $this->comparison()['unavailable_reason']);
    }

    public function test_stale_invalid_missing_and_partial_values_are_unavailable(): void
    {
        $this->assertNull($this->comparison()['delta_temp']);
        $this->readings();
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['value' => 900]);
        $this->assertSame('outdoor_unavailable', $this->comparison()['unavailable_reason']);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['value' => 25]);
        $this->travel(301)->seconds();
        $this->assertNull($this->comparison()['delta_temp']);
        $this->coop->readings()->where('sensor_identifier', 'outdoor_temperature')->update(['recorded_at' => now()]);
        $this->assertNull($this->comparison()['delta_temp']);
    }
}
