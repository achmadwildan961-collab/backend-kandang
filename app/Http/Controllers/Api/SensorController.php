<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChartHistoryRequest;
use App\Http\Requests\HistoryRequest;
use App\Http\Resources\SensorReadingResource;
use App\Http\Resources\SensorSnapshotResource;
use App\Models\Coop;
use App\Services\MonitoringContract;
use App\Services\SensorAggregationService;
use App\Services\SensorHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SensorController extends Controller
{
    public function export(ChartHistoryRequest $request, Coop $coop, SensorHistoryService $history)
    {
        Gate::authorize('view', $coop);
        [$from, $to] = $request->dateBounds();
        // Same complete-batch aggregation as the graph; at most 400 auto buckets.
        // Build the bounded result before sending headers so errors stay JSON.
        $chart = $history->chart($coop, $from, $to, null, 500, 1, $request->integer('schema_version', 1));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        if ($request->integer('schema_version', 1) === 2) {
            fputcsv($stream, ['Timestamp (UTC)', 'Indoor Average Temperature (°C)', 'Outdoor Temperature (°C)', 'Indoor Average Humidity (%)', 'Outdoor Humidity (%)', 'Gas (ADC)', 'Light Intensity (lux)', 'Indoor Temperature Complete Samples', 'Indoor Humidity Complete Samples', 'Legacy Indoor Humidity (%)'], ',', '"', '', "\r\n");
            foreach ($chart['data'] as $row) {
                fputcsv($stream, [$row['recorded_at'], $row['indoor_average'], $row['outdoor_temperature'], $row['indoor_humidity'], $row['outdoor_humidity'], $row['gas_adc'], $row['outdoor_light_lux'], $row['indoor_sample_count'], $row['indoor_humidity_sample_count'], $row['legacy_indoor_humidity']], ',', '"', '', "\r\n");
            }
        } else {
            fputcsv($stream, ['Timestamp (UTC)', 'Indoor Average Temperature (°C)', 'Outdoor Temperature (°C)', 'Humidity (%)', 'Gas (ADC)'], ',', '"', '', "\r\n");
            foreach ($chart['data'] as $row) {
                fputcsv($stream, [$row['recorded_at'], $row['indoor_average'], $row['outdoor_temperature'], $row['indoor_humidity'], $row['gas_adc']], ',', '"', '', "\r\n");
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $filename = 'coop-'.$coop->id.'-sensors-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Schema-Version' => (string) $request->integer('schema_version', 1),
            'X-Export-Rows' => (string) $chart['meta']['total'],
            'X-Aggregation-Seconds' => (string) $chart['meta']['interval_seconds'],
        ]);
    }

    public function chart(ChartHistoryRequest $request, Coop $coop, SensorHistoryService $history)
    {
        Gate::authorize('view', $coop);
        [$from, $to] = $request->dateBounds();

        return response()->json($history->chart($coop, $from, $to, $request->has('interval_seconds') ? $request->integer('interval_seconds') : null, $request->integer('per_page', 100), schemaVersion: $request->integer('schema_version', 1)));
    }

    public function latest(Request $request, Coop $coop, SensorAggregationService $sensors)
    {
        Gate::authorize('view', $coop);

        $request->validate(['schema_version' => ['sometimes', 'integer', 'in:1,2']]);

        return new SensorSnapshotResource(app(MonitoringContract::class)->snapshot($coop, $sensors->latest($coop), $request->integer('schema_version', 1)));
    }

    public function indoor(Coop $coop, SensorAggregationService $sensors)
    {
        Gate::authorize('view', $coop);
        $data = $sensors->latest($coop);

        return new SensorSnapshotResource(['coop_id' => $coop->id, 'as_of' => $data['as_of']] + $data['indoor_temperature']);
    }

    public function history(HistoryRequest $request, Coop $coop)
    {
        Gate::authorize('view', $coop);
        [$from,$to] = $request->dateBounds();
        $query = $coop->readings()->whereBetween('recorded_at', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')]);
        foreach (['sensor_identifier', 'sensor_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }

        return SensorReadingResource::collection($query->orderByDesc('recorded_at')->orderByDesc('id')->paginate($request->integer('per_page', 50))->withQueryString());
    }
}
