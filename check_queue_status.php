<?php

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$since = Carbon::now()->subHours(24);

echo "Analyzing PDV Syncs and Queue Status (Since $since)\n\n";

// 1. Check PDV Sync Status Distribution
$stats = DB::table('pdv_syncs')
    ->select('status', DB::raw('count(*) as total'))
    ->where('created_at', '>=', $since)
    ->groupBy('status')
    ->get();

echo "PDV Syncs Status Distribution:\n";
foreach ($stats as $s) {
    echo "  Status '{$s->status}': {$s->total}\n";
}

// 2. Check for recent stuck 'processing' jobs
$stuck = DB::table('pdv_syncs')
    ->where('status', 'processing')
    ->where('updated_at', '<', Carbon::now()->subMinutes(30))
    ->count();
echo "\nStuck 'processing' (> 30 mins): $stuck\n";

// 3. Check Failed Jobs Table
$failed = DB::table('failed_jobs')
    ->where('failed_at', '>=', $since)
    ->count();

echo "\nFailed Jobs (Last 24h): $failed\n";
if ($failed > 0) {
    $recentFailures = DB::table('failed_jobs')
        ->select('payload', 'exception', 'failed_at')
        ->orderByDesc('id')
        ->limit(3)
        ->get();

    echo "Recent Failures:\n";
    foreach ($recentFailures as $f) {
        $jobName = json_decode($f->payload)->displayName ?? 'Unknown';
        echo "  At: {$f->failed_at} | Job: $jobName | Err: " . substr($f->exception, 0, 100) . "...\n";
    }
}

// 4. Check for 'pdv_closures' population
$recentClosures = DB::table('pdv_closures')
    ->where('created_at', '>=', $since)
    ->count();
echo "\nNew entries in 'pdv_closures' (Last 24h): $recentClosures\n";

