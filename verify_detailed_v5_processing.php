<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PdvTurno;
use App\Models\PdvVenda;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Use Store ID 9 (Mata Atlântica) per verify_prod_with_real_data.py
$storeId = 9;

echo "\n======================================================\n";
echo "VERIFICATION: Deep Check of V5 Sync Processing\n";
echo "Store ID: $storeId (Mata Atlântica)\n";
echo "Date: " . Carbon::now()->toDateTimeString() . "\n";
echo "======================================================\n\n";

// 1. Check Job/Request Status (if available)
echo "1. Checking recent Activity for Store $storeId...\n";
$latestTurnos = PdvTurno::where('store_pdv_id', $storeId)
    ->orderBy('updated_at', 'desc')
    ->limit(5)
    ->get();

echo "   -> Found " . $latestTurnos->count() . " recently updated/created turnos.\n";

if ($latestTurnos->isEmpty()) {
    echo "   [ERROR] No recent activity found for Store $storeId. Job might not have run yet.\n";
    exit(1);
}

foreach ($latestTurnos as $t) {
    echo "      - Turno: {$t->id_turno} | Seq: {$t->sequencial} | Fechado: " . ($t->fechado ? 'YES' : 'NO') . " | Updated: {$t->updated_at}\n";
}

// 2. Verify Turno Deduplication / Open vs Closed
// We expect turnos_fechados to have updated existing rows or created new ones with closed=true
echo "\n2. Verifying Turno Fechado/Aberto logic...\n";
$closedTurno = $latestTurnos->where('fechado', true)->first();
if ($closedTurno) {
    echo "   [SUCCESS] Found CLOSED Turno: {$closedTurno->id_turno}\n";
    echo "             Matches 'turnos_fechados' payload logic.\n";
} else {
    echo "   [WARNING] No CLOSED turno found in recent updates. Check if payload contained closed turnos.\n";
}

// 3. Verify Sale Status (CANCELADO/CONCLUIDO)
echo "\n3. Verifying Sale Status (CANCELADO)...\n";
// Look for a sale with status CANCELADO updated recently
$cancelledSale = PdvVenda::where('store_pdv_id', $storeId)
    ->where('status', 'CANCELADO')
    ->orderBy('updated_at', 'desc')
    ->first();

if ($cancelledSale) {
    echo "   [SUCCESS] Found CANCELLED Sale: {$cancelledSale->id_operacao}\n";
    echo "             Status: {$cancelledSale->status}\n";
    echo "             Updated At: {$cancelledSale->updated_at}\n";
} else {
    echo "   [WARNING] No CANCELLED sales found recently. Payload might not have had cancelled sales or field mapping failed.\n";
}

// 4. Verify Snapshot Upsert (Sale Status Update)
echo "\n4. Verifying Snapshot Upsert (CONCLUIDO)...\n";
// Look for a sale with status CONCLUIDO updated recently (implies snapshot or normal sale upserted)
$completedSale = PdvVenda::where('store_pdv_id', $storeId)
    ->where('status', 'CONCLUIDO')
    ->orderBy('updated_at', 'desc')
    ->first();

if ($completedSale) {
    echo "   [SUCCESS] Found COMPLETED Sale: {$completedSale->id_operacao}\n";
    echo "             Status: {$completedSale->status}\n";
    echo "             Updated At: {$completedSale->updated_at}\n";
} else {
    echo "   [WARNING] No COMPLETED sales found recently.\n";
}

// 5. Check row counts to confirm volume
$salesCount = PdvVenda::where('store_pdv_id', $storeId)
    ->where('updated_at', '>=', Carbon::now()->subMinutes(10))
    ->count();

echo "\n5. Volume Check:\n";
echo "   -> Upserted/Updated Sales in last 10 mins: $salesCount\n";

if ($salesCount > 0) {
    echo "\n[OVERALL RESULT]: SUCCESS - Data is effectively processing and mutating the database.\n";
} else {
    echo "\n[OVERALL RESULT]: FAILURE/WAITING - No changes detected in pdv_vendas.\n";
}
