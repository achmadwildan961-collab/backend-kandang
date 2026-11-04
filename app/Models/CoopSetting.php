<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopSetting extends Model
{
    protected $fillable = ['coop_id', 'minimum_outdoor_temperature', 'maximum_indoor_temperature', 'gas_threshold', 'notification_preferences', 'alert_state'];

    protected function casts(): array
    {
        return ['minimum_outdoor_temperature' => 'float', 'maximum_indoor_temperature' => 'float', 'gas_threshold' => 'integer', 'notification_preferences' => 'array', 'alert_state' => 'array'];
    }

    protected $hidden = ['alert_state'];

    public function coop()
    {
        return $this->belongsTo(Coop::class);
    }
}
