<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storeId = 1; // Loja 1
$date = '2026-02-18';
$seq = 2;

echo "Checking Turno $seq for Loja $storeId on $date\n";

$turnos = DB::table('pdv_turnos')
    ->where('store_id', $storeId)
    ->whereDate('data_hora_inicio', $date)
    ->where('sequencial', $seq)
    ->get();

if ($turnos->isEmpty()) {
    echo "No turnos found.\n";
    exit;
}

foreach ($turnos as $t) {
    echo "Turno ID: {$t->id} | PDV ID: {$t->store_pdv_id} | Canal: {$t->canal} | Fechado: {$t->fechado} | UUID: {$t->closure_uuid}\n";
    echo "  Total Sistema: {$t->total_sistema}\n";
    echo "  Total Declarado: {$t->total_declarado}\n";

    $payments = DB::table('pdv_turno_pagamentos')
        ->where('id_turno', $t->id_turno)
        ->get();

    if ($payments->isEmpty()) {
        echo "  No payments found.\n";
    } else {
        foreach ($payments as $p) {
            echo "  Payment: {$p->tipo} | Meio: {$p->meio_pagamento} | Val: {$p->total}\n";
        }
    }
}
