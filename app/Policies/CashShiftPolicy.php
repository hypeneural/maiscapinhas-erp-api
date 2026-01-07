<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashShift;
use App\Models\User;

class CashShiftPolicy
{
    /**
     * Determine if the user can view any shifts.
     */
    public function viewAny(User $user): bool
    {
        return $user->storeUsers()->exists();
    }

    /**
     * Determine if the user can view the shift.
     */
    public function view(User $user, CashShift $shift): bool
    {
        return $user->hasAccessToStore($shift->store_id);
    }

    /**
     * Determine if the user can create a shift.
     */
    public function create(User $user, int $storeId): bool
    {
        return $user->hasAccessToStore($storeId);
    }
}
