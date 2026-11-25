<?php

namespace Tests\Feature;

use App\Jobs\DrainIntegrations;
use App\Services\EventRecorder;
use App\Services\Integration\CanonicalPayload;
use App\Services\Integration\ConfigurationDelivery;
use App\Services\Integration\ConfigurationDispatcher;
use App\Services\Integration\DeviceFeedback;
use App\Services\Integration\FakeMqttTransport;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\MqttTransport;
use App\Services\Push\PushDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

class IntegrationDeliveryTest extends ApiTestCase
{
    private FakeMqttTransport $transport;

    private string $boot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integration.simulation' => true]);
        $this->transport = new FakeMqttTransport;
        $this->app->instance(MqttTransport::class, $this->transport);
        $this->boot = (string) Str::uuid();
        app(DeviceFeedback::class)->openSession($this->device, $this->boot);
    }

    private function stage(): object
    {
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->coop, 'operational'));

        return DB::table('integration_deliveries')->where('device_id', $this->device->id)->orderByDesc('id')->first();
    }

    private function ack(object $row, string $status = 'applied', int $sequence = 1): array
    {
        return ['schema_version' => 1, 'kind' => 'ack', 'message_id' => (string) Str::uuid(), 'coop_id' => $this->coop->id,
            'device_identifier' => $this->device->device_identifier, 'boot_id' => $this->boot, 'sequence' => $sequence,
            'sent_at' => now()->format('Y-m-d\TH:i:s\Z'), 'configuration_message_id' => $row->message_id,
            'domain' => $row->domain, 'config_version' => $row->config_version, 'payload_hash' => $row->payload_hash, 'status' => $status];
    }

    private function feedback(array $payload, bool $retained = false): string
    {
        return app(DeviceFeedback::class)->ingest($this->device,
            "smart-kandang/coops/{$this->coop->id}/devices/{$this->device->device_identifier}/integration/v1/ack", $payload, $retained);
    }

    public function test_default_is_blocked_and_phase7_save_stages_atomic_drafts_without_publishing(): void
    {
        config(['integration.simulation' => false]);
        $this->asUser($this->admin)->putJson('/api/v1/coops/'.$this->coop->id.'/settings',
            $this->settings(['minimum_indoor_temperature' => 20]))->assertOk();
        $this->assertDatabaseHas('operational_configuration_outbox', ['status' => 'blocked', 'config_version' => 2]);
        $row = DB::table('integration_deliveries')->first();
        $this->assertSame('blocked', $row->status);
        $this->assertSame('blocked', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $this->assertSame([], $this->transport->messages);
        config(['integration.simulation' => true]);
        $this->assertSame('skipped', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $this->assertSame([], $this->transport->messages); // Never drain previously blocked drafts.
    }

    public function test_publish_acknowledge_and_applied_are_distinct_and_duplicates_are_idempotent(): void
    {
        $row = $this->stage();
        $this->stage();
        $this->assertDatabaseCount('integration_deliveries', 1);
        $this->assertSame('published', app(ConfigurationDispatcher::class)->dispatch($row->id));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'published', 'applied_at' => null]);
        $this->assertTrue($this->transport->messages[0]['retained']);
        $this->assertSame($row->message_id, $this->transport->messages[0]['idempotencyKey']);
        $wire = $this->transport->messages[0]['payload'];
        unset($wire['payload_hash']);
        $this->assertSame($row->payload_hash, hash('sha256', CanonicalPayload::encode($wire)));
        $this->assertSame('accepted', $this->feedback($this->ack($row, 'acknowledged')));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'acknowledged', 'applied_at' => null]);
        $ack = $this->ack($row, 'applied', 2);
        $this->assertSame('accepted', $this->feedback($ack));
        $this->assertSame('duplicate', $this->feedback($ack));
        $this->assertSame('duplicate', $this->feedback(array_reverse($ack, true)));
        $this->assertDatabaseCount('integration_receipts', 2);
        $this->assertTrue(app(IntegrationMonitor::class)->deliveries($this->coop)['deliveries'][0]['applied_current']);
        $this->assertDatabaseCount('actuator_reports', 0); // Applied config is never a relay report.
    }

    public function test_transport_retries_have_stable_identity_backoff_and_finite_budget(): void
    {
        $row = $this->stage();
        $this->transport->failures = 3;
        $dispatcher = app(ConfigurationDispatcher::class);
        $this->assertSame('retry', $dispatcher->dispatch($row->id));
        $this->assertSame('skipped', $dispatcher->dispatch($row->id));
        $this->travel(5)->seconds();
        $this->assertSame('retry', $dispatcher->dispatch($row->id));
        $this->travel(10)->seconds();
        $this->assertSame('failed', $dispatcher->dispatch($row->id));
        $this->travel(20)->seconds();
        $this->assertSame('skipped', $dispatcher->dispatch($row->id));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'attempts' => 3, 'status' => 'failed']);
    }

    public function test_ack_timeout_retries_same_payload_and_worker_lease_prevents_double_claim(): void
    {
        $row = $this->stage();
        DB::table('integration_deliveries')->where('id', $row->id)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addSecond()]);
        $dispatcher = app(ConfigurationDispatcher::class);
        $this->assertSame('skipped', $dispatcher->dispatch($row->id));
        $this->travel(2)->seconds();
        $this->assertSame('published', $dispatcher->dispatch($row->id));
        $this->travel(60)->seconds();
        $this->assertSame('published', $dispatcher->dispatch($row->id));
        $this->assertSame($this->transport->messages[0], $this->transport->messages[1]);
    }

    public function test_wrong_scope_hash_boot_timestamp_retained_and_replay_ack_are_rejected_atomically(): void
    {
        $row = $this->stage();
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $valid = $this->ack($row);
        foreach ([['coop_id' => $this->otherCoop->id], ['payload_hash' => str_repeat('0', 64)], ['config_version' => 99],
            ['boot_id' => (string) Str::uuid()], ['device_identifier' => 'foreign'], ['sent_at' => now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z')],
            ['sent_at' => now()->addSecond()->format('Y-m-d\TH:i:s\Z')]] as $invalid) {
            try {
                $this->feedback(array_replace($valid, $invalid));
                $this->fail('Invalid ACK accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('integration_receipts', 0);
                $this->assertDatabaseHas('integration_deliveries', ['status' => 'published']);
            }
        }
        try {
            $this->feedback($valid, true);
            $this->fail('Retained ACK accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_receipts', 0);
        }
        $this->feedback($valid);
        foreach ([array_replace($valid, ['status' => 'acknowledged']), $this->ack($row, 'applied')] as $replay) {
            try {
                $this->feedback($replay);
                $this->fail('Replay accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('integration_receipts', 1);
            }
        }
    }

    public function test_new_version_supersedes_old_and_expiry_never_publishes(): void
    {
        $old = $this->stage();
        app(ConfigurationDispatcher::class)->dispatch($old->id);
        $this->coop->settings()->update(['config_version' => 2]);
        $new = $this->stage();
        $this->assertDatabaseHas('integration_deliveries', ['id' => $old->id, 'status' => 'failed', 'reason' => 'superseded']);
        try {
            $this->feedback($this->ack($old));
            $this->fail('Stale ACK accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_receipts', 0);
        }
        DB::table('integration_deliveries')->where('id', $new->id)->update(['expires_at' => now()]);
        app(ConfigurationDispatcher::class)->dispatch($new->id);
        $this->assertDatabaseHas('integration_deliveries', ['id' => $new->id, 'status' => 'failed', 'reason' => 'expired']);
        $this->assertCount(1, $this->transport->messages);
    }

    public function test_offline_pauses_attempts_new_boot_requires_reapply_and_old_boot_cannot_return(): void
    {
        $row = $this->stage();
        $this->device->update(['connection_status' => 'offline']);
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'attempts' => 0]);
        app(DeviceFeedback::class)->openSession($this->device, $this->boot);
        $this->travel(5)->seconds();
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $this->feedback($this->ack($row));
        $oldBoot = $this->boot;
        $this->boot = (string) Str::uuid();
        app(DeviceFeedback::class)->openSession($this->device, $this->boot);
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'pending', 'applied_at' => null]);
        try {
            app(DeviceFeedback::class)->openSession($this->device, $oldBoot);
            $this->fail('Old boot accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_sessions', 2);
        }
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $this->feedback($this->ack($row));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'applied', 'boot_id' => $this->boot]);
    }

    public function test_reassignment_blocks_delivery_and_wrong_device_cannot_ack(): void
    {
        $row = $this->stage();
        $this->device->update(['coop_id' => $this->otherCoop->id]);
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'failed', 'reason' => 'superseded_or_reassigned']);
        $this->assertSame([], $this->transport->messages);
    }

    public function test_settings_and_delivery_rollback_together_on_audit_failure(): void
    {
        $this->mock(EventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('audit unavailable'));
        $this->asUser($this->admin)->putJson('/api/v1/coops/'.$this->coop->id.'/settings',
            $this->settings(['minimum_indoor_temperature' => 20]))->assertStatus(500);
        $this->assertDatabaseCount('integration_deliveries', 0);
        $this->assertDatabaseCount('operational_configuration_outbox', 0);
        $this->assertDatabaseHas('coop_settings', ['coop_id' => $this->coop->id, 'config_version' => 1]);
    }

    public function test_monitoring_delivery_rbac_and_all_physical_endpoints_stay_blocked(): void
    {
        $this->stage();
        $base = '/api/v1/coops/'.$this->coop->id;
        $this->getJson($base.'/integration/deliveries')->assertUnauthorized();
        $this->asUser($this->outsider)->getJson($base.'/integration/monitoring')->assertForbidden();
        $this->asUser($this->user)->getJson($base.'/integration/deliveries')->assertOk()->assertJsonCount(1, 'data.deliveries');
        $this->getJson($base.'/integration/monitoring')->assertOk()->assertJsonPath('data.control_preview.automation_enabled', false);
        $this->postJson($base.'/actuator/commands', [])->assertForbidden();
        $this->asUser($this->admin)->postJson($base.'/actuator/commands', [])->assertConflict();
        $this->postJson($base.'/lighting/commands', [])->assertConflict();
        $this->getJson($base.'/lighting')->assertJsonPath('data.schema_version', 1);
        $this->getJson($base.'/lighting?schema_version=2')->assertJsonPath('data.capabilities.commands', false);
        $this->admin->update(['status' => 'inactive']);
        $this->asUser($this->admin)->getJson($base.'/integration/deliveries')->assertForbidden();
    }

    public function test_multi_coop_delivery_identity_and_authenticated_device_cannot_cross_scope(): void
    {
        $local = $this->stage();
        $foreign = $this->otherCoop->devices()->create(['device_identifier' => 'FOREIGN_INDOOR', 'name' => 'Foreign', 'type' => 'esp32']);
        $boot = (string) Str::uuid();
        app(DeviceFeedback::class)->openSession($foreign, $boot);
        DB::transaction(fn () => app(ConfigurationDelivery::class)->stage($this->otherCoop, 'operational'));
        $other = DB::table('integration_deliveries')->where('coop_id', $this->otherCoop->id)->first();
        $this->assertNotSame($local->message_id, $other->message_id);
        app(ConfigurationDispatcher::class)->dispatch($local->id);
        try {
            app(DeviceFeedback::class)->ingest($foreign,
                "smart-kandang/coops/{$this->coop->id}/devices/{$this->device->device_identifier}/integration/v1/ack", $this->ack($local));
            $this->fail('Cross-device ACK accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_receipts', 0);
        }
        $this->asUser($this->user)->getJson('/api/v1/coops/'.$this->coop->id.'/integration/deliveries')
            ->assertOk()->assertJsonCount(1, 'data.deliveries')->assertJsonPath('data.deliveries.0.device_id', $this->device->id);
        $this->getJson('/api/v1/coops/'.$this->otherCoop->id.'/integration/deliveries')->assertForbidden();
    }

    public function test_failed_transport_cannot_receive_unsent_ack_and_rejected_ack_is_terminal(): void
    {
        $row = $this->stage();
        $this->transport->failures = 1;
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        try {
            $this->feedback($this->ack($row));
            $this->fail('Unsent ACK accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('integration_receipts', 0);
        }
        $this->travel(5)->seconds();
        app(ConfigurationDispatcher::class)->dispatch($row->id);
        $this->feedback($this->ack($row, 'rejected'));
        $this->assertDatabaseHas('integration_deliveries', ['id' => $row->id, 'status' => 'failed', 'reason' => 'device_rejected', 'applied_at' => null]);
        $this->assertSame('skipped', app(ConfigurationDispatcher::class)->dispatch($row->id));
    }

    public function test_lux_save_stages_independent_snapshot_and_queued_job_publishes_only_fakes(): void
    {
        $this->stage();
        $this->asUser($this->admin)->putJson('/api/v1/coops/'.$this->coop->id.'/lighting/settings', [
            'schema_version' => 2, 'lighting_mode' => 'auto', 'min_lux' => 10, 'max_lux' => 100,
            'threshold_unit' => 'lux', 'measurement_version' => 'bh1750_lux_v1', 'expected_config_version' => 0,
        ])->assertOk();
        $this->assertDatabaseHas('integration_deliveries', ['domain' => 'lighting_lux', 'config_version' => 1, 'status' => 'pending']);
        $this->assertDatabaseHas('lighting_lux_configuration_outbox', ['status' => 'blocked']);
        app(DrainIntegrations::class)->handle(app(ConfigurationDispatcher::class), app(PushDispatcher::class));
        $this->assertCount(2, $this->transport->messages);
        $this->assertDatabaseHas('integration_deliveries', ['domain' => 'operational', 'status' => 'published', 'applied_at' => null]);
        $this->assertDatabaseHas('integration_deliveries', ['domain' => 'lighting_lux', 'status' => 'published', 'applied_at' => null]);
    }

    public function test_bounded_sweep_does_not_starve_later_devices_behind_offline_retries(): void
    {
        $this->device->update(['connection_status' => 'offline']);
        $second = $this->coop->devices()->create(['device_identifier' => 'SECOND_NODE', 'name' => 'Second', 'type' => 'esp32']);
        app(DeviceFeedback::class)->openSession($second, (string) Str::uuid());
        $this->stage();
        $worker = app(ConfigurationDispatcher::class);
        // Explicit due ordering; relation row order differs between SQLite/MySQL.
        DB::table('integration_deliveries')->where('device_id', $this->device->id)->update(['next_attempt_at' => now()->subSeconds(10)]);
        $this->assertSame(1, $worker->run(1));
        $this->assertSame([], $this->transport->messages);
        $this->travel(5)->seconds();
        $this->assertSame(1, $worker->run(1));
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame('SECOND_NODE', $this->transport->messages[0]['payload']['device_identifier']);
    }
}
