<?php

declare(strict_types=1);

namespace App\Domains\Finance\Engines;

use App\Enums\BonusStatus;
use App\Enums\CashClosingStatus;
use App\Models\AuditLog;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\SellerDailyBonus;
use App\Services\RulesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BonusEngineService
{
    public function __construct(
        private RulesService $rulesService
    ) {
    }

    /**
     * Calculate and persist daily bonus for a seller.
     */
    public function calculateDailyBonus(int $storeId, int $userId, Carbon $date): SellerDailyBonus
    {
        return DB::transaction(function () use ($storeId, $userId, $date) {
            // 1. Calculate sales total
            $salesTotal = $this->getSalesTotalForDay($storeId, $userId, $date);

            // 2. Check divergences and eligibility
            $divergenceData = $this->checkDivergences($storeId, $userId, $date);
            $eligible = $divergenceData['eligible'];
            $divergenceTotal = $divergenceData['divergence_total'];

            // 3. Get applicable bonus rule
            $rule = $this->rulesService->getApplicableBonusRule($storeId, $date);

            // 4. Calculate bonus amount
            $bonusAmount = 0;
            $ruleVersion = 1;
            $status = BonusStatus::PROVISIONAL;

            if (!$eligible) {
                $status = BonusStatus::ZEROED;
            } elseif ($rule) {
                $bonusAmount = $this->rulesService->calculateBonusFromRule($rule, $salesTotal);
                $ruleVersion = $rule->version;
            }

            // 5. Upsert seller_daily_bonus
            $bonus = SellerDailyBonus::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'user_id' => $userId,
                    'date' => $date->format('Y-m-d'),
                ],
                [
                    'sales_total' => $salesTotal,
                    'divergence_total' => $divergenceTotal,
                    'eligible' => $eligible,
                    'bonus_amount' => $bonusAmount,
                    'status' => $status,
                    'rule_version' => $ruleVersion,
                ]
            );

            return $bonus;
        });
    }

    /**
     * Get total sales for a seller in a store on a specific day.
     */
    private function getSalesTotalForDay(int $storeId, int $userId, Carbon $date): float
    {
        return (float) Sale::where('store_id', $storeId)
            ->where('seller_id', $userId)
            ->whereDate('sold_at', $date)
            ->sum('amount');
    }

    /**
     * Check divergences for seller's shifts on a date.
     * 
     * Returns [eligible => bool, divergence_total => float]
     */
    private function checkDivergences(int $storeId, int $userId, Carbon $date): array
    {
        // Get all shifts for this seller/store/date
        $shifts = CashShift::where('store_id', $storeId)
            ->where('seller_id', $userId)
            ->where('date', $date->format('Y-m-d'))
            ->with('cashClosing.lines')
            ->get();

        $divergenceTotal = 0;
        $hasUnjustifiedDivergence = false;
        $hasInvalidStatus = false;

        foreach ($shifts as $shift) {
            $closing = $shift->cashClosing;

            if (!$closing) {
                // No closing yet - consider eligible but provisional
                continue;
            }

            $status = CashClosingStatus::tryFrom($closing->status);

            // Invalid status for bonus consideration
            if (!$status || !in_array($status, [CashClosingStatus::APPROVED])) {
                // If submitted or draft, we consider ineligible until approved
                if ($status === CashClosingStatus::REJECTED) {
                    $hasInvalidStatus = true;
                }
                continue;
            }

            foreach ($closing->lines as $line) {
                $diff = abs((float) $line->diff_value);
                $divergenceTotal += $diff;

                // Check for unjustified divergence
                if ($diff > 0 && empty($line->justification_text)) {
                    $hasUnjustifiedDivergence = true;
                }
            }
        }

        return [
            'eligible' => !$hasUnjustifiedDivergence && !$hasInvalidStatus,
            'divergence_total' => $divergenceTotal,
        ];
    }

    /**
     * Recalculate bonus for all sellers in a store on a date.
     */
    public function recalculateForStoreDate(int $storeId, Carbon $date): array
    {
        $results = [];

        // Get all sellers who had shifts or sales on this date
        $sellerIds = collect()
            ->merge(
                CashShift::where('store_id', $storeId)
                    ->where('date', $date->format('Y-m-d'))
                    ->pluck('seller_id')
            )
            ->merge(
                Sale::where('store_id', $storeId)
                    ->whereDate('sold_at', $date)
                    ->pluck('seller_id')
            )
            ->unique();

        foreach ($sellerIds as $sellerId) {
            $results[] = $this->calculateDailyBonus($storeId, $sellerId, $date);
        }

        return $results;
    }
}
