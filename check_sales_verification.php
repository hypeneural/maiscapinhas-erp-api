<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Store;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function checkSale($label, $idOperacaoTarget, $dataTarget, $totalTarget, $storeName)
{
    echo "--------------------------------------------------\n";
    echo "Verificando: {$label}\n";
    echo "    -> ID Operacao Alvo: {$idOperacaoTarget}\n";
    echo "    -> Data Alvo (ERP): {$dataTarget}\n";
    echo "    -> Total Alvo: {$totalTarget}\n";
    echo "    -> Loja Alvo: {$storeName}\n";
    echo "--------------------------------------------------\n";

    // 1. Busca Direta por ID (Hard Match)
    echo "[1] Busca por ID (id_operacao = {$idOperacaoTarget})...\n";
    $byID = DB::table('pdv_vendas')->where('id_operacao', $idOperacaoTarget)->get();

    if ($byID->count() > 0) {
        echo "    [SUCCESS] Encontrado(s) " . $byID->count() . " registro(s) por ID.\n";
        foreach ($byID as $v) {
            dumpSale($v, 'MATCH_ID');
        }
    } else {
        echo "    [FAIL] Nenhum registro encontrado por ID.\n";
    }

    // 2. Busca por Assinatura Robusta (Store + Valor + Janela Timezone Offset)
    // A logica agora eh: Data ERP eh INICIO. Data Banco eh FIM (em UTC).
    // Entao Data Banco deve ser MAIOR que Data ERP.
    // Janela recomendada: [Data ERP + 2h50m] ate [Data ERP + 4h30m]
    // (+3h do timezone e margem para duracao da venda/sync)

    echo "\n[2] Busca por Assinatura Robusta (Janela Offset Timezone)...\n";
    $dt = \Carbon\Carbon::parse($dataTarget);

    // Offset basico de +3h
    // Margem inferior: -10m do offset (2h50m)
    // Margem superior: +90m do offset (4h30m) - para cobrir vendas longas ou delay
    $start = $dt->copy()->addHours(2)->addMinutes(50);
    $end = $dt->copy()->addHours(4)->addMinutes(30);

    echo "    -> Janela de Busca (UTC Estimado): {$start} ate {$end}\n";
    echo "    -> Total: {$totalTarget}\n";

    $candidates = DB::table('pdv_vendas')
        ->whereBetween('data_hora', [$start, $end])
        ->whereBetween('total', [$totalTarget - 0.05, $totalTarget + 0.05])
        ->get();

    if ($candidates->count() > 0) {
        echo "    [SUCCESS] Encontrado(s) " . $candidates->count() . " candidato(s).\n";
        foreach ($candidates as $v) {
            dumpSale($v, 'MATCH_ROBUSTO');

            // Check itens se possivel
            $itensCount = DB::table('pdv_venda_itens')
                ->where('store_pdv_id', $v->store_pdv_id)
                ->where('id_operacao', $v->id_operacao)
                ->count();
            echo "       -> Itens no Banco: {$itensCount}\n";

            // Se nao foi achado por ID, avisa
            if ($byID->isEmpty() || !$byID->contains('id', $v->id)) {
                echo "       *** ID Operacao no Banco: {$v->id_operacao} (Diferente do Alvo) ***\n";
                // Diff time
                $dbDate = \Carbon\Carbon::parse($v->data_hora);
                $diff = $dbDate->diffInMinutes($dt, false);
                echo "       *** Diferenca de Tempo Real: {$diff} minutos (DB - ERP) ***\n";
            }
        }
    } else {
        echo "    [FAIL] Nenhum candidato encontrado por assinatura.\n";
    }
    echo "\n\n";
}

function dumpSale($v, $tag)
{
    $store = DB::table('stores')->find($v->store_id);
    $storeName = $store ? $store->name : 'N/A';

    echo "    -> [{$tag}] ID: {$v->id} | Op: {$v->id_operacao} | Data: {$v->data_hora} | Total: {$v->total} | Loja: {$storeName} (PDV: {$v->store_pdv_id})\n";
}

echo "iniciando verificacao de vendas no banco de dados...\n\n";

// Caso 1: Venda Loja 6 (Gov Celso Ramos)
// 12:08 ERP -> ~15:08 DB
checkSale('Caso 1 (Gov Celso Ramos)', 297485, '2026-02-14 12:08:11', 29.00, 'Loja 6');

// Caso 2: Venda Loja 1 (Komprao Centro TJ)
// 11:44 ERP -> ~14:44 DB
checkSale('Caso 2 (Komprao Centro TJ)', 297480, '2026-02-14 11:44:11', 96.00, 'Loja 1');

// Caso 3: Loja 12 (Porto Belo)
// JSON ERP: Codigo 297556, Data 15:22:04, Valor 35.00
// Webhook: id_operacao 33586, Data 15:26:21 (UTC 18:26)
checkSale('Caso 3 (Loja 12 - Porto Belo)', 297556, '2026-02-14 15:22:04', 35.00, 'Loja 12');

// Caso 4: Loja 3 (Outlet)
// JSON ERP: Codigo 297542, Data 14:53:11, Valor 45.00
checkSale('Caso 4 (Loja 3 - Outlet)', 297542, '2026-02-14 14:53:11', 45.00, 'Loja 3');

// Caso 5: Loja 4 (iTuntz)
// JSON ERP: Codigo 297568, Data 15:40:17, Valor 84.90
// API Confirmada: id_operacao 7780, Data 18:40:25 (UTC), Valor 84.9
checkSale('Caso 5 (Loja 4 - iTuntz)', 297568, '2026-02-14 15:40:17', 84.90, 'Loja 4');
