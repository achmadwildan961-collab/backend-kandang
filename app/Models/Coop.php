<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coop extends Model
{
    protected $fillable = ['name', 'location', 'description'];

    protected function casts(): array
    {
        return [];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function settings()
    {
        return $this->hasOne(CoopSetting::class);
    }

    public function readings()
    {
        return $this->hasMany(SensorReading::class);
    }

    public function events()
    {
        return $this->hasMany(SystemEvent::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->isAdmin() ? $query : $query->whereHas('users', fn ($q) => $q->whereKey($user->id));
    }
}
