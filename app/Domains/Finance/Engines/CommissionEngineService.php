<?php

declare(strict_types=1);

namespace App\Domains\Finance\Engines;

use App\Enums\CommissionStatus;
use App\Models\Sale;
use App\Models\SellerMonthlyCommission;
use App\Models\StoreMonthlyGoal;
use App\Services\GoalsService;
use App\Services\RulesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CommissionEngineService
{
    public function __construct(
        private RulesService $rulesService,
        private GoalsService $goalsService
    ) {
    }

    /**
     * Calculate and persist monthly commission for a seller.
     */
    public function calculateMonthlyCommission(int $storeId, int $userId, string $month): SellerMonthlyCommission
    {
        return DB::transaction(function () use ($storeId, $userId, $month) {
            // 1. Calculate sales total for the month
            $salesTotal = $this->getMonthlySalesTotal($storeId, $userId, $month);

            // 2. Get goal and individual amount
            $goal = $this->goalsService->getGoal($storeId, $month);
            $individualGoal = $goal ? $goal->getIndividualGoal($userId) : 0;

            // 3. Calculate attainment percent
            $attainmentPercent = $this->calculateAttainment($salesTotal, $individualGoal);

            // 4. Get applicable commission rule
            $rule = $this->rulesService->getApplicableCommissionRule($storeId, $month);

            // 5. Calculate rate and commission amount
            $ratePercent = 0;
            $ruleVersion = 1;

            if ($rule) {
                $ratePercent = $this->rulesService->calculateCommissionRateFromRule($rule, $attainmentPercent);
                $ruleVersion = $rule->version;
            }

            $commissionAmount = round($salesTotal * ($ratePercent / 100), 2);

            // 6. Upsert seller_monthly_commissions
            $commission = SellerMonthlyCommission::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'user_id' => $userId,
                    'month' => $month,
                ],
                [
                    'sales_total' => $salesTotal,
                    'goal_amount' => $individualGoal,
                    'attainment_percent' => $attainmentPercent,
                    'rate_percent' => $ratePercent,
                    'commission_amount' => $commissionAmount,
                    'status' => CommissionStatus::PROVISIONAL,
                    'rule_version' => $ruleVersion,
                ]
            );

            return $commission;
        });
    }

    /**
     * Get total sales for a seller in a store for a month.
     */
    private function getMonthlySalesTotal(int $storeId, int $userId, string $month): float
    {
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();

        return (float) Sale::where('store_id', $storeId)
            ->where('seller_id', $userId)
            ->whereBetween('sold_at', [$startDate, $endDate])
            ->sum('amount');
    }

    /**
     * Calculate attainment percentage.
     */
    private function calculateAttainment(float $salesTotal, float $goalAmount): float
    {
        if ($goalAmount <= 0) {
            return 0;
        }

        return round(($salesTotal / $goalAmount) * 100, 2);
    }

    /**
     * Recalculate commission for all sellers in a store for a month.
     */
    public function recalculateForStoreMonth(int $storeId, string $month): array
    {
        $results = [];

        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();

        // Get all sellers who had sales in this store/month
        $sellerIds = Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$startDate, $endDate])
            ->distinct()
            ->pluck('seller_id');

        // Also include sellers with splits
        $goal = StoreMonthlyGoal::forStore($storeId)->forMonth($month)->first();
        if ($goal) {
            $splitSellerIds = $goal->splits()->pluck('user_id');
            $sellerIds = $sellerIds->merge($splitSellerIds)->unique();
        }

        foreach ($sellerIds as $sellerId) {
            $results[] = $this->calculateMonthlyCommission($storeId, $sellerId, $month);
        }

        return $results;
    }

    /**
     * Confirm commissions for a store/month (finalize for payment).
     */
    public function confirmCommissions(int $storeId, string $month): int
    {
        return SellerMonthlyCommission::forStore($storeId)
            ->forMonth($month)
            ->where('status', CommissionStatus::PROVISIONAL)
            ->update(['status' => CommissionStatus::CONFIRMED]);
    }
}
