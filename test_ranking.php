<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $service = new \App\Domains\Reports\Services\RankingService();
    $result = $service->getRanking(null, '2026-01');

    echo "=== RANKING OK ===\n";
    echo "Period: " . $result['period'] . "\n";
    echo "Total sellers: " . ($result['stats']['total_sellers'] ?? 0) . "\n";
    echo "Podium count: " . count($result['podium'] ?? []) . "\n";

} catch (\Exception $e) {
    echo "=== ERROR ===\n";
    echo $e->getMessage() . "\n";
}
