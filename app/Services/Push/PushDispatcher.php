<?php

namespace App\Services\Push;

use App\Models\Notification;
use App\Services\Integration\IntegrationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushDispatcher
{
    public function __construct(private PushProvider $provider, private PushQueue $queue) {}

    public function dispatch(int $id): string
    {
        if (! IntegrationPolicy::simulation() || ! $this->provider instanceof FakePushProvider) {
            return 'blocked';
        }
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('push_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || ($row->lease_until && CarbonImmutable::parse($row->lease_until)->isFuture())
                || ($row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at)->isFuture())) {
                return null;
            }
            $token = DB::table('push_tokens')->where('id', $row->push_token_id)->lockForUpdate()->first();
            $notification = Notification::find($row->notification_id);
            $allowed = $token && $token->active && $notification && $token->user_id === $notification->user_id
                && hash_equals($row->token_hash, $token->token_hash) && $this->queue->allowed($notification);
            if (! $allowed || CarbonImmutable::parse($row->expires_at)->lte(now()) || $row->attempts >= max(1, config('integration.max_attempts'))) {
                DB::table('push_deliveries')->where('id', $id)->update(['status' => 'failed',
                    'reason' => ! $allowed ? 'authorization_preferences_or_token_changed' : 'expired_or_attempts_exhausted', 'updated_at' => now()]);

                return null;
            }
            $lease = (string) Str::uuid();
            DB::table('push_deliveries')->where('id', $id)->update(['attempts' => $row->attempts + 1, 'lease_token' => $lease,
                'lease_until' => now()->addSeconds(max(1, config('integration.lease_seconds'))), 'updated_at' => now()]);

            return [$row, $token, $notification, $lease];
        }, 3);
        if (! $claim) {
            return 'skipped';
        }
        [$row, $token, $notification, $lease] = $claim;
        try {
            $receipt = $this->provider->send(Crypt::decryptString($token->token), [
                'notification_id' => $notification->id, 'coop_id' => $notification->coop_id,
                'title' => $notification->title, 'body' => $notification->message, 'severity' => $notification->severity,
            ], $row->message_id);
            $changes = ['status' => 'provider_accepted', 'provider_receipt' => $receipt, 'reason' => null];
        } catch (InvalidPushToken) {
            DB::table('push_tokens')->where('id', $token->id)->where('token_hash', $row->token_hash)->update(['active' => false, 'updated_at' => now()]);
            $changes = ['status' => 'failed', 'reason' => 'invalid_token'];
        } catch (\Throwable) {
            $changes = ['status' => $row->attempts + 1 >= max(1, config('integration.max_attempts')) ? 'failed' : 'pending',
                'reason' => 'provider_failure', 'next_attempt_at' => now()->addSeconds(min(3600, max(1, config('integration.retry_seconds')) * (2 ** min(10, $row->attempts))))];
        }
        DB::table('push_deliveries')->where('id', $id)->where('lease_token', $lease)->update($changes + ['lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);

        return $changes['status'];
    }

    public function run(): int
    {
        $ids = DB::table('push_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $this->dispatch($id);
        }

        return $ids->count();
    }
}
