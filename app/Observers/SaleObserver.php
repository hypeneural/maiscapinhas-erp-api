<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RecalculateSellerDailyBonusJob;
use App\Jobs\RecalculateSellerMonthlyCommissionJob;
use App\Models\Sale;
use Carbon\Carbon;

class SaleObserver
{
    /**
     * Handle the Sale "created" event.
     */
    public function created(Sale $sale): void
    {
        $this->dispatchRecalculationJobs($sale);
    }

    /**
     * Handle the Sale "updated" event.
     */
    public function updated(Sale $sale): void
    {
        $this->dispatchRecalculationJobs($sale);
    }

    /**
     * Handle the Sale "deleted" event.
     */
    public function deleted(Sale $sale): void
    {
        $this->dispatchRecalculationJobs($sale);
    }

    private function dispatchRecalculationJobs(Sale $sale): void
    {
        $date = Carbon::parse($sale->sold_at);
        $month = $date->format('Y-m');

        // Dispatch daily bonus recalculation
        RecalculateSellerDailyBonusJob::dispatch(
            $sale->store_id,
            $sale->seller_id,
            $date->format('Y-m-d')
        )->onQueue('finance');

        // Dispatch monthly commission recalculation
        RecalculateSellerMonthlyCommissionJob::dispatch(
            $sale->store_id,
            $sale->seller_id,
            $month
        )->onQueue('finance');
    }
}
