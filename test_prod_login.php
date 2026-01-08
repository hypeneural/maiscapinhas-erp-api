<?php

$url = 'https://api.maiscapinhas.com.br/api/v1/auth/login';
$data = [
    'email' => 'admin@maiscapinhas.com.br',
    'password' => 'password'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

echo "=== Testing Login on Production ===\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

$decoded = json_decode($response, true);
if (isset($decoded['data']['token'])) {
    echo "Token: " . substr($decoded['data']['token'], 0, 30) . "...\n";
    echo "User: " . $decoded['data']['user']['name'] . "\n";
    echo "\n✅ Login successful!\n";
} else {
    echo "Response: " . $response . "\n";
}

// Now check audit logs via Laravel
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n=== Audit Logs ===\n";
echo "Total: " . \App\Models\AuditLog::count() . "\n\n";

$logs = \App\Models\AuditLog::orderBy('id', 'desc')->take(5)->get();
foreach ($logs as $log) {
    echo "ID {$log->id}: {$log->event} | Actor: {$log->actor_id} | {$log->created_at}\n";
}
