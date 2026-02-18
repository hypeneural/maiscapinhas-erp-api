<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$search = 'Loja 1';
echo "Searching for store: $search\n";

$stores = DB::table('stores')
    ->where('name', 'like', "%$search%")
    ->get();

foreach ($stores as $s) {
    // Check if store_pdv_id exists, otherwise just print ID and Name
    $pdvId = $s->store_pdv_id ?? 'N/A';
    echo "ID: {$s->id} | PDV: $pdvId | Name: {$s->name} | GUID: {$s->guid}\n";
}
