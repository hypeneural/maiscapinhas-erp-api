<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StoreUserRole;
use App\Models\BonusRule;
use App\Models\User;

class BonusRulePolicy
{
    /**
     * Determine if user can view any rules.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();
    }

    /**
     * Determine if user can view a specific rule.
     */
    public function view(User $user, BonusRule $rule): bool
    {
        // Global rule - any manager can view
        if ($rule->store_id === null) {
            return $this->isAnyManager($user);
        }

        // Store-specific rule - must be manager of that store
        return $this->isManagerOfStore($user, $rule->store_id);
    }

    /**
     * Determine if user can create rules.
     */
    public function create(User $user): bool
    {
        return $this->isAnyManager($user);
    }

    /**
     * Determine if user can update the rule.
     */
    public function update(User $user, BonusRule $rule): bool
    {
        // Global rule - only admin
        if ($rule->store_id === null) {
            return $this->isGlobalAdmin($user);
        }

        return $this->isManagerOfStore($user, $rule->store_id);
    }

    /**
     * Determine if user can delete the rule.
     */
    public function delete(User $user, BonusRule $rule): bool
    {
        return $this->update($user, $rule);
    }

    private function isAnyManager(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();
    }

    private function isManagerOfStore(User $user, int $storeId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->storeUsers()
            ->where('store_id', $storeId)
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();
    }

    private function isGlobalAdmin(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->storeUsers()
            ->where('role', StoreUserRole::ADMIN->value)
            ->exists();
    }
}
