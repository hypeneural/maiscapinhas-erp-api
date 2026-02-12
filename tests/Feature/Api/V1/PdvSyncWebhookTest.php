<?php

declare(strict_types=1);

use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvSync;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    config()->set('pdv.auth_mode', 'hmac');
    config()->set('pdv.hmac_secret', 'test-pdv-secret');
    config()->set('pdv.allow_bearer_fallback', false);
    config()->set('pdv.bearer_token', 'legacy-bearer-token');
    config()->set('pdv.timestamp_mode', 'tolerant');
    config()->set('pdv.timestamp_tolerance_seconds', 600);
    config()->set('pdv.naive_datetime_timezone', 'America/Sao_Paulo');
    config()->set('pdv.queue_name', 'pdv');
    config()->set('pdv.supported_schema_versions', ['2.0', '3.0']);
    config()->set('pdv.json_schema_files', [
        '2.0' => base_path('docs/schema_v2.0.json'),
        '3.0' => base_path('docs/schema_v3.0.json'),
    ]);
    Queue::fake();
});

function pdvPayload(array $overrides = []): array
{
    $base = [
        'schema_version' => '2.0',
        'event_type' => 'sales',
        'agent' => [
            'version' => '2.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => now()->toDateTimeString(),
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => now()->subMinutes(10)->toDateTimeString(),
            'to' => now()->toDateTimeString(),
            'minutes' => 10,
        ],
        'ops' => [
            'count' => 2,
            'ids' => [1001, 1002],
        ],
        'integrity' => [
            'sync_id' => 'sync-10-20260210-163000',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function pdvPayloadV3(array $overrides = []): array
{
    $base = [
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => now()->toIso8601String(),
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => now()->subMinutes(10)->toIso8601String(),
            'to' => now()->toIso8601String(),
            'minutes' => 10,
        ],
        'turnos' => [[
            'id_turno' => 'turno-v3-main-001',
            'sequencial' => 1,
            'fechado' => true,
            'data_hora_inicio' => now()->subHours(8)->toIso8601String(),
            'data_hora_termino' => now()->subMinutes(5)->toIso8601String(),
            'duracao_minutos' => 475,
            'periodo' => 'MATUTINO',
            'operador' => [
                'id_usuario' => 12,
                'nome' => 'Operador V3',
            ],
            'responsavel' => [
                'id_usuario' => 80,
                'nome' => 'Vendedor Lider',
            ],
            'qtd_vendas' => 5,
            'total_vendas' => 529.90,
            'qtd_vendedores' => 2,
            'totais_sistema' => [
                'total' => 529.90,
                'qtd_vendas' => 5,
                'por_pagamento' => [],
            ],
            'fechamento_declarado' => [
                'total' => 529.90,
                'qtd_vendas' => 5,
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 0.00,
                'qtd_vendas' => 0,
                'por_pagamento' => [],
            ],
        ]],
        'vendas' => [[
            'id_operacao' => 12380,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => now()->subMinutes(6)->toIso8601String(),
            'id_turno' => 'turno-v3-main-001',
            'itens' => [[
                'line_id' => 987001,
                'line_no' => 1,
                'id_produto' => 5402,
                'codigo_barras' => '7891234567890',
                'nome' => 'Produto V3',
                'qtd' => 1,
                'preco_unit' => 129.00,
                'total' => 129.00,
                'desconto' => 0,
                'vendedor' => [
                    'id_usuario' => 80,
                    'nome' => 'Vendedor Lider',
                ],
            ]],
            'pagamentos' => [[
                'line_id' => 987101,
                'line_no' => 1,
                'id_finalizador' => 5,
                'meio' => 'Pix',
                'valor' => 129.00,
                'troco' => 0,
                'parcelas' => 1,
            ]],
            'total' => 129.00,
        ]],
        'resumo' => [
            'by_vendor' => [[
                'id_usuario' => 80,
                'nome' => 'Vendedor Lider',
                'qtd_cupons' => 1,
                'total_vendido' => 129.00,
            ]],
            'by_payment' => [[
                'id_finalizador' => 5,
                'meio' => 'Pix',
                'total' => 129.00,
            ]],
        ],
        'snapshot_turnos' => [[
            'id_turno' => 'turno-v3-snap-001',
            'sequencial' => 1,
            'fechado' => true,
            'data_hora_inicio' => now()->subDay()->setTime(8, 0)->toIso8601String(),
            'data_hora_termino' => now()->subDay()->setTime(14, 30)->toIso8601String(),
            'duracao_minutos' => 390,
            'periodo' => 'MATUTINO',
            'operador' => [
                'id_usuario' => 12,
                'nome' => 'Operador V3',
            ],
            'responsavel' => [
                'id_usuario' => 80,
                'nome' => 'Vendedor Lider',
            ],
            'qtd_vendas' => 45,
            'total_vendas' => 12500.00,
            'qtd_vendedores' => 3,
        ]],
        'snapshot_vendas' => [[
            'id_operacao' => 12379,
            'canal' => 'HIPER_CAIXA',
            'data_hora_inicio' => now()->subDay()->setTime(16, 26, 44)->toIso8601String(),
            'data_hora_termino' => now()->subDay()->setTime(16, 26, 59)->toIso8601String(),
            'duracao_segundos' => 15,
            'id_turno' => 'turno-v3-snap-001',
            'turno_seq' => 1,
            'vendedor' => [
                'id_usuario' => 80,
                'nome' => 'Vendedor Lider',
            ],
            'qtd_itens' => 3,
            'total_itens' => 129.00,
        ]],
        'ops' => [
            'count' => 1,
            'ids' => [12380],
            'loja_count' => 1,
            'loja_ids' => [22380],
        ],
        'integrity' => [
            'sync_id' => 'sync-v3-20260211-001',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function pdvFixtureV3(string $fileName, array $overrides = []): array
{
    $path = base_path('tests/Fixtures/pdv/v3/' . $fileName);
    if (!is_file($path)) {
        throw new \RuntimeException("Fixture not found: {$path}");
    }

    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new \RuntimeException("Invalid JSON fixture: {$path}");
    }

    return array_replace_recursive($decoded, $overrides);
}

function signedPdvRequest(array $payload, ?int $timestamp = null, ?string $secret = null, array $extraHeaders = [])
{
    $timestamp ??= now()->timestamp;
    $secret ??= (string) config('pdv.hmac_secret');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return test()->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PDV_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PDV_SIGNATURE' => $signature,
        ], $extraHeaders),
        $body
    );
}

function bearerPdvRequest(array $payload, string $token = 'legacy-bearer-token')
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return test()->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ],
        $body
    );
}

