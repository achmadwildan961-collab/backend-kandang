<?php

namespace App\Services;

use App\Models\Coop;
use App\Services\Integration\ConfigurationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LuxLightingSettings
{
    private const FIELDS = ['lighting_mode', 'min_lux', 'max_lux', 'threshold_unit', 'measurement_version'];

    public function __construct(private EventRecorder $events) {}

    public function snapshot(Coop $coop): array
    {
        $row = DB::table('coop_lighting_lux_settings')->where('coop_id', $coop->id)->first();
        $configured = $row && $row->min_lux !== null && $row->max_lux !== null;

        return ['schema_version' => 2, 'coop_id' => $coop->id, 'lighting_mode' => $row?->lighting_mode,
            'min_lux' => $row?->min_lux === null ? null : (float) $row->min_lux,
            'max_lux' => $row?->max_lux === null ? null : (float) $row->max_lux,
            'threshold_unit' => 'lux', 'measurement_version' => LightingContract::BH1750_MEASUREMENT_VERSION,
            'config_version' => (int) ($row?->config_version ?? 0), 'policy_configured' => $configured,
            'policy_status' => $configured ? 'draft' : 'unconfigured', 'automation_enabled' => false,
            'updated_by' => $row?->updated_by, 'updated_at' => $row ? CarbonImmutable::parse($row->updated_at, 'UTC')->toISOString() : null,
            'delivery_status' => $row ? 'blocked' : 'not_queued', 'applied_config_version' => null, 'device_applied' => false];
    }

    public function update(Request $request, Coop $coop)
    {
        $range = 'between:0,'.LightingContract::BH1750_MAX_LUX;
        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:2'], 'lighting_mode' => ['required', 'in:auto,manual'],
            'min_lux' => ['present', 'nullable', 'numeric', 'decimal:0,2', $range],
            'max_lux' => ['present', 'nullable', 'numeric', 'decimal:0,2', $range],
            'threshold_unit' => ['required', 'in:lux'],
            'measurement_version' => ['required', 'in:'.LightingContract::BH1750_MEASUREMENT_VERSION],
            'expected_config_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
        ]);
        abort_if(array_diff(array_keys($request->all()), array_keys($data)) !== [], 422, 'Unknown lux lighting setting fields.');
        if (($data['min_lux'] === null) !== ($data['max_lux'] === null)
            || ($data['min_lux'] !== null && $data['min_lux'] >= $data['max_lux'])) {
            throw ValidationException::withMessages(['min_lux' => ['Set both lux thresholds with min_lux < max_lux, or set both to null to leave the policy unconfigured.']]);
        }
        $data['min_lux'] = $data['min_lux'] === null ? null : (float) $data['min_lux'];
        $data['max_lux'] = $data['max_lux'] === null ? null : (float) $data['max_lux'];

        return DB::transaction(function () use ($request, $coop, $data) {
            Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($coop);
            $expected = (int) $data['expected_config_version'];
            if ($before['config_version'] !== $expected) {
                return $this->conflict($coop);
            }
            $values = Arr::only($data, self::FIELDS);
            if ($before['config_version'] > 0 && $values === Arr::only($before, self::FIELDS)) {
                return response()->json(['data' => $before]);
            }
            $version = $expected + 1;
            $persist = $values + ['config_version' => $version, 'updated_by' => $request->user()->id, 'updated_at' => now()];
            if (DB::table('coop_lighting_lux_settings')->where('coop_id', $coop->id)->exists()) {
                $updated = DB::table('coop_lighting_lux_settings')->where('coop_id', $coop->id)->where('config_version', $expected)->update($persist);
                if ($updated !== 1) {
                    return $this->conflict($coop);
                }
            } else {
                DB::table('coop_lighting_lux_settings')->insert($persist + ['coop_id' => $coop->id, 'created_at' => now()]);
            }
            $key = hash('sha256', "lighting_lux:{$coop->id}:$version");
            DB::table('lighting_lux_configuration_outbox')->insert([
                'coop_id' => $coop->id, 'config_version' => $version, 'idempotency_key' => $key,
                'payload' => json_encode(['schema_version' => 2, 'contract_status' => 'draft', 'coop_id' => $coop->id,
                    'config_version' => $version, 'message_id' => $key, 'sensor_identifier' => 'outdoor_light_lux',
                    'actuator_identifier' => 'lighting_lamp', 'actuator_device_id' => null, 'settings' => $values], JSON_THROW_ON_ERROR),
                'status' => 'blocked', 'blocked_reason' => 'firmware_and_feedback_unverified', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $after = $this->snapshot($coop);
            app(ConfigurationDelivery::class)->stage($coop, 'lighting_lux');
            $this->events->record($coop, 'configuration_changed', 'info', 'Lighting lux draft updated', 'BH1750 lighting policy stored; device delivery remains blocked.',
                ['source' => 'configuration', 'domain' => 'lighting_lux', 'changed_by' => $request->user()->id, 'before' => $before, 'after' => $after]);

            return response()->json(['data' => $after]);
        }, 3);
    }

    private function conflict(Coop $coop)
    {
        return response()->json(['code' => 'lighting_version_conflict', 'message' => 'Lux lighting settings changed. Reload and reconcile the draft.',
            'current' => $this->snapshot($coop)], 409);
    }
}
