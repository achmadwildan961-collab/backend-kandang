<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\CoopSettingResource;
use App\Models\Coop;
use App\Services\AlertEvaluationService;
use App\Services\EventRecorder;
use App\Services\OperationalConfigurationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show(Coop $coop)
    {
        Gate::authorize('view', $coop);

        return new CoopSettingResource($coop->settings()->firstOrFail());
    }

    public function update(SettingsRequest $request, Coop $coop, AlertEvaluationService $alerts, EventRecorder $events, OperationalConfigurationOutbox $outbox)
    {
        Gate::authorize('updateSettings', $coop);

        return DB::transaction(function () use ($request, $coop, $alerts, $events, $outbox) {
            $coop = Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $settings = $coop->settings()->firstOrFail();
            if (! $request->has('expected_config_version')) {
                return response()->json(['message' => 'Fetch settings and submit expected_config_version before saving.',
                    'code' => 'configuration_version_required', 'current' => new CoopSettingResource($settings)], 428);
            }
            $expected = $request->integer('expected_config_version');
            if ($expected !== $settings->config_version) {
                return $this->conflict($settings);
            }
            $fields = ['minimum_indoor_temperature', 'minimum_outdoor_temperature', 'maximum_indoor_temperature', 'gas_threshold', 'notification_preferences'];
            $before = $settings->only($fields);
            $data = $request->safe()->only($fields);
            $data['notification_preferences'] = array_merge($settings->notification_preferences, $data['notification_preferences'] ?? []);
            $settings->fill($data);
            // Validate merged state under the lock, including legacy requests that omit minimum indoor.
            if ($settings->minimum_indoor_temperature !== null && $settings->minimum_indoor_temperature >= $settings->maximum_indoor_temperature) {
                throw ValidationException::withMessages(['minimum_indoor_temperature' => ['Minimum indoor temperature must be less than maximum indoor temperature.']]);
            }
            if ($settings->isDirty()) {
                $settings->config_version = $expected + 1;
                $settings->updated_at = now();
                // Conditional write also enforces CAS on databases without row-level SELECT locks.
                $updated = $settings->newQuery()->whereKey($settings->id)->where('config_version', $expected)->update($settings->getDirty());
                if ($updated !== 1) {
                    return $this->conflict($settings->fresh());
                }
                $settings->refresh();
                $outbox->stage($coop, $settings);
                $events->record($coop, 'configuration_changed', 'info', 'Monitoring settings updated', 'Monitoring thresholds or notification preferences changed.',
                    ['source' => 'configuration', 'changed_by' => $request->user()->id, 'previous_config_version' => $expected, 'config_version' => $settings->config_version, 'before' => $before, 'after' => $settings->only($fields)]);
                $alerts->evaluate($coop);
            }

            return new CoopSettingResource($settings->refresh());
        }, 3);
    }

    private function conflict($settings)
    {
        return response()->json(['message' => 'Settings changed. Reload and reconcile the draft before retrying.',
            'code' => 'configuration_version_conflict', 'current' => new CoopSettingResource($settings)], 409);
    }
}
