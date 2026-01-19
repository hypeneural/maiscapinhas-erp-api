<?php
// Quick check of wheel data

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WheelPlayer;
use App\Models\WheelSession;
use App\Models\WheelSessionPlayer;
use App\Models\WheelSpin;
use App\Models\WheelCampaign;
use App\Models\WheelPrize;
use App\Models\WheelSegment;
use App\Models\WheelScreen;
use App\Models\WheelInventory;
use App\Models\WheelEvent;

echo "=== WHEEL DATA STATUS ===\n\n";

echo "Campaigns: " . WheelCampaign::count() . "\n";
echo "Screens: " . WheelScreen::count() . "\n";
echo "Prizes: " . WheelPrize::count() . "\n";
echo "Segments: " . WheelSegment::count() . "\n";
echo "Players: " . WheelPlayer::count() . "\n";
echo "Sessions: " . WheelSession::count() . "\n";
echo "Session Players: " . WheelSessionPlayer::count() . "\n";
echo "Spins: " . WheelSpin::count() . "\n";

echo "\n=== ANALYTICS EVENTS ===\n";
$events = WheelEvent::selectRaw('type, COUNT(*) as total')
    ->groupBy('type')
    ->get();
foreach ($events as $event) {
    echo sprintf("- %s: %d\n", $event->type, $event->total);
}
$inventory = WheelInventory::with('prize')->get();
foreach ($inventory as $inv) {
    echo sprintf(
        "- %s: %d/%d (daily: %d/%d)\n",
        $inv->prize?->name ?? 'N/A',
        $inv->remaining ?? 0,
        $inv->total_limit ?? 0,
        $inv->daily_remaining ?? 0,
        $inv->daily_limit ?? 0
    );
}

echo "\n=== SPINS BY PRIZE ===\n";
$spins = WheelSpin::selectRaw('prize_id, COUNT(*) as total, SUM(CASE WHEN redeemed = 1 THEN 1 ELSE 0 END) as redeemed')
    ->groupBy('prize_id')
    ->with('prize')
    ->get();

foreach ($spins as $spin) {
    echo sprintf(
        "- %s: %d spins (%d redeemed)\n",
        $spin->prize?->name ?? 'No prize',
        $spin->total,
        $spin->redeemed
    );
}

echo "\nDone!\n";
