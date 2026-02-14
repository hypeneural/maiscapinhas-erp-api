<?php

use App\Http\Controllers\Api\PdvSaleValidateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--------------------------------------------------\n";
echo "Verificando Endpoint de Validacao de Vendas\n";
echo "--------------------------------------------------\n";

// Caso Loja 12 (Porto Belo) - Venda de R$ 35,00
// Data ERP: 2026-02-14 15:22:04 (Local) -> UTC 18:22
$payloadLoja12 = json_encode([
    'CodigoDaOperacao' => 297556,
    'Data' => '2026-02-14T15:22:04',
    'ValorTotalLiquido' => 35.00,
    'Loja' => [
        'Nome' => 'Loja 12 - MC Porto Belo',
        'Id' => 'uuid-qualquer'
    ],
    'Itens' => [
        [
            'Codigo' => '1234', // Fake, apenas para estrutura
            'Quantidade' => 1,
            'ValorTotalLiquido' => 35.00
        ]
    ],
    'MeiosDePagamentosAgrupados' => [
        [
            'MeiosDePagamentos' => [
                [
                    'Descricao' => 'Dinheiro',
                    'Valor' => 35.00
                ]
            ]
        ]
    ]
]);

echo "\n[1] Testando Loja 12 (Esperado: Found=true)...\n";
$validator = new \App\Services\Pdv\PdvSaleValidator();
$input12 = [
    'payload' => $payloadLoja12,
    'timezone' => 'America/Sao_Paulo'
];

// Mock do metodo resolveStorePdvIdReal se fosse necessario, mas como ele esta na classe...
// Oops, resolveStorePdvIdReal eh public agora, entao o validador deve funcionar.

$result12 = $validator->validateFromErpPayload($input12);

if ($result12['found'] ?? false) {
    echo "    [SUCCESS] Encontrado!\n";
    echo "    Match 100%: " . ($result12['match_100'] ? 'SIM' : 'NAO') . "\n";
    echo "    Best Match ID: " . ($result12['best_match']['pdv_venda_id'] ?? 'N/A') . "\n";
} else {
    echo "    [FAIL] Nao encontrado.\n";
    print_r($result12);
}

// Caso Loja 4 (iTuntz)
$payloadLoja4 = json_encode([
    'CodigoDaOperacao' => 297568,
    'Data' => '2026-02-14T15:40:17',
    'ValorTotalLiquido' => 84.90,
    'Loja' => [
        'Nome' => 'Loja 4 - iTuntz',
        'Id' => 'uuid-qualquer'
    ]
]);

$input4 = [
    'payload' => $payloadLoja4
];

echo "\n[2] Testando Loja 4 (Esperado: Found=true)...\n";
$result4 = $validator->validateFromErpPayload($input4);

if ($result4['found'] ?? false) {
    echo "    [SUCCESS] Encontrado!\n";
    echo "    Match 100%: " . ($result4['match_100'] ? 'SIM' : 'NAO') . "\n";
    echo "    Best Match ID: " . ($result4['best_match']['pdv_venda_id'] ?? 'N/A') . "\n";
} else {
    echo "    [FAIL] Nao encontrado.\n";
    print_r($result4);
}
