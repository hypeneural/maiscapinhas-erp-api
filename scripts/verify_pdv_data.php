<?php

use App\Models\Store;
use App\Models\User;
use App\Domains\Reports\Services\RankingService;
use App\Domains\Reports\Services\StorePerformanceService;
use App\Domains\Reports\Services\SellerGamificationService;
use Carbon\Carbon;

// Basic Config
$storeId = 1;
$userId = 1; // Default fallback if fetch fails
$month = Carbon::now()->format('Y-m');
$today = Carbon::today();

echo "Running PDV Verification checks for Store: $storeId User: $userId Month: $month\n";
echo "=================================================================\n";

try {
    // 1. Ranking Service
    echo "[RankingService] Testing getRanking...\n";
    $rankingService = app(RankingService::class);
    $ranking = $rankingService->getRanking($storeId, $month);
    echo "Count: " . count($ranking['ranking']) . "\n";
    if (count($ranking['ranking']) > 0) {
        print_r($ranking['ranking'][0]);
    } else {
        echo "No ranking data found.\n";
    }
    echo "-----------------------------------------------------------------\n";

    // 2. StorePerformance Service
    echo "[StorePerformance] Testing getPerformance...\n";
    $perfService = app(StorePerformanceService::class);
    $perf = $perfService->getPerformance($storeId, $month);
    echo "Current Sales: " . ($perf['sales']['current_amount'] ?? 'N/A') . "\n";
    echo "YoY Growth: " . ($perf['comparison']['yoy_growth'] ?? 'N/A') . "%\n";
    echo "-----------------------------------------------------------------\n";

    // 2.1 StorePerformance - MultiStore
    echo "[StorePerformance] Testing getMultiStorePerformance...\n";
    $multiPerf = $perfService->getMultiStorePerformance([$storeId], $month);
    echo "Consolidated Sales: " . ($multiPerf['consolidated']['total_sales'] ?? 'N/A') . "\n";
    echo "-----------------------------------------------------------------\n";

    // 3. Gamification Service
    echo "[Gamification] Testing getBonusGamification...\n";
    $gameService = app(SellerGamificationService::class);
    $bonus = $gameService->getBonusGamification($storeId, $userId, $today);
    echo "Current Amount: " . ($bonus['current_amount'] ?? 'N/A') . "\n";
    echo "Projected Bonus: " . ($bonus['projected_bonus'] ?? 'N/A') . "\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
