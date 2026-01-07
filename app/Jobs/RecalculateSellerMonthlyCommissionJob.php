<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domains\Finance\Engines\CommissionEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecalculateSellerMonthlyCommissionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $storeId,
        public int $userId,
        public string $month
    ) {
    }

    public function handle(CommissionEngineService $commissionEngine): void
    {
        try {
            $commission = $commissionEngine->calculateMonthlyCommission(
                $this->storeId,
                $this->userId,
                $this->month
            );

            Log::info('Monthly commission recalculated', [
                'store_id' => $this->storeId,
                'user_id' => $this->userId,
                'month' => $this->month,
                'sales_total' => $commission->sales_total,
                'goal_amount' => $commission->goal_amount,
                'attainment_percent' => $commission->attainment_percent,
                'rate_percent' => $commission->rate_percent,
                'commission_amount' => $commission->commission_amount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to recalculate monthly commission', [
                'store_id' => $this->storeId,
                'user_id' => $this->userId,
                'month' => $this->month,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'commission',
            'store:' . $this->storeId,
            'user:' . $this->userId,
            'month:' . $this->month,
        ];
    }
}
