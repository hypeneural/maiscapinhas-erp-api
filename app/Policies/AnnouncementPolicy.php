<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementTargetType;
use App\Enums\StoreUserRole;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementEligibilityService;

class AnnouncementPolicy
{
    public function __construct(
        private AnnouncementEligibilityService $eligibilityService
    ) {
    }

    /**
     * Any authenticated user can view announcements list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * User can view if they are eligible or are admin.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        return $this->eligibilityService->isEligible($user, $announcement);
    }

    /**
     * Admin/Gerente can create. Only admin can create global scope.
     */
    public function create(User $user, ?string $scope = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $isManager = $this->isAnyManager($user);

        if (!$isManager) {
            return false;
        }

        // Only global admin can create global announcements
        if ($scope === AnnouncementScope::GLOBAL ->value) {
            return $this->isGlobalAdmin($user);
        }

        return true;
    }

    /**
     * Can update if admin or creator with store scope access.
     */
    public function update(User $user, Announcement $announcement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Global announcements: only global admin
        if ($announcement->isGlobal()) {
            return $this->isGlobalAdmin($user);
        }

        // Creator can update their own announcements if they still have access
        if ($announcement->created_by_user_id === $user->id) {
            return $this->hasAccessToAnnouncementScope($user, $announcement);
        }

        // Managers with access to the announcement scope
        return $this->isAnyManager($user) && $this->hasAccessToAnnouncementScope($user, $announcement);
    }

    /**
     * Same rules as update.
     */
    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }

    /**
     * Admin or Gerente in store scope can publish.
     */
    public function publish(User $user, Announcement $announcement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Must be draft or scheduled
        if (!in_array($announcement->status, [AnnouncementStatus::DRAFT, AnnouncementStatus::SCHEDULED])) {
            return false;
        }

        if ($announcement->isGlobal()) {
            return $this->isGlobalAdmin($user);
        }

        return $this->isAnyManager($user) && $this->hasAccessToAnnouncementScope($user, $announcement);
    }

    /**
     * Admin or Gerente in store scope can archive.
     */
    public function archive(User $user, Announcement $announcement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Cannot archive already archived
        if ($announcement->status === AnnouncementStatus::ARCHIVED) {
            return false;
        }

        if ($announcement->isGlobal()) {
            return $this->isGlobalAdmin($user);
        }

        return $this->isAnyManager($user) && $this->hasAccessToAnnouncementScope($user, $announcement);
    }

    /**
     * Eligible users can mark as seen.
     */
    public function markSeen(User $user, Announcement $announcement): bool
    {
        return $this->eligibilityService->isEligible($user, $announcement);
    }

    /**
     * Eligible users can acknowledge.
     */
    public function ack(User $user, Announcement $announcement): bool
    {
        return $this->eligibilityService->isEligible($user, $announcement);
    }

    /**
     * Eligible users can dismiss (only for non-require_ack announcements).
     */
    public function dismiss(User $user, Announcement $announcement): bool
    {
        if ($announcement->require_ack) {
            return false;
        }

        return $this->eligibilityService->isEligible($user, $announcement);
    }

    /**
     * Admin list access.
     */
    public function adminIndex(User $user): bool
    {
        return $this->isAnyManager($user);
    }

    // ========================================
    // Private Helpers
    // ========================================

    private function isAnyManager(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->storeUsers()
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

    private function hasAccessToAnnouncementScope(User $user, Announcement $announcement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($announcement->scope) {
            AnnouncementScope::GLOBAL => $this->isGlobalAdmin($user),
            AnnouncementScope::STORE => $this->hasAccessToTargetStores($user, $announcement),
            AnnouncementScope::USER => $this->isGlobalAdmin($user), // Only admin can target users
            AnnouncementScope::ROLE => $this->hasAccessToTargetStores($user, $announcement) || $this->isGlobalAdmin($user),
        };
    }

    private function hasAccessToTargetStores(User $user, Announcement $announcement): bool
    {
        $targetStoreIds = $announcement->targets
            ->where('target_type', AnnouncementTargetType::STORE)
            ->pluck('target_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($targetStoreIds)) {
            // Role scope without store targets - need to be global admin
            return false;
        }

        // User must be manager in at least one of the target stores
        return $user->storeUsers()
            ->whereIn('store_id', $targetStoreIds)
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();
    }
}
