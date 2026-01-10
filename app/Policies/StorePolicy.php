<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    /**
     * Determine if the user can view any stores.
     */
    public function viewAny(User $user): bool
    {
        return $user->storeUsers()->exists();
    }

    /**
     * Determine if the user can view the store.
     */
    public function view(User $user, Store $store): bool
    {
        return $user->hasAccessToStore($store->id);
    }

    /**
     * Determine if the user can manage the store.
     */
    public function manage(User $user, Store $store): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        $role = $user->roleInStore($store->id);
        return in_array($role, ['admin', 'gerente']);
    }
}
