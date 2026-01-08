<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

// Simulate real HTTP request
$request = Illuminate\Http\Request::create(
    '/api/v1/auth/login',
    'POST',
    ['email' => 'admin@maiscapinhas.com.br', 'password' => 'password']
);

$request->headers->set('Accept', 'application/json');
$request->headers->set('Content-Type', 'application/json');

echo "=== Before Login Request ===\n";
echo "Audit logs count: " . \App\Models\AuditLog::count() . "\n";

try {
    $response = $kernel->handle($request);

    echo "\n=== Response ===\n";
    echo "Status: " . $response->getStatusCode() . "\n";

    $content = json_decode($response->getContent(), true);
    if (isset($content['data']['token'])) {
        echo "Token: " . substr($content['data']['token'], 0, 20) . "...\n";
    } else {
        echo "Response: " . $response->getContent() . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== After Login Request ===\n";
echo "Audit logs count: " . \App\Models\AuditLog::count() . "\n";

// Show last log if any new
$lastLog = \App\Models\AuditLog::latest('id')->first();
if ($lastLog) {
    echo "\nLast log:\n";
    echo "  Event: " . $lastLog->event . "\n";
    echo "  Actor ID: " . $lastLog->actor_id . "\n";
}
