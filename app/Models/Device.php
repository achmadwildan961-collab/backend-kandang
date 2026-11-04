<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['coop_id', 'device_identifier', 'name', 'type', 'connection_status', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    public function coop()
    {
        return $this->belongsTo(Coop::class);
    }

    public function readings()
    {
        return $this->hasMany(SensorReading::class);
    }

    public function events()
    {
        return $this->hasMany(SystemEvent::class);
    }
}
