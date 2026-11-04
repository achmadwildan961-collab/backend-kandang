<?php

namespace App\Policies;

use App\Models\Coop;
use App\Models\User;

class CoopPolicy
{
    public function view(User $user, Coop $coop): bool
    {
        return $user->canAccessCoop($coop);
    }

    public function updateSettings(User $user, Coop $coop): bool
    {
        return $user->canAccessCoop($coop);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Coop $coop): bool
    {
        return $user->isAdmin();
    }
}
