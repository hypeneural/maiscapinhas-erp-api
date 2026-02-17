<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Support\Pdv\PdvStoreResolver;
use Illuminate\Support\Facades\DB;

echo "Testing PdvStoreResolver with Auto-Link by GUID...\n";

$resolver = app(PdvStoreResolver::class);

// Target Store: Mata Atlantica (ID 9 in stores table, PDV ID 9 in payload)
$storePdvId = 9;
$knownGuid = '040b76bf-7aa9-4a91-9af7-4fb9938a4db1'; // From check_mappings_schema.php output

echo "Target PDV ID: $storePdvId\n";
echo "Target GUID: $knownGuid\n";

// 1. Check current state of mapping
$mapping = DB::table('pdv_store_mappings')
    ->where('pdv_store_id', $storePdvId)
    ->first();

echo "Current Mapping State: ";
if ($mapping) {
    echo "Found (ID: {$mapping->id}, GUID_LOJA: " . ($mapping->guid_loja ?? 'NULL') . ")\n";
    // Force reset for test if it already has guid (optional, but good for verification)
    // DB::table('pdv_store_mappings')->where('id', $mapping->id)->update(['guid_loja' => null]);
} else {
    echo "Not Found\n";
}

// 2. Resolve
echo "Resolving...\n";
$result = $resolver->resolve(
    $storePdvId,
    null, // alias
    null, // name
    null, // cnpj
    $knownGuid // GUID
);

print_r($result);

// 3. Verify Mapping Update
$newMapping = DB::table('pdv_store_mappings')
    ->where('pdv_store_id', $storePdvId)
    ->first();

echo "Post-Resolution Mapping State: ";
if ($newMapping) {
    echo "Found (ID: {$newMapping->id}, GUID_LOJA: " . ($newMapping->guid_loja ?? 'NULL') . ", StoreID: {$newMapping->store_id})\n";
} else {
    echo "Not Found (FAILED)\n";
}

if (($result['status'] ?? '') === 'resolved' && ($newMapping->guid_loja ?? '') === $knownGuid) {
    echo "SUCCESS: Store resolved and mapping updated.\n";
    exit(0);
} else {
    echo "FAILURE: Store not resolved or mapping not updated.\n";
    exit(1);
}
