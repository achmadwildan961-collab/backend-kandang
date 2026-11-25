<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['installation_id' => ['required', 'uuid'], 'platform' => ['required', 'in:android,ios,web'],
            'token' => ['required', 'string', 'min:16', 'max:4096']]);
        abort_if(array_diff(array_keys($request->all()), array_keys($data)) !== [], 422);

        return DB::transaction(function () use ($request, $data) {
            // Account lock serializes installation creation/rotation for this user.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $data['token']);
            $existing = DB::table('push_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            abort_if($existing && ($existing->user_id !== $request->user()->id || $existing->installation_id !== $data['installation_id']), 409, 'Token already registered.');
            $identity = ['user_id' => $request->user()->id, 'installation_id' => $data['installation_id']];
            $old = DB::table('push_tokens')->where($identity)->first();
            $values = ['platform' => $data['platform'], 'token' => Crypt::encryptString($data['token']), 'token_hash' => $hash,
                'active' => true, 'last_seen_at' => now(), 'updated_at' => now()];
            if ($old) {
                DB::table('push_tokens')->where('id', $old->id)->update($values);
                $id = $old->id;
            } else {
                $id = DB::table('push_tokens')->insertGetId($identity + $values + ['created_at' => now()]);
            }

            return response()->json(['data' => ['id' => $id, 'installation_id' => $data['installation_id'], 'active' => true,
                'delivery_enabled' => false, 'provider' => 'disabled']], $old ? 200 : 201);
        }, 3);
    }

    public function destroy(Request $request, int $token)
    {
        $row = DB::table('push_tokens')->where('user_id', $request->user()->id)->where('id', $token)->first();
        abort_unless($row, 404);
        DB::table('push_tokens')->where('id', $row->id)->update(['active' => false, 'updated_at' => now()]);

        return response()->noContent();
    }

    public function deliveries(Request $request)
    {
        return response()->json(['data' => DB::table('push_deliveries')->join('notifications', 'notifications.id', '=', 'push_deliveries.notification_id')
            ->where('notifications.user_id', $request->user()->id)->orderByDesc('push_deliveries.id')->limit(100)
            ->get(['push_deliveries.id', 'notification_id', 'push_deliveries.status', 'push_deliveries.reason', 'attempts', 'push_deliveries.updated_at'])]);
    }
}
