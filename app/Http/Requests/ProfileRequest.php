<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['prohibited'], 'status' => ['prohibited'], 'password' => ['prohibited']];
    }
}
