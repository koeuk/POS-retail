<?php

namespace App\Policies;

use App\Models\Category;
use App\Enums\Permission;
use App\Models\User;

class CategoryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** Cashiers filter the POS grid by category. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::Categories);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasPermission(Permission::Categories);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->hasPermission(Permission::Categories);
    }
}
