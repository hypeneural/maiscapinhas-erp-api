<?php

use Illuminate\Support\Facades\DB;
use App\Models\PdvVenda;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$vendaId = 458;
echo "--- Analisando Venda ID: $vendaId ---\n";

$venda = PdvVenda::find($vendaId);

if (!$venda) {
    die("Venda nao encontrada.\n");
}

echo "Venda encontrada: \n";
echo "  ID: {$venda->id}\n";
echo "  Store PDV ID: {$venda->store_pdv_id}\n";
echo "  ID Operacao: {$venda->id_operacao}\n";
echo "  Data: {$venda->data_hora}\n";
echo "  Total: {$venda->total}\n";

// Check items using Manual Load simulation (The FIX)
$venda->setRelation(
    'itens',
    DB::table('pdv_venda_itens')
        ->where('store_pdv_id', $venda->store_pdv_id)
        ->where('id_operacao', $venda->id_operacao)
        ->get()
);
$venda->setRelation(
    'pagamentos',
    DB::table('pdv_venda_pagamentos')
        ->where('store_pdv_id', $venda->store_pdv_id)
        ->where('id_operacao', $venda->id_operacao)
        ->get()
);

echo "\nAfter Manual SetRelation:\n";
echo "  Itens: " . $venda->itens->count() . "\n";
echo "  Pagamentos: " . $venda->pagamentos->count() . "\n";

// Check items using Manual Query (ignoring potentially broken relations)
echo "\nManual Query Check (pdv_venda_itens):\n";
$itensQuery = DB::table('pdv_venda_itens')
    ->where('store_pdv_id', $venda->store_pdv_id)
    ->where('id_operacao', $venda->id_operacao)
    ->get();
echo "  Rows found matching (store_pdv_id, id_operacao): " . $itensQuery->count() . "\n";

if ($itensQuery->isEmpty()) {
    echo "  [WARN] Nenhum item encontrado com a chave composta padrão.\n";
    // Tentar buscar apenas pelo ID da operacao, caso o store_id esteja diferente (improvavel mas possivel)
    $itensLoose = DB::table('pdv_venda_itens')->where('id_operacao', $venda->id_operacao)->get();
    echo "  Rows found matching ONLY (id_operacao): " . $itensLoose->count() . "\n";
    if ($itensLoose->isNotEmpty()) {
        foreach ($itensLoose as $i) {
            echo "    -> Found item with store_pdv_id: {$i->store_pdv_id}\n";
        }
    }
} else {
    foreach ($itensQuery as $i) {
        echo "    Item: {$i->codigo_barras} / Qtd: {$i->qtd} / Total: {$i->total}\n";
    }
}

echo "\nManual Query Check (pdv_venda_pagamentos):\n";
$payQuery = DB::table('pdv_venda_pagamentos')
    ->where('store_pdv_id', $venda->store_pdv_id)
    ->where('id_operacao', $venda->id_operacao)
    ->get();
echo "  Rows found matching (store_pdv_id, id_operacao): " . $payQuery->count() . "\n";

if ($payQuery->isEmpty()) {
    echo "  [WARN] Nenhum pagamento encontrado.\n";
} else {
    foreach ($payQuery as $p) {
        echo "    Pay: {$p->meio_pagamento} / Valor: {$p->valor}\n";
    }
}
