<?php

namespace App\Http\Requests;

class PaginationRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