test('rejects webhook without signature headers', function () {
    $payload = pdvPayload();
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $response = $this->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(401);
});

test('rejects webhook with invalid signature', function () {
    $payload = pdvPayload();
    $timestamp = now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $response = $this->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PDV_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PDV_SIGNATURE' => 'invalid-signature',
        ],
        $body
    );

    $response->assertStatus(403);
});

test('accepts valid webhook and queues processing', function () {
    $payload = pdvPayload();

    $response = signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '2.0',
        'HTTP_X_REQUEST_ID' => 'req-pdv-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.schema_version', '2.0')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJsonPath('data.request_id', 'req-pdv-001')
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.sync_id', 'sync-10-20260210-163000');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-10-20260210-163000',
        'schema_version' => '2.0',
        'event_type' => 'sales',
        'request_id' => 'req-pdv-001',
        'status' => 'queued',
        'store_pdv_id' => 10,
        'ops_count' => 2,
    ]);
    assertDatabaseCount('pdv_sync_payloads', 1);

    Queue::assertPushed(ProcessPdvSyncJob::class, function (ProcessPdvSyncJob $job) {
        return $job->queue === 'pdv';
    });
});

test('returns 200 duplicate for same sync id', function () {
    $payload = pdvPayload();

    signedPdvRequest($payload)->assertStatus(201);
    signedPdvRequest($payload)->assertStatus(200)
        ->assertJsonPath('data.status', 'duplicate')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJsonPath('data.duplicate', true);

    assertDatabaseCount('pdv_syncs', 1);
    assertDatabaseCount('pdv_sync_payloads', 1);
});

test('rejects when schema header does not match payload schema_version', function () {
    $payload = pdvPayload([
        'schema_version' => '2.0',
        'integrity' => [
            'sync_id' => 'sync-schema-mismatch-001',
        ],
    ]);

    signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '1.0',
    ])->assertStatus(422);

    assertDatabaseCount('pdv_syncs', 0);
});

