<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storeCount = Store::whereNotNull('guid')->count();
$userCount = User::whereNotNull('guid')->count();

echo "Stores with GUID: $storeCount\n";
echo "Users with GUID: $userCount\n";

if ($storeCount > 0 && $userCount > 0) {
    echo "SUCCESS: Data populated.\n";
    $store = Store::whereNotNull('guid')->first();
    echo "Sample Store: {$store->name} ({$store->guid})\n";

    $user = User::whereNotNull('guid')->first();
    echo "Sample User: {$user->name} ({$user->guid})\n";
} else {
    echo "FAILURE: No data populated.\n";
}
