<?php

namespace App\Services;

use App\Models\Coop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SensorHistoryService
{
    public function chart(Coop $coop, CarbonImmutable $from, CarbonImmutable $to, ?int $interval, int $perPage, ?int $page = null): array
    {
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $interval ??= collect([60, 300, 900, 3600, 21600, 86400])->first(fn ($s) => ceil($duration / $s) + 1 <= 400) ?? 86400;
        if (ceil($duration / $interval) + 1 > 500) {
            throw ValidationException::withMessages(['interval_seconds' => ['Choose an interval with at most 500 time buckets.']]);
        }
        // Filter by the indexed coop/time range before grouping. Aggregate exact
        // instants first: asynchronous readings may never complete one another.
        $samples = DB::table('sensor_readings')->where('coop_id', $coop->id)
            ->whereBetween('recorded_at', [$from->format($from->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])
            ->select('recorded_at')->groupBy('recorded_at');
        $valid = [];
        foreach (SensorCatalog::series() as $id => $spec) {
            $valid[$id] = "(sensor_identifier = '$id' AND sensor_type = '{$spec['sensor_type']}' AND unit = '{$spec['unit']}' AND value BETWEEN {$spec['min']} AND {$spec['max']})";
        }
        $indoor = implode(' OR ', array_intersect_key($valid, array_flip(SensorCatalog::indoorIdentifiers())));
        $samples->selectRaw("CASE WHEN COUNT(DISTINCT CASE WHEN $indoor THEN sensor_identifier END) = 5 THEN AVG(CASE WHEN $indoor THEN value END) END AS indoor_average");
        foreach (['outdoor_temperature', 'indoor_humidity', 'gas_adc'] as $metric) {
            $samples->selectRaw("MAX(CASE WHEN {$valid[$metric]} THEN value END) AS $metric");
        }
        $summary = DB::query()->fromSub(clone $samples, 'samples')
            ->selectRaw('AVG(indoor_average) AS indoor_average, MAX(indoor_average) AS indoor_maximum, COUNT(indoor_average) AS indoor_sample_count, AVG(outdoor_temperature) AS outdoor_temperature, AVG(indoor_humidity) AS indoor_humidity, AVG(gas_adc) AS gas_adc')->first();
        $bucket = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%s', recorded_at) AS INTEGER) / $interval" : "FLOOR(UNIX_TIMESTAMP(recorded_at) / $interval)";
        $buckets = DB::query()->fromSub($samples, 'samples')
            ->selectRaw("$bucket AS bucket, MIN(recorded_at) AS recorded_at, AVG(indoor_average) AS indoor_average, COUNT(indoor_average) AS indoor_sample_count, AVG(outdoor_temperature) AS outdoor_temperature, AVG(indoor_humidity) AS indoor_humidity, AVG(gas_adc) AS gas_adc")
            ->groupBy('bucket')->orderBy('bucket')->paginate($perPage, ['*'], 'page', $page);
        $cast = fn ($row) => collect((array) $row)->map(fn ($value, $key) => $value === null ? null : ($key === 'indoor_sample_count' ? (int) $value : round((float) $value, 4)))->all();
        $data = $buckets->getCollection()->map(function ($row) use ($cast) {
            $time = CarbonImmutable::parse($row->recorded_at, 'UTC')->toISOString();
            unset($row->recorded_at, $row->bucket);

            return ['recorded_at' => $time] + $cast($row);
        });

        return ['data' => $data, 'meta' => ['current_page' => $buckets->currentPage(), 'last_page' => $buckets->lastPage(), 'per_page' => $buckets->perPage(), 'total' => $buckets->total(), 'interval_seconds' => $interval], 'summary' => $cast($summary)];
    }
}
