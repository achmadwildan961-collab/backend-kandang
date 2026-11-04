<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UserRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')?->id)], 'phone' => ['nullable', 'string', 'max:30'], 'password' => $this->isMethod('POST') ? ['required', 'string', 'min:12', 'max:255', 'confirmed'] : ['prohibited'], 'role' => ['prohibited'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]];
    }
}
