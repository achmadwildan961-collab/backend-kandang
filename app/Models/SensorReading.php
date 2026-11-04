<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensorReading extends Model
{
    protected $fillable = ['device_id', 'coop_id', 'sensor_identifier', 'sensor_type', 'value', 'unit', 'recorded_at'];

    protected function casts(): array
    {
        return ['value' => 'float', 'recorded_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    public function coop()
    {
        return $this->belongsTo(Coop::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
