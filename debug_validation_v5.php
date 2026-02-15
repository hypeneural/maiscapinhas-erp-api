<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Pdv\PdvSaleValidator;
use App\Models\PdvVenda;

// Target Sale: ID 695
// UUID: 5e33576d-b020-44c1-ad50-15d1e1249b10
// Loja UUID: B87A7D32-F2BB-4FAE-AB2C-46FD5F5BCE9D
// NFCe: 42260229094289000560650010000134491432775653
// Total: 48.90
// Date: 2026-02-15 21:13:41

$validator = new PdvSaleValidator();

function test($name, $payload, $expectedType)
{
    global $validator;
    echo "\n--- Test: $name ---\n";
    $result = $validator->validateFromErpPayload(['payload' => $payload]);

    if (!($result['ok'] ?? false)) {
        echo "FAILED: Validation returned error.\n";
        print_r($result);
        return;
    }

    if (!$result['found']) {
        echo "FAILED: Sale not found.\n";
        print_r($result);
        return;
    }

    $matchType = $result['best_match']['match_type'] ?? 'legacy';
    echo "Match Type: $matchType\n";

    if ($matchType === $expectedType) {
        echo "SUCCESS: Matched as $expectedType\n";
    } else {
        echo "FAILURE: Expected $expectedType, got $matchType\n";
    }
}

// 1. Golden Match (UUIDs)
$payloadGolden = [
    'ErpOperacaoUuid' => '5e33576d-b020-44c1-ad50-15d1e1249b10',
    'LojaId' => 'B87A7D32-F2BB-4FAE-AB2C-46FD5F5BCE9D',
    'Data' => '2026-02-15T21:13:41',
    'ValorTotalLiquido' => 48.90,
    'Itens' => [], // Empty for brevity, focused on header match
];
test("Golden Key Match", $payloadGolden, 'golden_key_uuid');

// 2. Fiscal Match (Wrong Operacao UUID, Correct NFCe)
$payloadFiscal = $payloadGolden;
$payloadFiscal['ErpOperacaoUuid'] = '00000000-0000-0000-0000-000000000000'; // Wrong UUID
$payloadFiscal['DocumentosFiscais'] = [
    ['Chave' => '42260229094289000560650010000134491432775653']
];
test("Fiscal Key Match", $payloadFiscal, 'fiscal_key_nfce');

// 3. Legacy Match (Wrong UUIDs, Wrong NFCe, Correct Total/Date)
$payloadLegacy = $payloadGolden;
$payloadLegacy['ErpOperacaoUuid'] = '00000000-0000-0000-0000-000000000000';
$payloadLegacy['Loja'] = ['Nome' => 'MAIS CAPINHAS BOMBINHAS']; // Fallback name match if GUID mapping fails (but here GUID logic will run if LojaId is present)
// Actually, Validator tries ResolveStorePdvId. If LojaId is present, it looks up mapping. 
// Step 1973 showed resolveStorePdvId handles LojaId.
// For legacy test, we need `LojaId` to still resolve strictly to store_pdv_id but NOT match the `erp_loja_uuid` column in query? 
// No, `erp_loja_uuid` is derived from `LojaId`. If we pass `LojaId`, it resolves `erpLojaUuid`.
// So query `where('erp_loja_uuid', ...)` will run. 
// If we want to test legacy fallback, we need key lookups to FAIL.
// So we pass valid LojaId (to resolve store Pdv ID correctly) but invalid Key values.
// BUT `erpLojaUuid` is also used for the keys. 
// If we pass correct `LojaId`, `erp_loja_uuid` matches.
// So we need `erp_operacao_uuid` to mismatch AND `nfce_chave` to mismatch.
$payloadLegacy['DocumentosFiscais'] = [
    ['Chave' => '00000000000000000000000000000000000000000000']
];
test("Legacy Heuristic Match", $payloadLegacy, 'heuristic');
