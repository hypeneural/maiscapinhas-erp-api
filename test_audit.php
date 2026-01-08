<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $log = \App\Models\AuditLog::create([
        'actor_id' => 1,
        'action' => 'test_action',
        'event' => 'test.manual_insert',
        'log_name' => 'test',
        'entity_type' => 'User',
        'entity_id' => 1,
        'store_id' => 1,
        'request_id' => 'test-' . uniqid(),
        'ip' => '127.0.0.1',
        'user_agent' => 'Test Script',
        'after_json' => ['test' => 'data'],
        'created_at' => now(),
    ]);

    echo "=== SUCCESS ===\n";
    echo "Created AuditLog ID: " . $log->id . "\n";
    echo "Total logs: " . \App\Models\AuditLog::count() . "\n";

} catch (\Exception $e) {
    echo "=== ERROR ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
