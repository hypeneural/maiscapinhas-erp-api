<?php
use Illuminate\Support\Facades\DB;
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storeId = 13;
$store = \App\Models\Store::find($storeId);
if (!$store) {
    echo "Loja {$storeId} não encontrada.\n";
    exit;
}

$storePdvId = $store->pdv_store_id;
echo "Loja {$storeId} tem PDV ID: {$storePdvId}\n";

if (!$storePdvId) {
    echo "PDV ID não definido.\n";
    exit;
}



$count = DB::table('pdv_venda_itens')->where('store_pdv_id', $storePdvId)->count();
echo "Itens de venda encontrados para store_pdv_id {$storePdvId}: {$count}\n";

$sellers = DB::table('pdv_venda_itens')
    ->where('store_pdv_id', $storePdvId)
    ->select('vendedor_pdv_id')
    ->distinct()
    ->get();

echo "Vendedores distintos encontrados: " . $sellers->count() . "\n";
foreach ($sellers as $s) {
    echo " - ID: {$s->vendedor_pdv_id}\n";
}

$pagamentos = DB::table('pdv_turno_pagamentos')
    ->where('store_pdv_id', $storePdvId)
    ->count();
echo "Pagamentos de turno encontrados: {$pagamentos}\n";
