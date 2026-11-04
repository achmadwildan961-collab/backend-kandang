<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StatusRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'inactive'])], 'role' => ['prohibited']];
    }
}
