<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashClosing;
use App\Models\User;

class CashClosingPolicy
{
    /**
     * Determine if the user can view the closing.
     */
    public function view(User $user, CashClosing $closing): bool
    {
        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can submit the closing.
     */
    public function submit(User $user, CashClosing $closing): bool
    {
        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can approve the closing.
     */
    public function approve(User $user, CashClosing $closing): bool
    {
        $storeId = $closing->cashShift->store_id;
        return $user->hasAccessToStore($storeId) && $user->canApproveInStore($storeId);
    }

    /**
     * Determine if the user can reject the closing.
     */
    public function reject(User $user, CashClosing $closing): bool
    {
        $storeId = $closing->cashShift->store_id;
        return $user->hasAccessToStore($storeId) && $user->canApproveInStore($storeId);
    }
}
