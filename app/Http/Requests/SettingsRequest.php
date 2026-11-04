<?php

namespace App\Http\Requests;

class SettingsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'minimum_outdoor_temperature' => ['required', 'numeric', 'decimal:0,2', 'between:-20,60'],
            'maximum_indoor_temperature' => ['required', 'numeric', 'decimal:0,2', 'between:0,60'],
            'gas_threshold' => ['required', 'integer', 'between:1,4095'],
            'notification_preferences' => ['sometimes', 'array:push_notifications,email_notifications,critical_alerts,temperature_alerts,gas_alerts,maintenance_alerts'],
            'notification_preferences.*' => ['boolean'], 'alert_state' => ['prohibited'], 'coop_id' => ['prohibited'],
        ];
    }
}
