<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** Managing staff accounts is admin-only. */
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
        // Self, or the staff-management permission — but a non-admin can
        // never touch an admin account.
        if ($user->id === $model->id) {
            return true;
        }

        return $user->mayDo(Permission::Users, Action::Update) && ! $model->isAdmin();
    }

    /** Nobody may delete themselves, and only admins may delete anyone. */
    public function delete(User $user, User $model): bool
    {
        return $user->mayDo(Permission::Users, Action::Delete) && ! $model->isAdmin() && $user->id !== $model->id;
    }
}
