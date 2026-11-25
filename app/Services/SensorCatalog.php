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

        $items['outdoor_light_lux'] = ['sensor_identifier' => 'outdoor_light_lux', 'sensor_type' => 'outdoor_light',
            'unit' => 'lux', 'min' => 0, 'max' => LightingContract::BH1750_MAX_LUX, 'sensor_model' => 'BH1750'];

        if (LightingContract::verified()) {
            $items['outdoor_light_adc'] = ['sensor_identifier' => 'outdoor_light_adc', 'sensor_type' => 'outdoor_light', 'unit' => 'adc', 'min' => (int) config('lighting.adc_min'), 'max' => (int) config('lighting.adc_max')];
        }

        return $items;
    }
}
