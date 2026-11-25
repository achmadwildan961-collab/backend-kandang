<?php

namespace App\Services;

use App\Models\Coop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SensorHistoryService
{
    public function chart(Coop $coop, CarbonImmutable $from, CarbonImmutable $to, ?int $interval, int $perPage, ?int $page = null, int $schemaVersion = 1): array
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
        $lightDevices = LightingContract::bh1750Devices($coop)->pluck('id')->map(fn ($id) => (int) $id)->implode(',') ?: '0';
        $lightVersion = LightingContract::BH1750_MEASUREMENT_VERSION;
        $valid['outdoor_light_lux'] .= " AND measurement_version = '$lightVersion' AND device_id IN ($lightDevices)";
        foreach (HumidityContract::indoorIdentifiers() as $id) {
            $valid[$id] .= ' AND '.HumidityContract::sqlConstraint($coop, $id);
        }
        $valid['outdoor_humidity'] .= ' AND '.HumidityContract::sqlConstraint($coop, 'outdoor_humidity');
        if ($schemaVersion === 2) {
            $valid['outdoor_temperature'] .= ' AND '.HumidityContract::sqlConstraint($coop, 'outdoor_temperature');
            $indoorDevices = implode(',', $coop->devices()->get()->filter(fn ($d) => $d->type === 'esp32')->modelKeys()) ?: '0';
            foreach ([...SensorCatalog::indoorIdentifiers(), 'gas_adc'] as $id) {
                $valid[$id] .= " AND device_id IN ($indoorDevices)";
            }
        }
        $humidity = implode(' OR ', array_map(fn ($condition) => "($condition)", array_intersect_key($valid, array_flip(HumidityContract::indoorIdentifiers()))));
        $samples->selectRaw("CASE WHEN COUNT(DISTINCT CASE WHEN $humidity THEN sensor_identifier END) = 5
            AND COUNT(CASE WHEN $humidity THEN 1 END) = 5
            AND COUNT(DISTINCT CASE WHEN $humidity THEN device_id END) = 1
            AND COUNT(DISTINCT CASE WHEN $humidity THEN batch_key END) = 1
            THEN AVG(CASE WHEN $humidity THEN value END) END AS indoor_humidity_average");
        $indoor = implode(' OR ', array_intersect_key($valid, array_flip(SensorCatalog::indoorIdentifiers())));
        $samples->selectRaw("CASE WHEN COUNT(DISTINCT CASE WHEN $indoor THEN sensor_identifier END) = 5
            AND COUNT(CASE WHEN $indoor THEN 1 END) = 5
            AND COUNT(DISTINCT CASE WHEN $indoor THEN device_id END) = 1
            AND COUNT(DISTINCT CASE WHEN $indoor THEN COALESCE(batch_key, 'legacy') END) = 1
            THEN AVG(CASE WHEN $indoor THEN value END) END AS indoor_average");
        foreach (['outdoor_temperature', 'indoor_humidity', 'outdoor_humidity', 'gas_adc', 'outdoor_light_lux'] as $metric) {
            $samples->selectRaw("MAX(CASE WHEN {$valid[$metric]} THEN value END) AS $metric");
        }
        $summary = DB::query()->fromSub(clone $samples, 'samples')
            ->selectRaw('AVG(indoor_average) AS indoor_average, MAX(indoor_average) AS indoor_maximum, COUNT(indoor_average) AS indoor_sample_count, AVG(outdoor_temperature) AS outdoor_temperature, AVG(indoor_humidity) AS indoor_humidity, AVG(indoor_humidity_average) AS indoor_humidity_average, COUNT(indoor_humidity_average) AS indoor_humidity_sample_count, AVG(outdoor_humidity) AS outdoor_humidity, AVG(gas_adc) AS gas_adc, AVG(outdoor_light_lux) AS outdoor_light_lux')->first();
        $bucket = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%s', recorded_at) AS INTEGER) / $interval" : "FLOOR(UNIX_TIMESTAMP(recorded_at) / $interval)";
        $buckets = DB::query()->fromSub($samples, 'samples')
            ->selectRaw("$bucket AS bucket, MIN(recorded_at) AS recorded_at, AVG(indoor_average) AS indoor_average, COUNT(indoor_average) AS indoor_sample_count, AVG(outdoor_temperature) AS outdoor_temperature, AVG(indoor_humidity) AS indoor_humidity, AVG(indoor_humidity_average) AS indoor_humidity_average, COUNT(indoor_humidity_average) AS indoor_humidity_sample_count, AVG(outdoor_humidity) AS outdoor_humidity, AVG(gas_adc) AS gas_adc, AVG(outdoor_light_lux) AS outdoor_light_lux")
            ->groupBy('bucket')->orderBy('bucket')->paginate($perPage, ['*'], 'page', $page);
        $cast = fn ($row) => collect((array) $row)->map(fn ($value, $key) => $value === null ? null : (str_ends_with($key, '_sample_count') ? (int) $value : round((float) $value, 4)))->all();
        $data = $buckets->getCollection()->map(function ($row) use ($cast) {
            $time = CarbonImmutable::parse($row->recorded_at, 'UTC')->toISOString();
            unset($row->recorded_at, $row->bucket);

            return ['recorded_at' => $time] + $cast($row);
        });

        $adapt = function (array $row) use ($schemaVersion) {
            if ($schemaVersion === 2) {
                $row['legacy_indoor_humidity'] = $row['indoor_humidity'];
                $row['indoor_humidity'] = $row['indoor_humidity_average'];
            }

            return $row;
        };
        $data = $data->map($adapt);

        return ['data' => $data, 'meta' => ['schema_version' => $schemaVersion, 'humidity_unit' => 'percent', 'humidity_unit_symbol' => '%', 'humidity_aggregation' => 'complete_message_batch_before_bucket', 'legacy_indoor_humidity' => 'single_channel_not_five_sensor_average', 'current_page' => $buckets->currentPage(), 'last_page' => $buckets->lastPage(), 'per_page' => $buckets->perPage(), 'total' => $buckets->total(), 'interval_seconds' => $interval], 'summary' => $adapt($cast($summary))];
    }
}
