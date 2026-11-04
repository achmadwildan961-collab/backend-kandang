<?php

namespace App\Http\Requests;

use App\Services\SensorCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HistoryRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'from' => ['sometimes', 'date'],
            'to' => array_merge(['sometimes', 'date'], $this->filled('from') ? ['after_or_equal:from'] : []),
            'sensor_identifier' => ['sometimes', Rule::in(array_keys(SensorCatalog::series()))],
            'sensor_type' => ['sometimes', Rule::in(['indoor_temperature', 'outdoor_temperature', 'indoor_humidity', 'outdoor_humidity', 'gas'])],
            'event_type' => ['sometimes', Rule::in(['high_indoor_temperature', 'low_outdoor_temperature', 'high_gas_level', 'sensor_recovered', 'device_disconnected', 'device_reconnected', 'configuration_changed'])],
        ]);
    }

    public function dateBounds(): array
    {
        $to = $this->filled('to') ? CarbonImmutable::parse($this->validated('to'))->utc() : now()->toImmutable();
        if ($this->filled('to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->validated('to'))) {
            $to = $to->endOfDay();
        }
        $from = $this->filled('from') ? CarbonImmutable::parse($this->validated('from'))->utc() : $to->subDays(29)->startOfDay();
        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => ['Select an ordered date range of at most 366 days.']]);
        }

        return [$from, $to];
    }
}
