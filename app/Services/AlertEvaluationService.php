<?php

namespace App\Services;

use App\Models\Coop;
use Illuminate\Support\Facades\DB;

class AlertEvaluationService
{
    public function __construct(private SensorAggregationService $sensors, private EventRecorder $events) {}

    // Shared transaction lock serializes evaluation across API and future MQTT workers.
    public function evaluate(Coop $coop): void
    {
        DB::transaction(function () use ($coop) {
            $locked = Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $settings = $locked->settings()->firstOrFail();
            $state = $settings->alert_state ?? [];
            $data = $this->sensors->latest($locked);
            $conditions = [
                'indoor' => [$data['indoor_temperature']['status'], 'high_indoor_temperature', 'High Indoor Temperature', $data['indoor_temperature']['average'], $settings->maximum_indoor_temperature, 'celsius'],
                'outdoor' => [$data['outdoor_temperature']['condition'], 'low_outdoor_temperature', 'Low Outdoor Temperature', $data['outdoor_temperature']['value'], $settings->minimum_outdoor_temperature, 'celsius'],
                'gas' => [$data['gas']['condition'], 'high_gas_level', 'High Gas Level', $data['gas']['value'], $settings->gas_threshold, 'adc'],
            ];
            foreach ($conditions as $source => [$status,$type,$title,$value,$threshold,$unit]) {
                // Missing/stale data is unknown, never a recovery or a five-sensor alert.
                if ($status === 'unknown') {
                    continue;
                }
                $active = $status === 'warning';
                $previous = $state[$source] ?? false;
                if ($active !== $previous) {
                    $this->events->record($locked, $active ? $type : 'sensor_recovered', $active ? ($source === 'gas' ? 'critical' : 'warning') : 'info',
                        $active ? $title : 'Sensor Recovered', "$source reading is $value $unit; threshold $threshold $unit. ".($active ? 'Outside the configured limit.' : 'Returned to normal.'),
                        ['source' => $source, 'value' => $value, 'threshold' => $threshold, 'unit' => $unit]);
                }
                $state[$source] = $active;
            }
            foreach ($locked->devices()->get() as $device) {
                if ($device->connection_status === 'unknown') {
                    continue;
                }
                $key = 'device_'.$device->id;
                $offline = $device->connection_status === 'offline';
                $previous = $state[$key] ?? false;
                if ($offline !== $previous) {
                    $this->events->record($locked, $offline ? 'device_disconnected' : 'device_reconnected', $offline ? 'warning' : 'info',
                        $offline ? 'Device Disconnected' : 'Device Reconnected', "{$device->name} is {$device->connection_status}.", ['source' => $key, 'connection_status' => $device->connection_status], $device->id);
                }
                $state[$key] = $offline;
            }
            $settings->update(['alert_state' => $state]);
        }, 3);
    }
}
