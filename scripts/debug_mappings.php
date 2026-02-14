<?php

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$start = now()->startOfMonth();
$end = now()->endOfMonth();

echo "Checking Missing Mappings for period: " . $start->format('Y-m-d') . " to " . $end->format('Y-m-d') . "\n";

$sellersWithSales = DB::table('pdv_venda_itens as vi')
    ->join('pdv_vendas as v', function ($join) {
        $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
            ->on('v.canal', '=', 'vi.canal')
            ->on('v.id_operacao', '=', 'vi.id_operacao');
    })
    ->whereBetween('v.data_hora', [$start, $end])
    ->select('vi.store_pdv_id', 'vi.vendedor_pdv_id', DB::raw('count(*) as items_sold'))
    ->groupBy('vi.store_pdv_id', 'vi.vendedor_pdv_id')
    ->get();

echo "found " . $sellersWithSales->count() . " active sellers in PDV.\n";
echo "---------------------------------------------------\n";
echo str_pad("Store PDV", 10) . " | " . str_pad("Seller PDV", 10) . " | " . str_pad("Mapped?", 10) . " | Items\n";
echo "---------------------------------------------------\n";

$missing = 0;
foreach ($sellersWithSales as $s) {
    $exists = DB::table('pdv_user_mappings')
        ->where('store_pdv_id', $s->store_pdv_id)
        ->where('pdv_user_id', $s->vendedor_pdv_id)
        ->where('active', true)
        ->exists();

    $status = $exists ? "YES" : "NO";
    if (!$exists)
        $missing++;

    echo str_pad($s->store_pdv_id, 10) . " | " . str_pad($s->vendedor_pdv_id, 10) . " | " . str_pad($status, 10) . " | " . $s->items_sold . "\n";
}

echo "---------------------------------------------------\n";
echo "Total Missing Mappings: $missing\n";
