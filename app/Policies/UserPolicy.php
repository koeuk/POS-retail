<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    /** Only the superadmin bypasses: an admin may not touch admin accounts. */
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    /** Managing staff accounts is admin-only by default. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Users);
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        return $user->mayDo(Permission::Users, Action::Create);
    }

    public function update(User $user, User $model): bool
    {
        // Self, or the staff-management permission — but only the
        // superadmin (via before()) touches an admin or superadmin account.
        if ($user->id === $model->id) {
            return true;
        }

        return $user->mayDo(Permission::Users, Action::Update) && ! $model->isAdmin() && $this->inReach($user, $model);
    }

    /** Nobody may delete themselves, nor anyone but the superadmin an admin. */
    public function delete(User $user, User $model): bool
    {
        return $user->mayDo(Permission::Users, Action::Delete)
            && ! $model->isAdmin()
            && $user->id !== $model->id
            && $this->inReach($user, $model);
    }

    /**
     * Each side of a multi-vendor shop manages only its own accounts — the
     * admin shop's staff never touch a vendor's, nor one vendor another's.
     * Within its side, a vendor account manages cashiers only.
     */
    private function inReach(User $user, User $model): bool
    {
        if ($user->isAdmin()) {
            return true; // platform staff span every side
        }

        if ($model->vendor_id !== $user->vendor_id) {
            return false;
        }

        return ! $user->hasRole(Role::Vendor) || $model->hasRole(Role::Cashier);
    }
}
