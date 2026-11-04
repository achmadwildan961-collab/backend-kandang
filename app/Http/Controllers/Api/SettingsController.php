<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\CoopSettingResource;
use App\Models\Coop;
use App\Services\AlertEvaluationService;
use App\Services\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function show(Coop $coop)
    {
        Gate::authorize('view', $coop);

        return new CoopSettingResource($coop->settings()->firstOrFail());
    }

    public function update(SettingsRequest $request, Coop $coop, AlertEvaluationService $alerts, EventRecorder $events)
    {
        Gate::authorize('updateSettings', $coop);

        return DB::transaction(function () use ($request, $coop, $alerts, $events) {
            $coop = Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $settings = $coop->settings()->firstOrFail();
            $fields = ['minimum_outdoor_temperature', 'maximum_indoor_temperature', 'gas_threshold', 'notification_preferences'];
            $before = $settings->only($fields);
            $data = $request->safe()->only($fields);
            $data['notification_preferences'] = array_merge($settings->notification_preferences, $data['notification_preferences'] ?? []);
            $settings->fill($data);
            if ($settings->isDirty()) {
                $settings->save();
                $events->record($coop, 'configuration_changed', 'info', 'Monitoring settings updated', 'Monitoring thresholds or notification preferences changed.',
                    ['source' => 'configuration', 'changed_by' => $request->user()->id, 'before' => $before, 'after' => $settings->only($fields)]);
                $alerts->evaluate($coop);
            }

            return new CoopSettingResource($settings);
        });
    }
}
