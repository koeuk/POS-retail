<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    /** Managers only ever see their own store. */
    public function view(User $user, Store $store): bool
    {
        return $user->isManager() && $user->store_id === $store->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Store $store): bool
    {
        return false;
    }

    public function delete(User $user, Store $store): bool
    {
        return false;
    }
}
