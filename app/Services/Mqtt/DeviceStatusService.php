<?php

namespace App\Services\Mqtt;

use App\Models\Coop;
use App\Models\Device;
use App\Services\AlertEvaluationService;
use Illuminate\Support\Facades\DB;

class DeviceStatusService
{
    public function __construct(private AlertEvaluationService $alerts) {}

    public function check(): int
    {
        $changed = 0;
        Device::select(['id', 'coop_id'])->orderBy('id')->chunkById(100, function ($devices) use (&$changed) {
            foreach ($devices as $device) {
                $changed += DB::transaction(function () use ($device) {
                    $coop = Coop::whereKey($device->coop_id)->lockForUpdate()->first();
                    $locked = Device::whereKey($device->id)->lockForUpdate()->first();
                    if (! $coop || ! $locked) {
                        return 0;
                    }
                    $status = ! $locked->last_seen_at ? 'unknown' : ($locked->last_seen_at->lt(now()->subSeconds(max(1, config('mqtt.offline_after_seconds')))) ? 'offline' : $locked->connection_status);
                    if ($status === $locked->connection_status) {
                        return 0;
                    }
                    $locked->update(['connection_status' => $status]);
                    $this->alerts->evaluate($coop);

                    return 1;
                }, 3);
            }
        });

        return $changed;
    }
}
