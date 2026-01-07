<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;

class StoreAccessService
{
    /**
     * Check if user has access to a specific store.
     */
    public function canAccessStore(User $user, int $storeId): bool
    {
        return $user->hasAccessToStore($storeId);
    }

    /**
     * Get user's role in a specific store.
     */
    public function roleInStore(User $user, int $storeId): ?string
    {
        return $user->roleInStore($storeId);
    }

    /**
     * Get the StoreUser record for a user in a specific store.
     */
    public function getStoreUser(User $user, int $storeId): ?StoreUser
    {
        return $user->storeUserFor($storeId);
    }

    /**
     * Check if user can approve closings in a store.
     */
    public function canApproveInStore(User $user, int $storeId): bool
    {
        return $user->canApproveInStore($storeId);
    }

    /**
     * Check if user has at least one of the specified roles in the store.
     */
    public function hasRoleInStore(User $user, int $storeId, array $roles): bool
    {
        $userRole = $this->roleInStore($user, $storeId);
        return $userRole !== null && in_array($userRole, $roles);
    }

    /**
     * Check if user is admin in any store (global admin).
     */
    public function isGlobalAdmin(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    /**
     * Get all store IDs the user has access to.
     */
    public function getUserStoreIds(User $user): array
    {
        return $user->storeUsers()->pluck('store_id')->toArray();
    }

    /**
     * Get store ID from request, validating user access.
     */
    public function getStoreIdFromRequest(Request $request, User $user): ?int
    {
        $storeId = $request->input('store_id') ?? $request->route('store_id');

        if ($storeId === null) {
            return null;
        }

        $storeId = (int) $storeId;

        if (!$this->canAccessStore($user, $storeId)) {
            return null;
        }

        return $storeId;
    }
}
