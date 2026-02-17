<?php

use Illuminate\Support\Facades\Http;
use App\Models\Store;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test Configuration
$storeId = 8; // Mata Atlântica
$date = '2026-02-14'; // Valid date from previous analysis
$shiftCode = 'M'; // Matutino
$sellerId = null;

echo "--- Testing Cash Integration Endpoint ---\n";
echo "Store: $storeId\n";
echo "Date: $date\n";
echo "Shift: $shiftCode\n\n";

// internal request simulation
$request = Illuminate\Http\Request::create('/api/v1/cash/pdv-closure-data', 'GET', [
    'store_id' => $storeId,
    'date' => $date,
    'shift_code' => $shiftCode,
]);

$controller = new \App\Http\Controllers\Api\V1\CashIntegrationController();

try {
    $response = $controller->getClosureData($request);
    $data = $response->getData(true);

    if (isset($data['data'])) {
        echo "SUCCESS! Data retrieved:\n";
        echo "System Total: R$ " . number_format($data['data']['system_total'], 2, ',', '.') . "\n";
        echo "Turnos Found: " . $data['data']['turnos_found'] . "\n";
        echo "Payments:\n";
        foreach ($data['data']['payments'] as $payment) {
            echo " - " . $payment['label'] . ": R$ " . number_format($payment['value'], 2, ',', '.') . "\n";
        }
        echo "\nDetails:\n";
        print_r($data['data']['details']);
    } else {
        echo "FAILED. Response:\n";
        print_r($data);
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
