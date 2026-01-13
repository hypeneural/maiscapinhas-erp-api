<?php
// Run pending migrations
// DELETE THIS FILE AFTER USE!

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();

Artisan::call('config:clear');
echo "Cache cleared!\n";

echo "\n⚠️ DELETE THIS FILE NOW!\n";
