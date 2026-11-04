<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AssignmentRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['user_ids' => ['present', 'array', 'max:1000'], 'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'user')]];
    }
}
