<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storePdvId = 6;
$idOperacao = 25907;
$canal = 'HIPER_CAIXA';

echo "Checking Sale: Store PDV ID: $storePdvId, Op ID: $idOperacao, Canal: $canal\n";

// 1. Check Sale
$venda = DB::table('pdv_vendas')
    ->where('store_pdv_id', $storePdvId)
    ->where('id_operacao', $idOperacao)
    ->where('canal', $canal)
    ->first();

if (!$venda) {
    echo "Sale NOT FOUND in pdv_vendas!\n";
    exit;
}
echo "Sale FOUND. ID: {$venda->id}, Total: {$venda->total}, Date: {$venda->data_hora}\n";

// 2. Check Items
$items = DB::table('pdv_venda_itens')
    ->where('store_pdv_id', $storePdvId)
    ->where('id_operacao', $idOperacao)
    ->where('canal', $canal)
    ->get();

echo "\nItems Found: " . $items->count() . "\n";
foreach ($items as $item) {
    echo " - Line {$item->line_no}: ProdID={$item->id_produto}, Name='{$item->nome_produto}', Barcode='{$item->codigo_barras}'\n";
    echo "   Vendedor PDV ID: {$item->vendedor_pdv_id}, Name: '{$item->vendedor_nome}'\n";
    // Check if UUID exists if column exists
    if (isset($item->vendedor_guid)) {
        echo "   Vendedor GUID: {$item->vendedor_guid}\n";
    }
}

// 3. Check Payments
$payments = DB::table('pdv_venda_pagamentos')
    ->where('store_pdv_id', $storePdvId)
    ->where('id_operacao', $idOperacao)
    ->where('canal', $canal)
    ->get();


// 4. Reprocess Sync (Verification Step)
$syncId = $venda->sync_id;
echo "\nSync ID found: $syncId\n";

if ($syncId) {
    $sync = \App\Models\PdvSync::where('sync_id', $syncId)->first();
    if ($sync) {
        // Inspect Payload (Direct DB)
        $payloadRaw = DB::table('pdv_sync_payloads')->where('pdv_sync_id', $sync->id)->value('payload');
        if ($payloadRaw) {
            echo "Payload found (DB). Size: " . strlen($payloadRaw) . "\n";
            $decoded = json_decode($payloadRaw, true);
            $vendas = $decoded['vendas'] ?? [];
            if (count($vendas) > 0) {
                echo "First Venda Keys: " . implode(', ', array_keys($vendas[0])) . "\n";
            }
        } else {
            echo "PAYLOAD NOT FOUND IN DB for Sync ID {$sync->id}!\n";
        }
        $sync->update(['status' => 'queued']);
        echo "Dispatching ProcessPdvSyncJob...\n";
        try {
            \App\Jobs\ProcessPdvSyncJob::dispatchSync($sync->id);
            echo "Job executed successfully.\n";
        } catch (\Throwable $e) {
            echo "Job FAILED: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }

        // Re-check Items
        $items = DB::table('pdv_venda_itens')
            ->where('store_pdv_id', $storePdvId)
            ->where('id_operacao', $idOperacao)
            ->where('canal', $canal)
            ->get();
        echo "\n[AFTER REPROCESS] Items Found: " . $items->count() . "\n";
        foreach ($items as $item) {
            echo " - Name: '{$item->nome_produto}', Qtd: {$item->qtd}, Total: {$item->total}\n";
        }

        // Re-check Payments
        $payments = DB::table('pdv_venda_pagamentos')
            ->where('store_pdv_id', $storePdvId)
            ->where('id_operacao', $idOperacao)
            ->where('canal', $canal)
            ->get();
        echo "\n[AFTER REPROCESS] Payments Found: " . $payments->count() . "\n";
        foreach ($payments as $pay) {
            echo " - Meio: '{$pay->meio_pagamento}', Valor: {$pay->valor}\n";
        }
    } else {
        echo "Sync record not found.\n";
    }
}
