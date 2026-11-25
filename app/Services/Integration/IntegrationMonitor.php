<?php

namespace App\Services\Integration;

use App\Models\Coop;
use App\Services\LuxLightingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IntegrationMonitor
{
    public function reports(Coop $coop): array
    {
        return $coop->devices()->get()->filter(fn ($d) => $d->type === 'esp32')->values()->map(function ($device) use ($coop) {
            $row = DB::table('actuator_reports')->where('coop_id', $coop->id)->where('device_id', $device->id)->first();
            $current = $row && DB::table('integration_sessions')->where('device_id', $device->id)->where('coop_id', $coop->id)
                ->where('boot_id', $row->boot_id)->where('current', true)->exists();
            $fresh = $current && IntegrationPolicy::online($device) && CarbonImmutable::parse($row->reported_at)->lte(now())
                && CarbonImmutable::parse($row->reported_at)->gte(now()->subSeconds(max(1, config('integration.report_seconds'))));

            return ['device_id' => $device->id, 'online' => IntegrationPolicy::online($device),
                'status' => ! $row ? 'missing' : ($fresh ? 'fresh' : 'unavailable'),
                'states' => $fresh ? json_decode($row->states, true) : array_fill_keys(['heating_lamp', 'ventilation_fan_1', 'ventilation_fan_2', 'lighting_lamp'], null),
                'feedback_kind' => $row?->feedback_kind, 'reported_at' => $row?->reported_at,
                // A device's feedback label is not hardware acceptance evidence.
                'physical_verified' => false];
        })->all();
    }

    public function deliveries(Coop $coop): array
    {
        $rows = DB::table('integration_deliveries')->where('coop_id', $coop->id)->orderByDesc('id')->limit(100)->get();

        return ['physical_commands_enabled' => false, 'external_delivery_enabled' => false,
            'stored_versions' => ['operational' => $coop->settings()->firstOrFail()->config_version,
                'lighting_lux' => app(LuxLightingSettings::class)->snapshot($coop)['config_version']],
            'deliveries' => $rows->map(function ($row) use ($coop) {
                $device = $coop->devices()->find($row->device_id);
                $current = $device && app(ConfigurationDelivery::class)->isCurrent($row, $device);
                $online = $device && IntegrationPolicy::online($device);
                $sessionMatches = $row->boot_id && DB::table('integration_sessions')->where('coop_id', $coop->id)
                    ->where('device_id', $row->device_id)->where('current', true)->where('boot_id', $row->boot_id)->exists();

                return ['id' => $row->id, 'device_id' => $row->device_id, 'domain' => $row->domain,
                    'config_version' => $row->config_version, 'status' => $row->status, 'reason' => $row->reason,
                    'current' => (bool) $current, 'device_online' => (bool) $online,
                    'applied_current' => $current && $online && $sessionMatches && $row->status === 'applied' && CarbonImmutable::parse($row->expires_at)->isFuture(),
                    'attempts' => $row->attempts, 'published_at' => $row->published_at, 'acknowledged_at' => $row->acknowledged_at,
                    'applied_at' => $row->applied_at, 'expires_at' => $row->expires_at];
            })->all()];
    }
}
