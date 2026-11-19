<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Units);
    }

    public function create(User $user): bool
    {
        return $user->mayDo(Permission::Units, Action::Create);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->mayDo(Permission::Units, Action::Update);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->mayDo(Permission::Units, Action::Delete);
    }
}
