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
    ],
    // Adicionando itens fakes para teste de estrutura, não vai bater 100% se o DB tiver itens reais, 
    // mas evita o "Matches because both empty" se o DB estiver vazio.
    // O objetivo aqui é ver se o script roda sem erros.
    'Itens' => [
        ['Codigo' => '999', 'Quantidade' => 1, 'ValorTotalLiquido' => 84.90]
    ],
    'MeiosDePagamentosAgrupados' => [
        [
            'MeiosDePagamentos' => [
                ['Descricao' => 'Pix', 'Valor' => 84.90]
            ]
        ]
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

// Caso Loja 5 (MC Komprão) - Venda de R$ 32,81 (Liq) - Total Bruto 35.00
// Data ERP: 2026-02-14 15:29:31.6079127 (Local) -> UTC ~18:29
$payloadLoja5 = json_encode([
    // Corrigido ID operacao com base no JSON Loja 5 (Exemplo 3) - Ops, o Exemplo 3 tem CodigoDaOperacao 297561? Nao, o exemplo 3 tem valor liquido 60.00 e Data 15:28:05.
    // Analisando JSON Loja 5 do User:
    // Exemplo 3:
    // "CodigoDaOperacao": 297561, "Data": "2026-02-14T15:28:05", "ValorTotalLiquido": 60.00, "Loja": {"Nome": "Loja 5 - MC Komprão BR Tijucas"}

    // Vou usar esse do exemplo 3 (Loja 5 - 60.00)
    'CodigoDaOperacao' => 297561,
    'Data' => '2026-02-14T15:28:05',
    'ValorTotalLiquido' => 60.00,
    'Loja' => [
        'Nome' => 'Loja 5 - MC Komprão BR Tijucas',
        'Id' => 'cbfa4e39-c3db-45cf-8b9b-a9a6b6574227'
    ]
]);

$input5 = ['payload' => $payloadLoja5];
echo "\n[3] Testando Loja 5 (Esperado: Found=true)...\n";
$result5 = $validator->validateFromErpPayload($input5);
if ($result5['found'] ?? false) {
    echo "    [SUCCESS] Encontrado!\n";
    echo "    Match 100%: " . ($result5['match_100'] ? 'SIM' : 'NAO') . "\n";
} else {
    echo "    [FAIL] Nao encontrado.\n";
    print_r($result5['reason'] ?? $result5);
}

// Caso Cancelada
// Exemplo 2: "Cancelada": true, "ValorTotalLiquido": 1450.00
$payloadCancelada = json_encode([
    'Cancelada' => true,
    'CodigoDaOperacao' => 297565,
    'Data' => '2026-02-14T15:35:23',
    'ValorTotalLiquido' => 1450.00,
    'Loja' => ['Nome' => 'Loja 4 - iTuntz']
]);
$inputCancel = ['payload' => $payloadCancelada];
echo "\n[4] Testando Venda Cancelada (Esperado: Found=false, status_erp=CANCELLED)...\n";
$resultCancel = $validator->validateFromErpPayload($inputCancel);
if (($resultCancel['status_erp'] ?? '') === 'CANCELLED') {
    echo "    [SUCCESS] Identificou cancelamento corretamente.\n";
} else {
    echo "    [FAIL] Falhou em identificar cancelamento.\n";
    print_r($resultCancel);
}
