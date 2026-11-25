<?php

namespace App\Http\Requests;

class SettingsRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateSettings', $this->route('coop')) ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_config_version' => ['sometimes', 'integer', 'min:1'],
            'config_version' => ['prohibited'],
            'minimum_outdoor_temperature' => ['sometimes', 'numeric', 'decimal:0,2', 'between:-20,60'],
            'minimum_indoor_temperature' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:-40,60'],
            'maximum_indoor_temperature' => ['required', 'numeric', 'decimal:0,2', 'between:0,60'],
            'gas_threshold' => ['required', 'integer', 'between:1,4095'],
            'notification_preferences' => ['sometimes', 'array:push_notifications,email_notifications,critical_alerts,temperature_alerts,gas_alerts,maintenance_alerts'],
            'notification_preferences.*' => ['boolean'], 'alert_state' => ['prohibited'], 'coop_id' => ['prohibited'],
        ];
    }
}
