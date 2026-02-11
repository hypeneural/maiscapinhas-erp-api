<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$authMode = 'bearer';
$withLineId = true;
$withOffset = true;
$injectInvalidExtra = false;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--auth=')) {
        $authMode = strtolower(trim(substr($arg, 7)));
    }
    if ($arg === '--without-line-id') {
        $withLineId = false;
    }
    if ($arg === '--naive-datetime') {
        $withOffset = false;
    }
    if ($arg === '--invalid-extra') {
        $injectInvalidExtra = true;
    }
}

config([
    'pdv.auth_mode' => $authMode === 'hmac' ? 'hmac' : 'bearer',
    'pdv.allow_bearer_fallback' => true,
    'pdv.bearer_token' => 'test-pdv-bearer-token',
    'pdv.hmac_secret' => 'test-pdv-hmac-secret',
    'pdv.timestamp_mode' => 'tolerant',
]);

$syncId = 'ingest-real-' . date('YmdHis');

$brt = new DateTimeZone('-03:00');
$windowFromDt = new DateTimeImmutable('2026-02-10 20:49:44', $brt);
$windowToDt = new DateTimeImmutable('2026-02-10 21:12:56', $brt);
$sentAtDt = new DateTimeImmutable('2026-02-10 21:12:56', $brt);
$turnoStartDt = new DateTimeImmutable('2026-02-10 16:37:05', $brt);
$vendaAtDt = new DateTimeImmutable('2026-02-10 21:07:05', $brt);

$formatDt = static function (DateTimeImmutable $dt, bool $withOffset): string {
    return $withOffset
        ? $dt->format('Y-m-d\TH:i:sP')
        : $dt->format('Y-m-d\TH:i:s');
};

$payload = [
    'schema_version' => '2.0',
    'event_type' => 'sales',
    'agent' => [
        'version' => '2.0.0',
        'machine' => 'DESKTOP-9TD3UO6',
        'sent_at' => $formatDt($sentAtDt, $withOffset),
    ],
    'store' => [
        'id_ponto_venda' => 10,
        'nome' => 'Loja 1 - MC Komprão Centro TJ',
        'alias' => 'tijucas-01',
    ],
    'window' => [
        'from' => $formatDt($windowFromDt, $withOffset),
        'to' => $formatDt($windowToDt, $withOffset),
        'minutes' => 10,
    ],
    'turnos' => [[
        'id_turno' => '2258B1E2-528A-4B68-8172-8F73F7BB7B27',
        'sequencial' => 3,
        'fechado' => false,
        'data_hora_inicio' => $formatDt($turnoStartDt, $withOffset),
        'data_hora_termino' => null,
        'operador' => [
            'id_usuario' => 12,
            'nome' => 'Loja 01 - Komprao Centro/Tijucas',
        ],
        'totais_sistema' => [
            'total' => '1781.60',
            'qtd_vendas' => 8,
            'por_pagamento' => [
                ['id_finalizador' => 12, 'meio' => 'Pix', 'total' => '1096.00', 'qtd_vendas' => 8],
                ['id_finalizador' => 4, 'meio' => 'Cartão de crédito', 'total' => '298.70', 'qtd_vendas' => 2],
                ['id_finalizador' => 5, 'meio' => 'Cartão de débito', 'total' => '191.90', 'qtd_vendas' => 3],
                ['id_finalizador' => 1, 'meio' => 'Dinheiro', 'total' => '170.00', 'qtd_vendas' => 3],
                ['id_finalizador' => 3, 'meio' => 'Devolução', 'total' => '25.00', 'qtd_vendas' => 1],
            ],
        ],
        'fechamento_declarado' => null,
        'falta_caixa' => null,
    ]],
    'vendas' => [[
        'id_operacao' => 13425,
        'data_hora' => $formatDt($vendaAtDt, $withOffset),
        'id_turno' => '2258B1E2-528A-4B68-8172-8F73F7BB7B27',
        'itens' => [[
            'line_id' => 900001,
            'line_no' => 1,
            'id_produto' => 2543,
            'codigo_barras' => '4218',
            'nome' => 'Cabo Type C FAM FCA-EC12',
            'qtd' => '2.000',
            'preco_unit' => '42.010000',
            'total' => '85.00',
            'desconto' => '0.98',
            'vendedor' => ['id_usuario' => 92, 'nome' => 'Vitoria'],
        ]],
        'pagamentos' => [[
            'line_id' => 990001,
            'line_no' => 1,
            'id_finalizador' => 1,
            'meio' => 'Dinheiro',
            'valor' => '85.00',
            'troco' => '0.00',
            'parcelas' => 1,
        ]],
        'total' => '85.00',
    ]],
    'resumo' => [
        'by_vendor' => [[
            'id_usuario' => 92,
            'nome' => 'Vitoria',
            'qtd_cupons' => 1,
            'total_vendido' => '85.00',
        ]],
        'by_payment' => [[
            'id_finalizador' => 1,
            'meio' => 'Dinheiro',
            'total' => '85.00',
        ]],
    ],
    'ops' => ['count' => 1, 'ids' => [13425]],
    'integrity' => ['sync_id' => $syncId, 'warnings' => []],
];

