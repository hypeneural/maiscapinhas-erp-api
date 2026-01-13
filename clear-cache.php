<?php

// Temporary script to clear config cache
// DELETE THIS FILE AFTER USE!

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Clear config cache
Artisan::call('config:clear');
echo "Config cache cleared!\n";

// Clear application cache
Artisan::call('cache:clear');
echo "Application cache cleared!\n";

echo "\n✅ Done! APP_KEY now: " . config('app.key') . "\n";
echo "\n⚠️ DELETE THIS FILE NOW!\n";
