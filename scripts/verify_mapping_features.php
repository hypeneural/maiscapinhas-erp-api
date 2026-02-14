<?php

use App\Models\PdvUserMapping;
use App\Models\PdvStoreMapping;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

// 1. Setup Data
$store = Store::first();
$user = User::first();
$pdvStoreId = 9999;
$pdvUserId = 8888;

echo "--- Starting Verification ---\n";
echo "Using Store: {$store->id} ({$store->name})\n";
echo "Using User: {$user->id} ({$user->name})\n";

// 2. Test Store Mapping
echo "\n[Test] Store Mapping...\n";
$mapping = PdvStoreMapping::updateOrCreate(
    ['pdv_store_id' => $pdvStoreId],
    ['store_id' => $store->id, 'active' => true]
);
echo "Created Store Mapping: PDV {$mapping->pdv_store_id} -> ERP {$mapping->store_id}\n";

$check = PdvStoreMapping::where('pdv_store_id', $pdvStoreId)->first();
if ($check && $check->store_id === $store->id) {
    echo "SUCCESS: Store Mapping verified.\n";
} else {
    echo "FAILED: Store Mapping verification.\n";
}

// 3. Test User Mapping
echo "\n[Test] User Mapping...\n";
$userMapping = PdvUserMapping::updateOrCreate(
    ['store_pdv_id' => $pdvStoreId, 'pdv_user_id' => $pdvUserId],
    ['user_id' => $user->id, 'active' => true, 'source' => 'test']
);
echo "Created User Mapping: PDV User {$pdvUserId} @ Store {$pdvStoreId} -> ERP User {$userMapping->user_id}\n";

$checkUser = PdvUserMapping::where('pdv_user_id', $pdvUserId)->first();
if ($checkUser && $checkUser->user_id === $user->id) {
    echo "SUCCESS: User Mapping verified.\n";
} else {
    echo "FAILED: User Mapping verification.\n";
}

// 4. Test Suggestions Logic (Simulation)
echo "\n[Test] Suggestions Logic...\n";
// Insert a fake sale item for an unmapped user
$unmappedPdvUser = 7777;
// Ensure no mapping
PdvUserMapping::where('pdv_user_id', $unmappedPdvUser)->delete();

// We can't easily insert into pdv_venda_itens without FK constraints failing usually, 
// but let's try to query existing unmapped items first.
$unmapped = DB::table('pdv_venda_itens as vi')
    ->select('vi.store_pdv_id', 'vi.vendedor_pdv_id')
    ->leftJoin('pdv_user_mappings as m', function ($join) {
        $join->on('m.store_pdv_id', '=', 'vi.store_pdv_id')
            ->on('m.pdv_user_id', '=', 'vi.vendedor_pdv_id');
    })
    ->whereNull('m.id')
    ->distinct()
    ->limit(5)
    ->get();

echo "Found " . $unmapped->count() . " unmapped sellers in real data.\n";
if ($unmapped->count() > 0) {
    echo "First Unmapped: Store " . $unmapped[0]->store_pdv_id . " User " . $unmapped[0]->vendedor_pdv_id . "\n";
    echo "SUCCESS: Queries for unmapped users are working.\n";
} else {
    echo "WARNING: No unmapped users found (Maybe all mapped? or no data).\n";
}

// Cleanup
echo "\n[Cleanup] Removing test data...\n";
$mapping->delete();
$userMapping->delete();
echo "Done.\n";
