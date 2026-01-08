<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$token = '2|yPNqTWnttSq6X9uKnWp6XhplfRLzzmI0yXhdr0Csf019bda1';
$baseUrl = 'http://127.0.0.1:8000/api/v1';

$results = [];

// Endpoints to test (GET only for simplicity)
$endpoints = [
    // Public
    ['GET', '/health', false],
    ['GET', '/version', false],

    // Auth
    ['GET', '/me', true],

    // Stores
    ['GET', '/stores', true],
    ['GET', '/stores/1', true],
    ['GET', '/stores/1/sellers', true],

    // Sales
    ['GET', '/sales', true],
    ['GET', '/sales/1', true],

    // Cash Management
    ['GET', '/cash/shifts', true],
    ['GET', '/cash/shifts/pending', true],
    ['GET', '/cash/shifts/divergent', true],
    ['GET', '/cash/shifts/1', true],
    ['GET', '/cash/closings/1', true],

    // Rules
    ['GET', '/rules/bonus', true],
    ['GET', '/rules/commission', true],

    // Goals
    ['GET', '/goals/monthly', true],

    // Finance
    ['GET', '/finance/bonus', true],
    ['GET', '/finance/bonus/calculate?amount=1000', true],
    ['GET', '/finance/bonus/seller/1', true],
    ['GET', '/finance/commission', true],
    ['GET', '/finance/commission/seller/1', true],
    ['GET', '/finance/commission/projection/1', true],

    // Analytics
    ['GET', '/analytics/people/shift', true],

    // Dashboard
    ['GET', '/dashboard/seller', true],
    ['GET', '/dashboard/store', true],
    ['GET', '/dashboard/admin', true],

    // Reports
    ['GET', '/reports/store-performance', true],
    ['GET', '/reports/consolidated', true],
    ['GET', '/reports/cash-integrity', true],
    ['GET', '/reports/ranking', true],

    // Users
    ['GET', '/users/birthdays', true],

    // Admin
    ['GET', '/admin/users', true],
    ['GET', '/admin/users/1', true],
    ['GET', '/admin/stores', true],
    ['GET', '/admin/stores/1', true],
    ['GET', '/admin/stores/1/users', true],
    ['GET', '/admin/audit-logs', true],
    ['GET', '/admin/audit-logs/stats', true],
];

echo "# 📋 API Endpoint Test Results\n\n";
echo "| Status | Method | Endpoint | Response |\n";
echo "|--------|--------|----------|----------|\n";

$passed = 0;
$failed = 0;

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

    $status = ($httpCode >= 200 && $httpCode < 300) ? '✅' : '❌';
    if ($httpCode >= 200 && $httpCode < 300) {
        $passed++;
        $msg = 'OK';
    } else {
        $failed++;
        $decoded = json_decode($response, true);
        $msg = $decoded['message'] ?? "HTTP $httpCode";
        $msg = substr($msg, 0, 40);
    }

    echo "| $status | $method | `$path` | $msg |\n";
}

echo "\n---\n";
echo "**Total:** " . count($endpoints) . " endpoints\n";
echo "**Passed:** $passed ✅\n";
echo "**Failed:** $failed ❌\n";
