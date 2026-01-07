<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StoreGoalSplit;
use App\Models\StoreMonthlyGoal;
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

            AuditLog::log($actor, 'goal.created', $goal, null, [
                'store_id' => $goal->store_id,
                'month' => $goal->month,
                'goal_amount' => $goal->goal_amount,
            ]);

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

            AuditLog::log($actor, 'goal.updated', $goal, $before, $goal->toArray());

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

            AuditLog::log($actor, 'goal.splits_updated', $goal, [
                'splits' => $before,
            ], [
                'splits' => $after,
            ]);

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
