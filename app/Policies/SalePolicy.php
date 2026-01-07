<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    /**
     * Determine if the user can view sales.
     */
    public function viewAny(User $user): bool
    {
        return $user->storeUsers()->exists();
    }

    /**
     * Determine if the user can view the sale.
     */
    public function view(User $user, Sale $sale): bool
    {
        return $user->hasAccessToStore($sale->store_id);
    }

    /**
     * Determine if the user can create a sale in the store.
     */
    public function create(User $user, int $storeId): bool
    {
        return $user->hasAccessToStore($storeId);
    }
}