test('accepts valid v3 webhook and queues processing', function () {
    $payload = pdvPayloadV3();

    $response = signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
        'HTTP_X_REQUEST_ID' => 'req-pdv-v3-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.schema_version', '3.0')
        ->assertJsonPath('data.event_type', 'mixed')
        ->assertJsonPath('data.request_id', 'req-pdv-v3-001')
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.sync_id', 'sync-v3-20260211-001');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-v3-20260211-001',
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'request_id' => 'req-pdv-v3-001',
        'status' => 'queued',
        'store_pdv_id' => 10,
        'ops_count' => 1,
        'ops_loja_count' => 1,
        'snapshot_turnos_count' => 1,
        'snapshot_vendas_count' => 1,
    ]);
});

test('accepts anonymized mixed fixture with id_operacao collision across canais', function () {
    $payload = pdvFixtureV3('mixed_caixa_loja_collision.json', [
        'integrity' => [
            'sync_id' => 'sync-v3-fixture-mixed-001',
            'warnings' => [],
        ],
        'agent' => [
            'sent_at' => now()->toIso8601String(),
        ],
    ]);

    signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
    ])->assertStatus(201)
        ->assertJsonPath('data.schema_version', '3.0')
        ->assertJsonPath('data.event_type', 'mixed')
        ->assertJsonPath('data.sync_id', 'sync-v3-fixture-mixed-001');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-v3-fixture-mixed-001',
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'ops_count' => 1,
        'ops_loja_count' => 1,
        'snapshot_turnos_count' => 1,
        'snapshot_vendas_count' => 2,
    ]);
});

test('accepts anonymized turno_closure fixture with empty vendas', function () {
    $payload = pdvFixtureV3('turno_closure.json', [
        'integrity' => [
            'sync_id' => 'sync-v3-fixture-turno-closure-001',
            'warnings' => [],
        ],
        'agent' => [
            'sent_at' => now()->toIso8601String(),
        ],
    ]);

    signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
    ])->assertStatus(201)
        ->assertJsonPath('data.schema_version', '3.0')
        ->assertJsonPath('data.event_type', 'turno_closure')
        ->assertJsonPath('data.sync_id', 'sync-v3-fixture-turno-closure-001');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-v3-fixture-turno-closure-001',
        'schema_version' => '3.0',
        'event_type' => 'turno_closure',
        'ops_count' => 0,
        'ops_loja_count' => 0,
        'snapshot_turnos_count' => 1,
        'snapshot_vendas_count' => 1,
    ]);
});

test('accepts v3 payload with json schema validation enabled', function () {
    config()->set('pdv.json_schema_validation_enabled', true);

    $payload = pdvPayloadV3([
        'integrity' => [
            'sync_id' => 'sync-v3-schema-001',
            'warnings' => [],
        ],
    ]);

    signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
    ])->assertStatus(201)
        ->assertJsonPath('data.schema_version', '3.0');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-v3-schema-001',
        'schema_version' => '3.0',
    ]);
});

test('returns validation error when json schema validation is enabled and payload violates schema', function () {
    config()->set('pdv.json_schema_validation_enabled', true);
    config()->set('pdv.json_schema_files', [
        '2.0' => base_path('docs/schema_v2.0.json'),
    ]);

    $payload = pdvPayload([
        'agent' => [
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
        ],
        'integrity' => [
            'sync_id' => 'sync-schema-runtime-invalid-001',
        ],
    ]);
    $payload['unexpected_property'] = 'x';

    signedPdvRequest($payload)
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation')
        ->assertJsonPath('message', 'Payload does not match JSON schema.');

    assertDatabaseCount('pdv_syncs', 0);
});

test('returns 503 when json schema validator is enabled but schema file is missing', function () {
    config()->set('pdv.json_schema_validation_enabled', true);
    config()->set('pdv.json_schema_files', [
        '2.0' => base_path('docs/schema_v2.0.missing.json'),
    ]);

    $payload = pdvPayload([
        'agent' => [
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
        ],
        'integrity' => [
            'sync_id' => 'sync-schema-runtime-missing-001',
        ],
    ]);

    signedPdvRequest($payload)
        ->assertStatus(503)
        ->assertJsonPath('message', 'Webhook schema validator unavailable.');

    assertDatabaseCount('pdv_syncs', 0);
});

