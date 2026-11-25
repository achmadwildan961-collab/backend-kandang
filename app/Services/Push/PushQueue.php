<?php

namespace App\Services\Push;

use App\Models\Notification;
use App\Services\Integration\IntegrationPolicy;
use Illuminate\Support\Facades\DB;

class PushQueue
{
    public function allowed(Notification $notification): bool
    {
        $user = $notification->user()->first();
        $coop = $notification->coop()->first();
        if (! $user || $user->status !== 'active' || ! $coop || ($user->role !== 'admin' && ! $user->coops()->whereKey($coop->id)->exists())) {
            return false;
        }
        $p = $coop->settings()->firstOrFail()->notification_preferences;
        if (! ($p['push_notifications'] ?? true)) {
            return false;
        }
        $source = $notification->systemEvent?->metadata['source'] ?? 'configuration';

        return match ($source) {
            'indoor', 'indoor_low', 'outdoor' => ($p['critical_alerts'] ?? true) && ($p['temperature_alerts'] ?? true),
            'gas' => ($p['critical_alerts'] ?? true) && ($p['gas_alerts'] ?? true),
            'configuration' => $p['maintenance_alerts'] ?? false,
            default => true,
        };
    }

    public function stage(Notification $notification): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Push staging requires notification transaction.');
        }
        if (! $this->allowed($notification)) {
            return;
        }
        foreach (DB::table('push_tokens')->where('user_id', $notification->user_id)->where('active', true)->get() as $token) {
            $identity = ['notification_id' => $notification->id, 'push_token_id' => $token->id];
            if (DB::table('push_deliveries')->where($identity)->exists()) {
                continue;
            }
            DB::table('push_deliveries')->insert($identity + ['token_hash' => $token->token_hash,
                'message_id' => hash('sha256', "push:{$notification->id}:{$token->id}"),
                'status' => IntegrationPolicy::simulation() ? 'pending' : 'blocked',
                'reason' => IntegrationPolicy::simulation() ? null : 'provider_unverified',
                'next_attempt_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
