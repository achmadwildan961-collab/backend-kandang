<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class DeviceRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['device_identifier' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('devices')->ignore($this->route('device')?->id)], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'string', 'max:50'], 'connection_status' => ['sometimes', Rule::in(['online', 'offline', 'unknown'])], 'coop_id' => ['prohibited'], 'last_seen_at' => ['prohibited']];
    }
}
