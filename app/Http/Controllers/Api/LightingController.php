<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coop;
use App\Services\Bh1750Telemetry;
use App\Services\LightingContract;
use App\Services\LuxLightingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LightingController extends Controller
{
    private function supported(Coop $coop): bool
    {
        return LightingContract::verified() && $coop->devices()->whereIn('device_identifier', config('lighting.device_identifiers'))->exists();
    }

    public function show(Request $request, Coop $coop, LuxLightingSettings $lux, Bh1750Telemetry $telemetry)
    {
        Gate::authorize('view', $coop);
        $request->validate(['schema_version' => ['sometimes', 'integer', 'in:1,2']]);
        if ($request->integer('schema_version', 1) === 2) {
            return response()->json(['data' => [
                'schema_version' => 2, 'coop_id' => $coop->id,
                'capabilities' => ['telemetry' => LightingContract::bh1750Devices($coop)->exists(),
                    'configuration' => false, 'configuration_storage' => true, 'commands' => false],
                'measurement' => $telemetry->latest($coop), 'settings' => $lux->snapshot($coop),
                'actuator' => ['identifier' => 'lighting_lamp', 'desired_state' => null, 'reported_state' => null,
                    'reported_at' => null, 'feedback_kind' => 'unknown', 'device_id' => null],
                'command' => null, 'stale_after_seconds' => config('lighting.stale_after_seconds'),
                'unavailable_reason' => 'firmware_and_feedback_unverified',
            ]]);
        }
        $supported = $this->supported($coop);
        $reading = $supported ? $coop->readings()
            ->where('sensor_identifier', 'outdoor_light_adc')->where('sensor_type', 'outdoor_light')->where('unit', 'adc')
            ->where('measurement_version', config('lighting.measurement_version'))
            ->whereBetween('value', [(int) config('lighting.adc_min'), (int) config('lighting.adc_max')])
            ->whereIn('device_id', $coop->devices()->whereIn('device_identifier', config('lighting.device_identifiers'))->select('id'))
            ->where('recorded_at', '<=', now()->format('Y-m-d H:i:s.u'))->orderByDesc('recorded_at')->first() : null;
        $fresh = $reading && $reading->recorded_at->gte(now()->subSeconds(config('lighting.stale_after_seconds')));

        return response()->json(['data' => [
            'schema_version' => 1, 'deprecated' => true, 'replacement_schema_version' => 2, 'coop_id' => $coop->id,
            'capabilities' => ['telemetry' => $supported, 'configuration' => false, 'configuration_storage' => $supported && LightingContract::storesConfiguration(), 'commands' => false],
            'measurement' => ['sensor_identifier' => 'outdoor_light_adc', 'value' => $reading ? (float) $reading->value : null,
                'unit' => 'adc', 'legacy' => true, 'status' => ! $supported ? 'unsupported' : (! $reading ? 'missing' : ($fresh ? 'fresh' : 'stale')),
                'recorded_at' => $reading?->recorded_at->toISOString(), 'calibrated' => false,
                'measurement_version' => $reading?->measurement_version, 'calibration_version' => null],
            'settings' => $this->settings($coop),
            'actuator' => ['identifier' => 'lighting_lamp', 'desired_state' => null, 'reported_state' => null, 'reported_at' => null, 'feedback_kind' => 'unknown', 'device_id' => null],
            'command' => null, 'stale_after_seconds' => config('lighting.stale_after_seconds'),
            'unavailable_reason' => 'firmware_contract_unapproved',
        ]]);
    }

    private function settings(Coop $coop): ?array
    {
        $row = DB::table('coop_lighting_settings')->where('coop_id', $coop->id)->first();
        if (! $row) {
            return null;
        }

        return ['lighting_mode' => $row->lighting_mode, 'dark_threshold' => (float) $row->dark_threshold,
            'bright_threshold' => (float) $row->bright_threshold, 'threshold_unit' => $row->threshold_unit,
            'calibration_version' => $row->calibration_version, 'measurement_version' => $row->measurement_version, 'config_version' => (int) $row->config_version,
            'updated_at' => CarbonImmutable::parse($row->updated_at, 'UTC')->toISOString(),
            'updated_by' => $row->updated_by, 'delivery_status' => 'blocked'];
    }

    public function update(Request $request, Coop $coop, LuxLightingSettings $lux)
    {
        Gate::authorize('updateSettings', $coop);
        $request->validate(['schema_version' => ['sometimes', 'integer', 'in:1,2']]);
        if ($request->integer('schema_version', 1) === 2) {
            return $lux->update($request, $coop);
        }
        abort_unless($this->supported($coop) && LightingContract::storesConfiguration(), 409, 'Lighting configuration storage is unsupported.');
        $data = $request->validate([
            'lighting_mode' => ['required', 'in:automatic,manual'],
            'dark_threshold' => ['required', 'numeric', 'decimal:0,2', 'between:'.config('lighting.adc_min').','.config('lighting.adc_max')],
            'bright_threshold' => ['required', 'numeric', 'decimal:0,2', 'gt:dark_threshold', 'between:'.config('lighting.adc_min').','.config('lighting.adc_max')],
            'threshold_unit' => ['required', 'in:adc'],
            'measurement_version' => ['required', 'in:'.config('lighting.measurement_version')],
            'calibration_version' => ['nullable', 'prohibited'],
            'expected_config_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
        ]);
        // Reject unsupported fields instead of silently accepting a desired output.
        abort_if(array_diff(array_keys($request->all()), [...array_keys($data), 'schema_version']) !== [], 422, 'Unknown lighting setting fields.');

        return DB::transaction(function () use ($coop, $data, $request) {
            Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('coop_lighting_settings')->where('coop_id', $coop->id)->first();
            abort_if((int) ($current?->config_version ?? 0) !== (int) $data['expected_config_version'], 409, 'Stale lighting configuration.');
            $version = (int) $data['expected_config_version'] + 1;
            unset($data['expected_config_version']);
            $values = $data + ['config_version' => $version, 'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($current) {
                DB::table('coop_lighting_settings')->where('coop_id', $coop->id)->update($values);
            } else {
                DB::table('coop_lighting_settings')->insert($values + ['coop_id' => $coop->id, 'created_at' => now()]);
            }
            DB::table('lighting_configuration_outbox')->insert([
                'coop_id' => $coop->id, 'config_version' => $version,
                'payload' => json_encode(['schema_version' => 1, 'coop_id' => $coop->id, 'config_version' => $version, 'settings' => $data], JSON_THROW_ON_ERROR),
                'status' => 'blocked', 'blocked_reason' => 'firmware_contract_unapproved', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['data' => $this->settings($coop)]);
        });
    }

    public function command(Coop $coop)
    {
        Gate::authorize('updateSettings', $coop);

        // No command can be accepted, queued or published by this foundation.
        return response()->json(['message' => 'Lighting commands require an approved firmware and feedback contract.', 'code' => 'lighting_commands_unsupported'], 409);
    }
}
