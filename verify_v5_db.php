<?php

use App\Models\PdvTurno;
use App\Models\PdvVenda;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Database Verification Results ---\n";

// 1. Verify Turno Fechado
$turno = PdvTurno::where('id_turno', 'CLOSED-001')->first();
echo "\n1. Turno 'CLOSED-001': ";
if ($turno) {
    echo "FOUND\n";
    echo "   - ID: " . $turno->id . "\n";
    echo "   - Status: " . ($turno->fechado ? 'Fechado' : 'Aberto') . "\n";
    echo "   - Data Abertura: " . $turno->data_hora_inicio . "\n";
} else {
    echo "NOT FOUND\n";
}

// 2. Verify Sale 5001 (Status CANCELADO)
$venda1 = PdvVenda::where('id_operacao', 5001)->first();
echo "\n2. Sale 5001 (Main Payload): ";
if ($venda1) {
    echo "FOUND\n";
    echo "   - Status: " . $venda1->status . " (Expected: CANCELADO)\n";
    echo "   - Total: " . $venda1->total . "\n";
    echo "   - UUID: " . $venda1->erp_operacao_uuid . "\n";
} else {
    echo "NOT FOUND\n";
}

// 3. Verify Sale 5002 (Snapshot)
$venda2 = PdvVenda::where('id_operacao', 5002)->first();
echo "\n3. Sale 5002 (Snapshot Payload): ";
if ($venda2) {
    echo "FOUND\n";
    echo "   - Status: " . $venda2->status . " (Expected: CONCLUIDO)\n";
    echo "   - Total: " . $venda2->total . "\n";
    echo "   - Itens Count: " . $venda2->itens()->count() . "\n";
} else {
    echo "NOT FOUND (Might be in pdv_vendas_resumo if V5 logic failed)\n";
}

echo "\n--- End Verification ---\n";
