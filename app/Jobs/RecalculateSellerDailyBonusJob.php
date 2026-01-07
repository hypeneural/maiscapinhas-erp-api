<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domains\Finance\Engines\BonusEngineService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecalculateSellerDailyBonusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $storeId,
        public int $userId,
        public string $date
    ) {
    }

    public function handle(BonusEngineService $bonusEngine): void
    {
        try {
            $bonus = $bonusEngine->calculateDailyBonus(
                $this->storeId,
                $this->userId,
                Carbon::parse($this->date)
            );

            Log::info('Daily bonus recalculated', [
                'store_id' => $this->storeId,
                'user_id' => $this->userId,
                'date' => $this->date,
                'bonus_amount' => $bonus->bonus_amount,
                'eligible' => $bonus->eligible,
                'status' => $bonus->status->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to recalculate daily bonus', [
                'store_id' => $this->storeId,
                'user_id' => $this->userId,
                'date' => $this->date,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'bonus',
            'store:' . $this->storeId,
            'user:' . $this->userId,
            'date:' . $this->date,
        ];
    }
}
