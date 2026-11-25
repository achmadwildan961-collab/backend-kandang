<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ChartHistoryRequest extends HistoryRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'schema_version' => ['sometimes', 'integer', Rule::in([1, 2])],
            'interval_seconds' => ['sometimes', 'integer', Rule::in([60, 300, 900, 3600, 21600, 86400])],
        ];
    }
}
