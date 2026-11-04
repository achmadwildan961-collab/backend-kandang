<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        return $user->canAccessCoop($device->coop);
    }

    public function update(User $user, Device $device): bool
    {
        return $user->isAdmin();
    }
}
