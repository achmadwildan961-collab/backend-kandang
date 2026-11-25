<?php

namespace Tests\Feature;

use App\Services\AlertEvaluationService;
use App\Services\Mqtt\MqttConnectionFactory;
use App\Services\OperationalConfigurationOutbox;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class OperationalConfigurationOutboxTest extends ApiTestCase
{
    public function test_settings_stage_scoped_immutable_blocked_drafts_without_any_transport(): void
    {
        $this->mock(MqttConnectionFactory::class)->shouldNotReceive('make');
        $outdoor = $this->coop->devices()->create(['device_identifier' => 'OUTDOOR', 'name' => 'Outdoor', 'type' => 'nodemcu_esp8266', 'connection_status' => 'offline']);
        $this->otherCoop->devices()->create(['device_identifier' => 'PRIVATE', 'name' => 'Other coop', 'type' => 'esp32']);
        $url = '/api/v1/coops/'.$this->coop->id.'/settings';
        $this->asUser($this->admin)->putJson($url, $this->settings(['minimum_indoor_temperature' => 24]))
            ->assertOk()->assertJsonPath('data.configuration_status.outbox', 'blocked')
            ->assertJsonPath('data.configuration_status.target_count', 2)
            ->assertJsonPath('data.configuration_status.applied_config_version', null);
        $rows = DB::table('operational_configuration_outbox')->orderBy('device_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->device->id, $outdoor->id], $rows->pluck('device_id')->all());
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $this->assertSame('blocked', $row->status);
            $this->assertSame('firmware_contract_unverified', $row->blocked_reason);
            $this->assertSame($this->coop->id, $payload['coop_id']);
            $this->assertSame($row->device_id, $payload['device_id']);
            $this->assertSame(2, $payload['config_version']);
            $this->assertSame(24, $payload['configuration']['minimum_indoor_temperature']);
            $this->assertSame('adc', $payload['configuration']['gas_unit']);
            $this->assertArrayNotHasKey('minimum_outdoor_temperature', $payload['configuration']);
            $this->assertArrayNotHasKey('topic', $payload);
        }
        DB::transaction(fn () => app(OperationalConfigurationOutbox::class)->stage($this->coop, $this->coop->settings()->first()));
        $this->putJson($url, $this->settings(['minimum_indoor_temperature' => 24]))->assertOk();
        $this->assertDatabaseCount('operational_configuration_outbox', 2);
        $this->putJson($url, $this->settings(['expected_config_version' => 1, 'gas_threshold' => 900]))->assertConflict();
        $this->assertDatabaseCount('operational_configuration_outbox', 2);
        $this->putJson($url, $this->settings(['minimum_indoor_temperature' => 23]))->assertOk();
        $this->assertDatabaseCount('operational_configuration_outbox', 4);
        $this->assertSame($rows->first()->payload, DB::table('operational_configuration_outbox')->where('id', $rows->first()->id)->first()->payload);
        $this->assertSame('offline', $outdoor->fresh()->connection_status);
    }

    public function test_failure_after_outbox_insert_rolls_back_the_entire_save(): void
    {
        $this->mock(AlertEvaluationService::class)->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('outbox rollback probe'));
        $this->withoutExceptionHandling();
        try {
            $this->asUser($this->admin)->putJson('/api/v1/coops/'.$this->coop->id.'/settings', $this->settings(['gas_threshold' => 900]));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox rollback probe', $exception->getMessage());
        }
        $this->assertDatabaseCount('operational_configuration_outbox', 0);
        $this->assertDatabaseCount('system_events', 0);
        $this->assertSame(1, $this->coop->settings()->first()->config_version);
    }

    public function test_cross_coop_staging_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => app(OperationalConfigurationOutbox::class)->stage($this->otherCoop, $this->coop->settings()->first()));
    }
}
