<?php

namespace Tests\Feature;

use Tests\ApiTestCase;

class ChartHistoryTest extends ApiTestCase
{
    private function uri(array $query = []): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/sensors/chart?'.http_build_query($query + ['from' => '2026-11-03T00:00:00Z', 'to' => '2026-11-03T23:59:59.999999Z']);
    }

    public function test_complete_batches_aggregate_without_inventing_asynchronous_samples(): void
    {
        $this->readings();
        $this->reading('indoor_dht22_1', 70, now()->addSecond());
        foreach (range(2, 5) as $i) {
            $this->reading('indoor_dht22_'.$i, 70, now()->addSeconds($i));
        }
        $this->reading('indoor_humidity', 75, now()->addSecond());
        $this->asUser($this->user)->getJson($this->uri(['interval_seconds' => 3600]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.indoor_average', 30.38)
            ->assertJsonPath('data.0.indoor_sample_count', 1)->assertJsonPath('data.0.indoor_humidity', 75)
            ->assertJsonPath('summary.indoor_maximum', 30.38);
    }

    public function test_summary_is_sample_weighted_and_keeps_true_peak_not_bucket_peak(): void
    {
        foreach ([[0, 20], [1, 40], [3600, 50]] as [$offset, $temperature]) {
            foreach (range(1, 5) as $i) {
                $this->reading('indoor_dht22_'.$i, $temperature, now()->addSeconds($offset));
            }
        }
        $this->asUser($this->user)->getJson($this->uri(['interval_seconds' => 3600, 'per_page' => 1]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.indoor_average', 30)->assertJsonPath('summary.indoor_maximum', 50)
            ->assertJsonPath('summary.indoor_sample_count', 3);
        $summary = $this->getJson($this->uri())->json('summary.indoor_average');
        $this->assertEqualsWithDelta(110 / 3, $summary, 0.0001);
    }

    public function test_incomplete_or_invalid_indoor_readings_leave_other_metrics_available(): void
    {
        foreach (range(1, 4) as $i) {
            $this->reading('indoor_dht22_'.$i, 30);
        }
        $this->reading('indoor_dht22_5', 900);
        $this->reading('outdoor_temperature', 24);
        $this->reading('gas_adc', 1000);
        $this->asUser($this->user)->getJson($this->uri())->assertOk()
            ->assertJsonPath('data.0.indoor_average', null)->assertJsonPath('summary.indoor_sample_count', 0)
            ->assertJsonPath('data.0.outdoor_temperature', 24)->assertJsonPath('data.0.gas_adc', 1000);
    }

    public function test_bounds_and_interval_are_validated_and_responses_bounded(): void
    {
        $this->asUser($this->user);
        $this->getJson($this->uri(['interval_seconds' => 60]))->assertUnprocessable();
        $this->getJson($this->uri(['interval_seconds' => 123]))->assertUnprocessable();
        $this->getJson($this->uri(['from' => '2020-01-01']))->assertUnprocessable();
        $this->getJson($this->uri(['per_page' => 10000]))->assertUnprocessable();
        $this->getJson($this->uri(['from' => '2026-12-01']))->assertUnprocessable();
        $this->getJson($this->uri())->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.indoor_average', null);
    }

    public function test_chart_authorization_and_raw_history_contract_are_preserved(): void
    {
        $this->readings();
        $this->asUser($this->outsider)->getJson($this->uri())->assertForbidden();
        $this->asUser($this->admin)->getJson($this->uri())->assertOk();
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history')->assertOk()->assertJsonPath('meta.total', 7);
    }

    public function test_cors_preflight_allows_configured_origin_and_bearer_header_only(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:58111', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'authorization'])->options('/api/v1/coops/1/sensors/chart')
            ->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:58111');
        $response = $this->withHeaders(['Origin' => 'https://untrusted.invalid', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/coops/1/sensors/chart');
        $this->assertNotSame('https://untrusted.invalid', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
