<?php

namespace App\Services;

class SensorCatalog
{
    public static function indoorIdentifiers(): array
    {
        return array_map(fn ($i) => "indoor_dht22_$i", range(1, 5));
    }

    public static function series(): array
    {
        $items = [];
        foreach (self::indoorIdentifiers() as $id) {
            $items[$id] = ['sensor_identifier' => $id, 'sensor_type' => 'indoor_temperature', 'unit' => 'celsius', 'min' => -40, 'max' => 80];
        }
        foreach ([
            'outdoor_temperature' => ['outdoor_temperature', 'celsius', -40, 80],
            'indoor_humidity' => ['indoor_humidity', 'percent', 0, 100],
            'outdoor_humidity' => ['outdoor_humidity', 'percent', 0, 100],
            'gas_adc' => ['gas', 'adc', 0, 4095],
        ] as $id => [$type,$unit,$min,$max]) {
            $items[$id] = ['sensor_identifier' => $id, 'sensor_type' => $type, 'unit' => $unit, 'min' => $min, 'max' => $max];
        }

        return $items;
    }
}