test('strict mode rejects new stale sync', function () {
    config()->set('pdv.timestamp_mode', 'strict');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-new-stale-001',
        ],
    ]);
    $oldTimestamp = now()->subMinutes(30)->timestamp;

    signedPdvRequest($payload, $oldTimestamp)->assertStatus(422);

    assertDatabaseCount('pdv_syncs', 0);
});

test('tolerant mode accepts stale new sync and flags timestamp risk', function () {
    config()->set('pdv.timestamp_mode', 'tolerant');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-new-stale-tolerant-001',
        ],
    ]);
    $oldTimestamp = now()->subMinutes(30)->timestamp;

    signedPdvRequest($payload, $oldTimestamp)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.timestamp_out_of_window', true);

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-new-stale-tolerant-001',
        'status' => 'queued',
        'timestamp_out_of_window' => 1,
    ]);
});

test('strict mode still returns duplicate 200 even if timestamp is stale', function () {
    config()->set('pdv.timestamp_mode', 'strict');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-dup-stale-001',
        ],
    ]);

    signedPdvRequest($payload, now()->timestamp)->assertStatus(201);
    signedPdvRequest($payload, now()->subMinutes(30)->timestamp)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'duplicate');

    assertDatabaseCount('pdv_syncs', 1);
});

test('accepts bearer fallback when enabled without hmac headers', function () {
    config()->set('pdv.auth_mode', 'auto');
    config()->set('pdv.allow_bearer_fallback', true);
    config()->set('pdv.timestamp_mode', 'strict');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-fallback-001',
        ],
    ]);

    bearerPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.auth_mode', 'bearer_fallback')
        ->assertJsonPath('data.timestamp_out_of_window', false);

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-bearer-fallback-001',
        'status' => 'queued',
    ]);
});

test('accepts bearer token when auth mode is bearer', function () {
    config()->set('pdv.auth_mode', 'bearer');
    config()->set('pdv.timestamp_mode', 'strict');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-mode-001',
        ],
    ]);

    bearerPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.auth_mode', 'bearer');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-bearer-mode-001',
        'status' => 'queued',
    ]);
});

test('accepts turno_closure event with empty vendas and zero ops', function () {
    $payload = pdvPayload([
        'event_type' => 'turno_closure',
        'turnos' => [[
            'id_turno' => 'turno-closure-test-001',
            'fechado' => true,
            'data_hora_inicio' => now()->subHours(6)->toDateTimeString(),
            'data_hora_termino' => now()->toDateTimeString(),
            'totais_sistema' => [
                'total' => 100.00,
                'qtd_vendas' => 2,
                'por_pagamento' => [],
            ],
            'fechamento_declarado' => [
                'total' => 95.00,
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 5.00,
                'por_pagamento' => [],
            ],
        ]],
        'vendas' => [],
        'ops' => [
            'count' => 0,
            'ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-turno-closure-001',
        ],
    ]);

    signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.event_type', 'turno_closure')
        ->assertJsonPath('data.processing_status', 'queued');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-turno-closure-001',
        'event_type' => 'turno_closure',
        'ops_count' => 0,
        'status' => 'queued',
    ]);
});

test('flags inconsistency when turno_closure event contains vendas', function () {
    $payload = pdvPayloadV3([
        'event_type' => 'turno_closure',
        'turnos' => [[
            'id_turno' => 'turno-closure-inconsistent-001',
            'fechado' => true,
            'data_hora_inicio' => now()->subHours(6)->toIso8601String(),
            'data_hora_termino' => now()->toIso8601String(),
            'totais_sistema' => [
                'total' => 100.00,
                'qtd_vendas' => 1,
                'por_pagamento' => [],
            ],
            'fechamento_declarado' => [
                'total' => 100.00,
                'qtd_vendas' => 1,
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 0.00,
                'qtd_vendas' => 0,
                'por_pagamento' => [],
            ],
        ]],
        'vendas' => [[
            'id_operacao' => 99881,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => now()->subMinutes(5)->toIso8601String(),
            'total' => 100.00,
            'itens' => [],
            'pagamentos' => [],
        ]],
        'ops' => [
            'count' => 1,
            'ids' => [99881],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-turno-closure-inconsistent-001',
            'warnings' => [],
        ],
    ]);

    $response = signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.event_type', 'turno_closure');

    expect($response->json('data.risk_flags'))->toContain('event_type_turno_closure_with_vendas');

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-turno-closure-inconsistent-001')
        ->value('risk_flags');

    expect(is_array($riskFlags) ? $riskFlags : [])->toContain('event_type_turno_closure_with_vendas');
});

