<?php

use Illuminate\Support\Facades\DB;
use App\Models\PdvVenda;
use Carbon\CarbonImmutable;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Parameters (Example from user request)
$page = 1;
$perPage = 15;
$from = CarbonImmutable::parse('2026-02-15')->startOfDay();
$to = CarbonImmutable::parse('2026-02-15')->endOfDay();

echo "Testing Vendas Listing Query for Date: $from to $to\n";

try {
    // Replicate Item Aggregation
    $itemAgg = DB::table('pdv_venda_itens as vi')
        ->select([
            'vi.store_pdv_id',
            'vi.canal',
            'vi.id_operacao',
            DB::raw('COUNT(*) as itens_count'),
            DB::raw('COALESCE(SUM(vi.qtd), 0) as itens_qtd_total'),
            DB::raw('COALESCE(SUM(vi.total), 0) as itens_valor_total'),
            DB::raw('MIN(vi.vendedor_pdv_id) as vendedor_pdv_id'),
            DB::raw('MAX(vi.vendedor_guid) as vendedor_guid'),
            DB::raw('MAX(vi.vendedor_nome) as vendedor_nome_pdv'),
        ])
        ->groupBy('vi.store_pdv_id', 'vi.canal', 'vi.id_operacao');

    // Replicate Payment Aggregation
    $paymentAgg = DB::table('pdv_venda_pagamentos as vp')
        ->select([
            'vp.store_pdv_id',
            'vp.canal',
            'vp.id_operacao',
            DB::raw('COUNT(*) as pagamentos_count'),
            DB::raw('COALESCE(SUM(vp.valor), 0) as pagamentos_valor_total'),
        ])
        ->groupBy('vp.store_pdv_id', 'vp.canal', 'vp.id_operacao');

    // Main Query
    $query = DB::table('pdv_vendas as v')
        ->leftJoin('stores as s', 'v.store_id', '=', 's.id')
        ->leftJoin('pdv_lojas as pl', 'v.store_pdv_id', '=', 'pl.id_ponto_venda')
        ->leftJoinSub($itemAgg, 'it', function ($join) {
            $join->on('it.store_pdv_id', '=', 'v.store_pdv_id')
                ->on('it.canal', '=', 'v.canal')
                ->on('it.id_operacao', '=', 'v.id_operacao');
        })
        ->leftJoin('pdv_usuarios as u_guid', 'u_guid.guid_usuario', '=', 'it.vendedor_guid')
        ->leftJoin('pdv_user_mappings as pum', function ($join) {
            $join->on('pum.store_pdv_id', '=', 'v.store_pdv_id')
                ->on('pum.pdv_user_id', '=', 'it.vendedor_pdv_id');
        })
        ->leftJoin('users as u_map', 'pum.user_id', '=', 'u_map.id')
        ->leftJoinSub($paymentAgg, 'pg', function ($join) {
            $join->on('pg.store_pdv_id', '=', 'v.store_pdv_id')
                ->on('pg.canal', '=', 'v.canal')
                ->on('pg.id_operacao', '=', 'v.id_operacao');
        })
        ->select([
            'v.id',
            'v.store_id',
            's.name as store_name',
            'v.store_pdv_id',
            'pl.nome_padronizado as store_pdv_name',
            'v.id_operacao',
            'v.canal',
            'v.id_turno',
            'v.data_hora',
            'v.total',
            DB::raw('COALESCE(u_guid.nome_padronizado, u_map.name, it.vendedor_nome_pdv) as seller_name'),
            'v.erp_operacao_uuid',
            'v.erp_loja_uuid',
        ])
        ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()])
        ->orderBy('v.data_hora', 'desc')
        ->limit(5);

    $results = $query->get();

    echo "Sales Found: " . $results->count() . "\n";
    foreach ($results as $row) {
        echo "Sale ID: {$row->id}, StorePDV: {$row->store_pdv_id}, Op: {$row->id_operacao}\n";
        echo " - Store Name: " . ($row->store_name ?? 'NULL') . "\n";
        echo " - PDV Name: " . ($row->store_pdv_name ?? 'NULL') . "\n";
        echo " - Seller: " . ($row->seller_name ?? 'NULL') . "\n";
        echo " - Op UUID: " . ($row->erp_operacao_uuid ?? 'NULL') . "\n";
        echo " - Store UUID: " . ($row->erp_loja_uuid ?? 'NULL') . "\n";
        echo "--------------------------------------------------\n";
    }

} catch (\Exception $e) {
    echo "Query Failed: " . $e->getMessage() . "\n";
}
