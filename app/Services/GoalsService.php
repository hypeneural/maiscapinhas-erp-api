<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StoreGoalSplit;
use App\Models\StoreMonthlyGoal;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoalsService
{
    private const SPLIT_TOLERANCE = 0.01;

    /**
     * Create a monthly goal for a store.
     */
    public function createGoal(array $data, User $actor): StoreMonthlyGoal
    {
        return DB::transaction(function () use ($data, $actor) {
            $goal = StoreMonthlyGoal::create([
                'store_id' => $data['store_id'],
                'month' => $data['month'],
                'goal_amount' => $data['goal_amount'],
                'active' => $data['active'] ?? true,
            ]);

            AuditLog::log('goal.created', $goal, null, [
                'store_id' => $goal->store_id,
                'month' => $goal->month,
                'goal_amount' => $goal->goal_amount,
            ], $actor->id);

            return $goal;
        });
    }

    /**
     * Update a monthly goal.
     */
    public function updateGoal(StoreMonthlyGoal $goal, array $data, User $actor): StoreMonthlyGoal
    {
        return DB::transaction(function () use ($goal, $data, $actor) {
            $before = $goal->toArray();

            $goal->update([
                'goal_amount' => $data['goal_amount'] ?? $goal->goal_amount,
                'active' => $data['active'] ?? $goal->active,
            ]);

            AuditLog::log('goal.updated', $goal, $before, $goal->toArray(), $actor->id);

            return $goal->fresh();
        });
    }

    /**
     * Set splits for a goal.
     * 
     * @throws ValidationException if splits don't sum to 100
     */
    public function setSplits(StoreMonthlyGoal $goal, array $splits, User $actor): StoreMonthlyGoal
    {
        $this->validateSplitUsers($goal, $splits);
        $this->validateSplitsSum($splits);

        return DB::transaction(function () use ($goal, $splits, $actor) {
            $before = $goal->splits()->get()->toArray();

            // Delete existing splits
            $goal->splits()->delete();

            // Create new splits
            foreach ($splits as $split) {
                StoreGoalSplit::create([
                    'store_monthly_goal_id' => $goal->id,
                    'user_id' => $split['user_id'],
                    'percent' => $split['percent'],
                ]);
            }

            $after = $goal->fresh()->splits()->get()->toArray();

            AuditLog::log('goal.splits_updated', $goal, [
                'splits' => $before,
            ], [
                'splits' => $after,
            ], $actor->id);

            return $goal->fresh(['splits.user']);
        });
    }

    /**
     * Validate that splits sum to 100%.
     */
    private function validateSplitsSum(array $splits): void
    {
        $sum = array_sum(array_column($splits, 'percent'));

        if (abs($sum - 100.00) > self::SPLIT_TOLERANCE) {
            throw ValidationException::withMessages([
                'splits' => ["The sum of split percentages must equal 100%. Current sum: {$sum}%"],
            ]);
        }
    }

    /**
     * Validate split users are active and linked to the goal store.
     *
     * Allowed roles for split users: vendedor, gerente, admin.
     */
    private function validateSplitUsers(StoreMonthlyGoal $goal, array $splits): void
    {
        $userIds = array_values(array_unique(array_map(
            static fn(array $split): int => (int) ($split['user_id'] ?? 0),
            $splits
        )));

        if ($userIds === []) {
            throw ValidationException::withMessages([
                'splits' => ['At least one split user is required.'],
            ]);
        }

        $allowedUserIds = StoreUser::query()
            ->where('store_id', $goal->store_id)
            ->whereIn('user_id', $userIds)
            ->whereIn('role', [
                StoreUser::ROLE_VENDEDOR,
                StoreUser::ROLE_GERENTE,
                StoreUser::ROLE_ADMIN,
            ])
            ->pluck('user_id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();

        $allowedLookup = array_fill_keys($allowedUserIds, true);
        $invalidStoreUsers = array_values(array_filter(
            $userIds,
            static fn(int $userId): bool => !isset($allowedLookup[$userId])
        ));

        if ($invalidStoreUsers !== []) {
            throw ValidationException::withMessages([
                'splits' => [
                    'Some users are not linked to this store with an allowed role (vendedor/gerente/admin): '
                    . implode(', ', $invalidStoreUsers),
                ],
            ]);
        }

        $inactiveUsers = User::query()
            ->whereIn('id', $userIds)
            ->where('active', false)
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();

        if ($inactiveUsers !== []) {
            throw ValidationException::withMessages([
                'splits' => [
                    'Some users are inactive and cannot receive goal splits: ' . implode(', ', $inactiveUsers),
                ],
            ]);
        }
    }

    /**
     * Get goal for a store and month.
     */
    public function getGoal(int $storeId, string $month): ?StoreMonthlyGoal
    {
        return StoreMonthlyGoal::forStore($storeId)
            ->forMonth($month)
            ->with('splits.user')
            ->first();
    }

    /**
     * Get user's individual goal amount.
     */
    public function getUserGoalAmount(int $storeId, int $userId, string $month): float
    {
        $goal = $this->getGoal($storeId, $month);

        if (!$goal) {
            return 0;
        }

        return $goal->getIndividualGoal($userId);
    }

    /**
     * Get user's split percent for a goal.
     */
    public function getUserSplitPercent(StoreMonthlyGoal $goal, int $userId): float
    {
        $split = $goal->splits()->where('user_id', $userId)->first();
        return $split ? (float) $split->percent : 0;
    }
}
