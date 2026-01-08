<?php
/**
 * Teste completo de todos os endpoints com saída detalhada
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Gerar token fresh para o teste
$user = \App\Models\User::where('email', 'admin@maiscapinhas.com.br')->first();
$token = $user->createToken('test-token')->plainTextToken;

$baseUrl = 'http://127.0.0.1:8000/api/v1';

$endpoints = [
    ['GET', '/health', false],
    ['GET', '/version', false],
    ['GET', '/me', true],
    ['GET', '/stores', true],
    ['GET', '/stores/1', true],
    ['GET', '/stores/1/sellers', true],
    ['GET', '/sales', true],
    ['GET', '/sales/1', true],
    ['GET', '/cash/shifts', true],
    ['GET', '/cash/shifts/pending', true],
    ['GET', '/cash/shifts/divergent', true],
    ['GET', '/cash/shifts/1', true],
    ['GET', '/cash/closings/1', true],
    ['GET', '/rules/bonus', true],
    ['GET', '/rules/commission', true],
    ['GET', '/goals/monthly', true],
    ['GET', '/finance/bonus', true],
    ['GET', '/finance/bonus/calculate?amount=1000', true],
    ['GET', '/finance/bonus/seller/1', true],
    ['GET', '/finance/commission', true],
    ['GET', '/finance/commission/seller/1', true],
    ['GET', '/finance/commission/projection/1', true],
    ['GET', '/analytics/people/shift', true],
    ['GET', '/dashboard/seller', true],
    ['GET', '/dashboard/store', true],
    ['GET', '/dashboard/admin', true],
    ['GET', '/reports/store-performance', true],
    ['GET', '/reports/consolidated', true],
    ['GET', '/reports/cash-integrity', true],
    ['GET', '/reports/ranking', true],
    ['GET', '/users/birthdays', true],
    ['GET', '/admin/users', true],
    ['GET', '/admin/users/1', true],
    ['GET', '/admin/stores', true],
    ['GET', '/admin/stores/1', true],
    ['GET', '/admin/stores/1/users', true],
    ['GET', '/admin/audit-logs', true],
    ['GET', '/admin/audit-logs/stats', true],
];

echo "API Endpoint Test Results\n";
echo "=========================\n\n";

$passed = 0;
$failed = 0;
$failedEndpoints = [];

foreach ($endpoints as $ep) {
    [$method, $path, $auth] = $ep;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $headers = ['Accept: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $passed++;
        echo "[OK]   $method $path - $httpCode\n";
    } else {
        $failed++;
        $decoded = json_decode($response, true);
        $msg = $decoded['message'] ?? "HTTP $httpCode";
        echo "[FAIL] $method $path - $httpCode - $msg\n";
        $failedEndpoints[] = [
            'path' => $path,
            'code' => $httpCode,
            'message' => $msg
        ];
    }
}

echo "\n";
echo "=========================\n";
echo "Total: " . count($endpoints) . " endpoints\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed > 0) {
    echo "\nFailed Endpoints Details:\n";
    echo "-------------------------\n";
    foreach ($failedEndpoints as $fe) {
        echo "  - {$fe['path']}: {$fe['code']} - {$fe['message']}\n";
    }
}
