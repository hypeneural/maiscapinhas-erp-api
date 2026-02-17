<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "Checking Tables Schema...\n";

$tables = ['stores', 'users', 'pdv_store_mappings', 'pdv_user_mappings', 'pdv_lojas', 'pdv_usuarios'];

foreach ($tables as $table) {
    echo "Table: $table\n";
    if (Schema::hasTable($table)) {
        $columns = Schema::getColumnListing($table);
        echo "  Columns: " . implode(', ', $columns) . "\n";

        if (in_array('guid', $columns)) {
            echo "  Has 'guid' column.\n";
            $count = DB::table($table)->whereNotNull('guid')->count();
            echo "  Rows with GUID: $count\n";

            // Show sample
            $sample = DB::table($table)->whereNotNull('guid')->first(['id', 'guid']);
            if ($sample) {
                echo "  Sample: ID={$sample->id}, GUID={$sample->guid}\n";
            }
        } elseif (in_array('guid_loja', $columns)) {
            echo "  Has 'guid_loja' column.\n";
        } elseif (in_array('guid_usuario', $columns)) {
            echo "  Has 'guid_usuario' column.\n";
        }
    } else {
        echo "  Table does not exist.\n";
    }
    echo "\n";
}

echo "Checking Store 9 (Mata Atlantica)...\n";
// From previous context, Store 9 ID in pdv_turnos/vendas was '9'. But is that pdv_store_id or store_id?
// In verify script we query pdv_turnos.store_pdv_id = 9.

$pdvStoreId = 9;
echo "PDV Store ID: $pdvStoreId\n";

// Check pdv_lojas
$pdvLoja = DB::table('pdv_lojas')->where('id_ponto_venda', $pdvStoreId)->first();
if ($pdvLoja) {
    echo "Found in pdv_lojas: GUID=" . ($pdvLoja->guid ?? 'NULL') . "\n";

    // Check if this GUID exists in stores table
    if (!empty($pdvLoja->guid)) {
        $store = DB::table('stores')->where('guid', $pdvLoja->guid)->first();
        if ($store) {
            echo "MATCH in stores table: ID={$store->id}, Name={$store->name}\n";
        } else {
            echo "NO MATCH in stores table for GUID {$pdvLoja->guid}\n";
        }
    }
} else {
    echo "Not found in pdv_lojas.\n";
}
