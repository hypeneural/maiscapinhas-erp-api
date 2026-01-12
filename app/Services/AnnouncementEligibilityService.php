<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnnouncementEligibilityService
{
    /**
     * Get active announcements for a user, separated into critical and banners.
     *
     * @return array{critical: Collection, banners: Collection}
     */
    public function getActiveForUser(User $user, ?int $storeId = null): array
    {
        $now = now();

        // Get all potentially eligible announcements
        $announcements = Announcement::activeNow($now)
            ->visible()
            ->ordered($now)
            ->with(['targets', 'receipts' => fn($q) => $q->where('user_id', $user->id)])
            ->get();

        // Filter by eligibility
        $eligible = $announcements->filter(
            fn(Announcement $a) => $this->isEligible($user, $a, $storeId)
        );

        // Filter by display rules (should show now based on periodicity/ack)
        $showNow = $eligible->filter(function (Announcement $a) use ($user, $storeId, $now) {
            $receipt = $a->receiptForUser($user->id);
            return $this->shouldShowNow($receipt, $a, $now);
        });

        // Create/update receipts for items being shown
        foreach ($showNow as $announcement) {
            $this->touchReceiptOnShown($user, $announcement, $storeId);
        }

        // Reload to get updated receipts
        $showNow = $showNow->fresh(['receipts' => fn($q) => $q->where('user_id', $user->id)]);

        return $this->applyDisplayRules($showNow);
    }

    /**
     * Check if a user is eligible to see an announcement based on scope and targets.
     */
    public function isEligible(User $user, Announcement $announcement, ?int $storeId = null): bool
    {
        // Check schedule
        if (!$announcement->isWithinSchedule()) {
            return false;
        }

        // Check status
        if (!$announcement->status->isVisible()) {
            return false;
        }

        // Check scope-based eligibility
        return match ($announcement->scope) {
            AnnouncementScope::GLOBAL => true,
            AnnouncementScope::STORE => $this->isEligibleByStore($user, $announcement, $storeId),
            AnnouncementScope::USER => $this->isEligibleByUser($user, $announcement),
            AnnouncementScope::ROLE => $this->isEligibleByRole($user, $announcement, $storeId),
        };
    }

    /**
     * Check if announcement should be shown now based on periodicity and acknowledgement.
     */
    public function shouldShowNow(?AnnouncementReceipt $receipt, Announcement $announcement, Carbon $now): bool
    {
        // If require_ack and already acknowledged, don't show
        if ($announcement->require_ack && $receipt?->isAcknowledged()) {
            return false;
        }

        // If dismissed (for non-ack items), don't show
        if (!$announcement->require_ack && $receipt?->isDismissed()) {
            return false;
        }

        // Check snooze
        if ($receipt?->isSnoozed($now)) {
            return false;
        }

        // Check periodicity for require_ack items
        if ($announcement->require_ack && $receipt?->last_shown_at !== null) {
            $repeatMinutes = $announcement->repeat_every_minutes;

            // If no repeat setting, only show once until acknowledged
            if ($repeatMinutes === null) {
                return false;
            }

            // Check if enough time has passed
            $minutesSinceShown = $receipt->last_shown_at->diffInMinutes($now);
            if ($minutesSinceShown < $repeatMinutes) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create or update receipt when announcement is shown.
     */
    public function touchReceiptOnShown(User $user, Announcement $announcement, ?int $storeId = null): AnnouncementReceipt
    {
        $receipt = AnnouncementReceipt::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ],
            [
                'store_id' => $storeId,
                'delivered_at' => now(),
            ]
        );

        $receipt->touchShown();

        return $receipt;
    }

    /**
     * Separate announcements into critical and banners.
     *
     * @return array{critical: Collection, banners: Collection}
     */
    public function applyDisplayRules(Collection $announcements): array
    {
        $critical = $announcements->filter(fn(Announcement $a) => $a->isCritical());

        // Banners exclude critical to avoid duplicates
        $banners = $announcements->filter(fn(Announcement $a) => !$a->isCritical());

        return [
            'critical' => $critical->values(),
            'banners' => $banners->values(),
        ];
    }

    /**
     * Check eligibility by store scope.
     */
    private function isEligibleByStore(User $user, Announcement $announcement, ?int $storeId = null): bool
    {
        $targetStoreIds = $announcement->targets
            ->where('target_type', AnnouncementTargetType::STORE)
            ->pluck('target_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($targetStoreIds)) {
            return false;
        }

        // If specific store requested, check if it's in targets
        if ($storeId !== null) {
            return in_array($storeId, $targetStoreIds) && $user->hasAccessToStore($storeId);
        }

        // Check if user has access to any of the target stores
        $userStoreIds = $user->storeUsers->pluck('store_id')->all();

        return !empty(array_intersect($targetStoreIds, $userStoreIds));
    }

    /**
     * Check eligibility by user scope.
     */
    private function isEligibleByUser(User $user, Announcement $announcement): bool
    {
        $targetUserIds = $announcement->targets
            ->where('target_type', AnnouncementTargetType::USER)
            ->pluck('target_id')
            ->map(fn($id) => (int) $id)
            ->all();

        return in_array($user->id, $targetUserIds);
    }

    /**
     * Check eligibility by role scope.
     */
    private function isEligibleByRole(User $user, Announcement $announcement, ?int $storeId = null): bool
    {
        $targetRoles = $announcement->targets
            ->where('target_type', AnnouncementTargetType::ROLE)
            ->pluck('target_id')
            ->all();

        if (empty($targetRoles)) {
            return false;
        }

        // Get user's roles across stores (or specific store)
        $query = $user->storeUsers();

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        $userRoles = $query->pluck('role')->unique()->all();

        return !empty(array_intersect($targetRoles, $userRoles));
    }
}
