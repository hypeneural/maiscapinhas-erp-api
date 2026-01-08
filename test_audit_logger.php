<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Http\Request;

echo "=== Testing AuditLogger ===\n\n";

// Check current logs count
$beforeCount = \App\Models\AuditLog::count();
echo "Logs before test: $beforeCount\n";

// Get AuditLogger from container
$auditLogger = app(\App\Support\Audit\AuditLogger::class);
$auditContext = app(\App\Support\Audit\AuditContext::class);

echo "AuditContext request_id: " . ($auditContext->getRequestId() ?: 'NULL') . "\n";

// Manually set context (simulating middleware)
$auditContext->setFromRequest(Request::create('/test', 'GET'));

echo "After setFromRequest - request_id: " . $auditContext->getRequestId() . "\n";

// Try to log something
try {
    $user = \App\Models\User::first();
    
    $auditLogger->logAuth('test_login', $user, [
        'test' => 'from_script',
        'method' => 'direct_call',
    ]);
    
    echo "\nlogAuth() called successfully!\n";
    
} catch (\Exception $e) {
    echo "\nERROR in logAuth(): " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Check logs after
$afterCount = \App\Models\AuditLog::count();
echo "\nLogs after test: $afterCount\n";
echo "New logs created: " . ($afterCount - $beforeCount) . "\n";

// Show last log
if ($afterCount > $beforeCount) {
    $lastLog = \App\Models\AuditLog::latest('id')->first();
    echo "\n=== Last Log ===\n";
    echo "ID: " . $lastLog->id . "\n";
    echo "Event: " . $lastLog->event . "\n";
    echo "Action: " . $lastLog->action . "\n";
    echo "Actor ID: " . $lastLog->actor_id . "\n";
    echo "Request ID: " . $lastLog->request_id . "\n";
}
