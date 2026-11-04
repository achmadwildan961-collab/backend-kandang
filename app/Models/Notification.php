<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'coop_id', 'system_event_id', 'type', 'title', 'message', 'severity', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coop()
    {
        return $this->belongsTo(Coop::class);
    }

    public function systemEvent()
    {
        return $this->belongsTo(SystemEvent::class);
    }
}
