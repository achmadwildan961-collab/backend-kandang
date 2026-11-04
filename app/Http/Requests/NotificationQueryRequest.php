<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class NotificationQueryRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['filter' => ['sometimes', Rule::in(['all', 'read', 'unread'])]]);
    }
}
