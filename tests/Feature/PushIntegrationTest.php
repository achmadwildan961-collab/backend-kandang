<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Services\EventRecorder;
use App\Services\Push\FakePushProvider;
use App\Services\Push\PushDispatcher;
use App\Services\Push\PushProvider;
use App\Services\Push\PushQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

class PushIntegrationTest extends ApiTestCase
{
    private FakePushProvider $provider;

    private string $installation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakePushProvider;
        $this->app->instance(PushProvider::class, $this->provider);
        $this->installation = (string) Str::uuid();
    }

    private function register(string $value = 'synthetic-token-for-tests'): int
    {
        return $this->asUser($this->user)->postJson('/api/v1/push/tokens', ['installation_id' => $this->installation,
            'platform' => 'android', 'token' => $value])->assertSuccessful()->assertJsonMissing(['token' => $value])->json('data.id');
    }

    private function notify(): object
    {
        app(EventRecorder::class)->record($this->coop, 'high_gas', 'warning', 'Test gas', 'Synthetic warning', ['source' => 'gas']);

        return DB::table('push_deliveries')->orderByDesc('id')->first();
    }

    public function test_token_lifecycle_encrypts_hides_scopes_and_rotates_tokens(): void
    {
        $this->postJson('/api/v1/push/tokens', [])->assertUnauthorized();
        $id = $this->register();
        $row = DB::table('push_tokens')->find($id);
        $this->assertNotSame('synthetic-token-for-tests', $row->token);
        $this->assertSame('synthetic-token-for-tests', Crypt::decryptString($row->token));
        $this->assertSame($id, $this->register('rotated-synthetic-token'));
        $this->assertDatabaseCount('push_tokens', 1);
        $this->asUser($this->outsider)->deleteJson('/api/v1/push/tokens/'.$id)->assertNotFound();
        $this->postJson('/api/v1/push/tokens', ['installation_id' => (string) Str::uuid(), 'platform' => 'ios', 'token' => 'rotated-synthetic-token'])->assertConflict();
        $this->asUser($this->user)->deleteJson('/api/v1/push/tokens/'.$id)->assertNoContent();
        $this->assertDatabaseHas('push_tokens', ['id' => $id, 'active' => false]);
    }

    public function test_default_push_is_blocked_and_cannot_be_drained_by_later_toggle(): void
    {
        $this->register();
        $row = $this->notify();
        $this->assertSame('blocked', $row->status);
        $this->assertSame('blocked', app(PushDispatcher::class)->dispatch($row->id));
        config(['integration.simulation' => true]);
        $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
        $this->assertSame([], $this->provider->messages);
    }

    public function test_queue_dedup_and_provider_acceptance_do_not_claim_mobile_delivery(): void
    {
        config(['integration.simulation' => true]);
        $this->register();
        $row = $this->notify();
        DB::transaction(fn () => app(PushQueue::class)->stage(Notification::findOrFail($row->notification_id)));
        $this->assertDatabaseCount('push_deliveries', 1);
        $this->assertSame('provider_accepted', app(PushDispatcher::class)->dispatch($row->id));
        $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
        $this->assertCount(1, $this->provider->messages);
        $this->assertSame($row->message_id, $this->provider->messages[0]['idempotencyKey']);
        $this->getJson('/api/v1/push/deliveries')->assertOk()->assertJsonPath('data.0.status', 'provider_accepted');
        $this->asUser($this->outsider)->getJson('/api/v1/push/deliveries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_provider_failures_retry_with_backoff_and_stop_at_budget(): void
    {
        config(['integration.simulation' => true]);
        $this->register();
        $row = $this->notify();
        $this->provider->failures = 3;
        $dispatcher = app(PushDispatcher::class);
        $this->assertSame('pending', $dispatcher->dispatch($row->id));
        $this->assertSame('skipped', $dispatcher->dispatch($row->id));
        $this->travel(5)->seconds();
        $this->assertSame('pending', $dispatcher->dispatch($row->id));
        $this->travel(10)->seconds();
        $this->assertSame('failed', $dispatcher->dispatch($row->id));
        $this->assertDatabaseHas('push_deliveries', ['id' => $row->id, 'attempts' => 3, 'status' => 'failed']);
    }

    public function test_invalid_provider_token_is_revoked_and_rotation_cancels_old_delivery(): void
    {
        config(['integration.simulation' => true]);
        $token = $this->register();
        $row = $this->notify();
        $this->register('rotated-synthetic-token');
        $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
        $this->assertDatabaseHas('push_deliveries', ['id' => $row->id, 'status' => 'failed']);
        $new = $this->notify();
        $this->provider->invalidToken = true;
        $this->assertSame('failed', app(PushDispatcher::class)->dispatch($new->id));
        $this->assertDatabaseHas('push_tokens', ['id' => $token, 'active' => false]);
        $this->assertSame([], $this->provider->messages);
    }

    public function test_preferences_role_revocation_and_inactive_users_are_rechecked_before_send(): void
    {
        config(['integration.simulation' => true]);
        $this->register();
        foreach (['preference', 'membership', 'inactive'] as $case) {
            $row = $this->notify();
            if ($case === 'preference') {
                $p = $this->coop->settings()->first()->notification_preferences;
                $this->coop->settings()->update(['notification_preferences' => array_replace($p, ['gas_alerts' => false])]);
            } elseif ($case === 'membership') {
                $this->coop->users()->detach($this->user);
            } else {
                $this->user->update(['status' => 'inactive']);
            }
            $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
            $this->assertDatabaseHas('push_deliveries', ['id' => $row->id, 'status' => 'failed']);
            if ($case === 'preference') {
                $this->coop->settings()->update(['notification_preferences' => $p]);
            } elseif ($case === 'membership') {
                $this->coop->users()->attach($this->user);
            }
        }
        $this->assertSame([], $this->provider->messages);
    }

    public function test_notification_and_push_intent_rollback_together(): void
    {
        $this->register();
        $this->mock(PushQueue::class)->shouldReceive('stage')->andThrow(new \RuntimeException('queue failure'));
        try {
            $this->notify();
            $this->fail('Expected failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('queue failure', $error->getMessage());
        }
        $this->assertDatabaseCount('system_events', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('push_deliveries', 0);
    }

    public function test_expiry_and_active_lease_prevent_duplicate_push(): void
    {
        config(['integration.simulation' => true]);
        $this->register();
        $row = $this->notify();
        DB::table('push_deliveries')->where('id', $row->id)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addSecond()]);
        $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
        $this->travel(2)->seconds();
        DB::table('push_deliveries')->where('id', $row->id)->update(['expires_at' => now()]);
        $this->assertSame('skipped', app(PushDispatcher::class)->dispatch($row->id));
        $this->assertDatabaseHas('push_deliveries', ['id' => $row->id, 'status' => 'failed']);
        $this->assertSame([], $this->provider->messages);
    }
}