if (!$withLineId) {
    unset($payload['vendas'][0]['itens'][0]['line_id']);
    unset($payload['vendas'][0]['pagamentos'][0]['line_id']);
}

if ($injectInvalidExtra) {
    $payload['unexpected_property'] = 'invalid';
}

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_PDV_SCHEMA_VERSION' => '2.0',
    'HTTP_X_REQUEST_ID' => 'probe-' . bin2hex(random_bytes(6)),
];

if ($authMode === 'hmac') {
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, (string) config('pdv.hmac_secret'));
    $server['HTTP_X_PDV_TIMESTAMP'] = $timestamp;
    $server['HTTP_X_PDV_SIGNATURE'] = 'sha256=' . $signature;
    config([
        'pdv.auth_mode' => 'hmac',
        'pdv.allow_bearer_fallback' => false,
    ]);
} else {
    $server['HTTP_AUTHORIZATION'] = 'Bearer test-pdv-bearer-token';
    config(['pdv.auth_mode' => 'bearer']);
}

$http = $app->make(HttpKernel::class);

$send = static function () use ($http, $body, $server): array {
    $request = Request::create('/api/v1/pdv/sync', 'POST', [], [], [], $server, $body);
    $response = $http->handle($request);
    $content = $response->getContent();
    $status = $response->getStatusCode();
    $http->terminate($request, $response);

    return [$status, $content];
};

[$status1, $content1] = $send();
[$status2, $content2] = $send();

$db = $app->make('db');
$sync = $db->table('pdv_syncs')->where('sync_id', $syncId)->first();
$syncRaw = $db->table('pdv_syncs')->where('sync_id', $syncId)->first(['window_from', 'window_to']);
$saleRaw = $db->table('pdv_vendas')->where('store_pdv_id', 10)->where('id_operacao', 13425)->first(['data_hora']);
$itemRow = $db->table('pdv_venda_itens')->where('store_pdv_id', 10)->where('id_operacao', 13425)->first();
$paymentRow = $db->table('pdv_venda_pagamentos')->where('store_pdv_id', 10)->where('id_operacao', 13425)->first();

$out = [
    'auth_mode_requested' => $authMode,
    'with_line_id' => $withLineId,
    'with_offset' => $withOffset,
    'inject_invalid_extra' => $injectInvalidExtra,
    'sync_id' => $syncId,
    'http_first' => $status1,
    'body_first' => json_decode((string) $content1, true),
    'http_second' => $status2,
    'body_second' => json_decode((string) $content2, true),
    'db' => [
        'pdv_syncs_for_sync_id' => $db->table('pdv_syncs')->where('sync_id', $syncId)->count(),
        'pdv_sync_payloads_for_sync_id' => $sync ? $db->table('pdv_sync_payloads')->where('pdv_sync_id', $sync->id)->count() : 0,
        'pdv_turnos_for_turno' => $db->table('pdv_turnos')->where('store_pdv_id', 10)->where('id_turno', '2258B1E2-528A-4B68-8172-8F73F7BB7B27')->count(),
        'pdv_vendas_for_operacao' => $db->table('pdv_vendas')->where('store_pdv_id', 10)->where('id_operacao', 13425)->count(),
        'pdv_venda_itens_for_operacao' => $db->table('pdv_venda_itens')->where('store_pdv_id', 10)->where('id_operacao', 13425)->count(),
        'pdv_venda_pagamentos_for_operacao' => $db->table('pdv_venda_pagamentos')->where('store_pdv_id', 10)->where('id_operacao', 13425)->count(),
        'pdv_venda_itens_duplicate_row_hash_groups' => $db->table('pdv_venda_itens')
            ->select('row_hash')
            ->where('store_pdv_id', 10)
            ->where('id_operacao', 13425)
            ->groupBy('row_hash')
            ->havingRaw('COUNT(*) > 1')
            ->count(),
        'pdv_venda_pagamentos_duplicate_row_hash_groups' => $db->table('pdv_venda_pagamentos')
            ->select('row_hash')
            ->where('store_pdv_id', 10)
            ->where('id_operacao', 13425)
            ->groupBy('row_hash')
            ->havingRaw('COUNT(*) > 1')
            ->count(),
        'item_line_id' => $itemRow?->line_id,
        'payment_line_id' => $paymentRow?->line_id,
        'item_row_hash' => $itemRow?->row_hash,
        'payment_row_hash' => $paymentRow?->row_hash,
        'window_from_db' => $syncRaw?->window_from,
        'window_to_db' => $syncRaw?->window_to,
        'sale_data_hora_db' => $saleRaw?->data_hora,
        'window_from_expected_utc' => $withOffset ? (new DateTimeImmutable((string) $payload['window']['from']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
        'window_to_expected_utc' => $withOffset ? (new DateTimeImmutable((string) $payload['window']['to']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
        'sale_data_hora_expected_utc' => $withOffset ? (new DateTimeImmutable((string) $payload['vendas'][0]['data_hora']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
        'sync_status' => $sync?->status,
        'risk_flags' => $sync ? json_decode((string) $sync->risk_flags, true) : null,
    ],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
