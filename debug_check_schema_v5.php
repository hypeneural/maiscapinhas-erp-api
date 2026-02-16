<?php

use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storesColumns = Schema::getColumnListing('stores');
$usersColumns = Schema::getColumnListing('users');

echo "Stores Columns: " . implode(', ', $storesColumns) . "\n";
echo "Users Columns: " . implode(', ', $usersColumns) . "\n";

$hasStoreGuid = in_array('guid', $storesColumns);
$hasStoreCnpj = in_array('cnpj', $storesColumns);
$hasUserGuid = in_array('guid', $usersColumns);
$hasUserErpId = in_array('erp_id', $usersColumns);

if ($hasStoreGuid && $hasStoreCnpj && $hasUserGuid && $hasUserErpId) {
    echo "SUCCESS: All V5 columns are present.\n";
    exit(0);
} else {
    echo "FAILURE: Missing columns.\n";
    echo "Store GUID: " . ($hasStoreGuid ? 'Yes' : 'No') . "\n";
    echo "Store CNPJ: " . ($hasStoreCnpj ? 'Yes' : 'No') . "\n";
    echo "User GUID: " . ($hasUserGuid ? 'Yes' : 'No') . "\n";
    echo "User ERP ID: " . ($hasUserErpId ? 'Yes' : 'No') . "\n";
    exit(1);
}
