<?php

use Illuminate\Support\Facades\DB;
use App\Models\PdvVenda;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Parameters (Same as problematic sale)
$storePdvId = 6;
$idOperacao = 25907;
$canal = 'HIPER_CAIXA';

echo "Testing VendaDetalhe Query for StorePDV: $storePdvId, Op: $idOperacao\n";

// 1. Venda Query
try {
    $venda = DB::table('pdv_vendas as v')
        ->leftJoin('stores as s', 'v.store_id', '=', 's.id')
        ->leftJoin('pdv_lojas as pl', 'v.store_pdv_id', '=', 'pl.id_ponto_venda')
        ->select([
            'v.store_id',
            's.name as store_name', // Internal Store Name
            'v.store_pdv_id',
            'pl.nome_padronizado as store_pdv_name', // PDV Store Name
            'v.canal',
            'v.id_operacao',
            'v.id_turno',
            'v.data_hora',
            'v.total',
            'v.erp_operacao_uuid',
            'v.erp_loja_uuid',
        ])
        ->where('v.store_pdv_id', $storePdvId)
        ->where('v.canal', $canal)
        ->where('v.id_operacao', $idOperacao)
        ->first();

    if ($venda) {
        echo "Venda Found!\n";
        echo " - Store Name: " . ($venda->store_name ?? 'NULL') . "\n";
        echo " - PDV Store Name: " . ($venda->store_pdv_name ?? 'NULL') . "\n";
        echo " - CNPJ: " . ($venda->store_cnpj ?? 'NULL') . "\n";
        echo " - UUID: " . ($venda->erp_operacao_uuid ?? 'NULL') . "\n";
    } else {
        echo "Venda NOT Found.\n";
        exit;
    }
} catch (\Exception $e) {
    echo "Venda Query Failed: " . $e->getMessage() . "\n";
    exit;
}

// 2. Items Query
try {
    $itens = DB::table('pdv_venda_itens as vi')
        ->select([
            'vi.id',
            'vi.nome_produto',
            'vi.vendedor_guid', // V5 UUID
            'vi.vendedor_pdv_id',
        ])
        ->where('vi.store_pdv_id', $storePdvId)
        ->where('vi.canal', $canal)
        ->where('vi.id_operacao', $idOperacao)
        ->leftJoin('pdv_user_mappings as pum', function ($join) {
            $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id');
        })
        ->leftJoin('users as u', 'pum.user_id', '=', 'u.id')
        ->addSelect([
            'u.whatsapp as vendedor_whatsapp',
            'u.avatar_url as vendedor_avatar_url',
        ])
        ->get();

    echo "Items Found: " . $itens->count() . "\n";
    foreach ($itens as $item) {
        echo " - Item: " . ($item->nome_produto ?: 'EMP_NAME') . "\n";
        echo "   - Vendedor GUID: " . ($item->vendedor_guid ?? 'NULL') . "\n";
        echo "   - WhatsApp: " . ($item->vendedor_whatsapp ?? 'NULL') . "\n";
    }

} catch (\Exception $e) {
    echo "Item Query Failed: " . $e->getMessage() . "\n";
}
