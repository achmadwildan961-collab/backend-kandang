<?php

namespace App\Http\Requests;

class CoopRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']];
    }
}
