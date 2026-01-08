<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Audit Logs Summary ===\n\n";
echo "Total logs: " . \App\Models\AuditLog::count() . "\n\n";

$logs = \App\Models\AuditLog::orderBy('id', 'desc')->take(10)->get();

foreach ($logs as $log) {
    echo "ID: {$log->id} | Event: {$log->event} | Actor: {$log->actor_id} | Created: {$log->created_at}\n";
}