test('flags inconsistencies when mixed event has no vendas and no closed turno', function () {
    $payload = pdvPayloadV3([
        'event_type' => 'mixed',
        'turnos' => [[
            'id_turno' => 'turno-mixed-inconsistent-001',
            'fechado' => false,
            'data_hora_inicio' => now()->subHours(6)->toIso8601String(),
            'data_hora_termino' => null,
            'totais_sistema' => [
                'total' => 0.00,
                'qtd_vendas' => 0,
                'por_pagamento' => [],
            ],
            'fechamento_declarado' => [
                'total' => 0.00,
                'qtd_vendas' => 0,
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 0.00,
                'qtd_vendas' => 0,
                'por_pagamento' => [],
            ],
        ]],
        'vendas' => [],
        'ops' => [
            'count' => 0,
            'ids' => [],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-mixed-inconsistent-001',
            'warnings' => [],
        ],
    ]);

    $response = signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.event_type', 'mixed');

    $riskFlagsResponse = $response->json('data.risk_flags');
    expect($riskFlagsResponse)->toContain('event_type_mixed_without_vendas');
    expect($riskFlagsResponse)->toContain('event_type_mixed_without_closed_turno');

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-mixed-inconsistent-001')
        ->value('risk_flags');
    $riskFlags = is_array($riskFlags) ? $riskFlags : [];

    expect($riskFlags)->toContain('event_type_mixed_without_vendas');
    expect($riskFlags)->toContain('event_type_mixed_without_closed_turno');
});

test('unknown event_type falls back to sales and sets risk flag', function () {
    $payload = pdvPayload([
        'event_type' => 'unexpected_mode',
        'integrity' => [
            'sync_id' => 'sync-event-type-fallback-001',
        ],
    ]);

    $response = signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJson(fn ($json) => $json
            ->where('data.status', 'created')
            ->where('data.event_type', 'sales')
            ->has('data.risk_flags')
            ->etc()
        );

    expect($response->json('data.risk_flags'))->toContain('event_type_unknown');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-event-type-fallback-001',
        'event_type' => 'sales',
        'status' => 'queued',
    ]);

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-event-type-fallback-001')
        ->value('risk_flags');

    expect(is_array($riskFlags) ? $riskFlags : [])->toContain('event_type_unknown');
});

test('sets gestao_db_failure risk flag when integrity warning reports gestao database failure', function () {
    $payload = pdvPayloadV3([
        'integrity' => [
            'sync_id' => 'sync-gestao-db-failure-001',
            'warnings' => [
                'GESTAO_DB_FAILURE: [Errno 10061] Connection refused',
            ],
        ],
    ]);

    $response = signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'created');

    expect($response->json('data.risk_flags'))->toContain('gestao_db_failure');

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-gestao-db-failure-001')
        ->value('risk_flags');
    $riskFlags = is_array($riskFlags) ? $riskFlags : [];

    expect($riskFlags)->toContain('gestao_db_failure');
});

test('sets warning risk flags for vendedor null and meio de pagamento null warnings', function () {
    $payload = pdvPayloadV3([
        'integrity' => [
            'sync_id' => 'sync-warning-risk-flags-001',
            'warnings' => [
                'Vendedor NULL encontrado em 2 cupom(s)',
                'Meio de pagamento NULL encontrado',
            ],
        ],
    ]);

    $response = signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '3.0',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'created');

    expect($response->json('data.risk_flags'))->toContain('vendedor_null');
    expect($response->json('data.risk_flags'))->toContain('meio_pagamento_null');

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-warning-risk-flags-001')
        ->value('risk_flags');
    $riskFlags = is_array($riskFlags) ? $riskFlags : [];

    expect($riskFlags)->toContain('vendedor_null');
    expect($riskFlags)->toContain('meio_pagamento_null');
});

test('rejects bearer token when auth mode is bearer and token is invalid', function () {
    config()->set('pdv.auth_mode', 'bearer');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-mode-invalid-001',
        ],
    ]);

    bearerPdvRequest($payload, 'invalid-token')->assertStatus(403);
});
