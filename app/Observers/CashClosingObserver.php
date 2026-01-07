<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CashClosingStatus;
use App\Jobs\RecalculateSellerDailyBonusJob;
use App\Jobs\RecalculateSellerMonthlyCommissionJob;
use App\Models\CashClosing;
use Carbon\Carbon;

class CashClosingObserver
{
    /**
     * Handle the CashClosing "updated" event.
     */
    public function updated(CashClosing $closing): void
    {
        // Only recalculate on status changes
        if (!$closing->wasChanged('status')) {
            return;
        }

        $shift = $closing->cashShift;
        if (!$shift) {
            return;
        }

        $date = Carbon::parse($shift->date);
        $month = $date->format('Y-m');

        // Dispatch daily bonus recalculation
        RecalculateSellerDailyBonusJob::dispatch(
            $shift->store_id,
            $shift->seller_id,
            $date->format('Y-m-d')
        )->onQueue('finance');

        // Also recalculate commission if approved/rejected
        $newStatus = CashClosingStatus::tryFrom($closing->status);
        if (in_array($newStatus, [CashClosingStatus::APPROVED, CashClosingStatus::REJECTED])) {
            RecalculateSellerMonthlyCommissionJob::dispatch(
                $shift->store_id,
                $shift->seller_id,
                $month
            )->onQueue('finance');
        }
    }
}
