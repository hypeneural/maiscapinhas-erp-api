<?php

declare(strict_types=1);

use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvSync;
use App\Models\PdvSyncPayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    DB::reconnect('sqlite');

    Schema::create('pdv_syncs', function (Blueprint $table) {
        $table->id();
        $table->string('sync_id', 128)->unique();
        $table->string('schema_version', 20)->nullable();
        $table->string('event_type', 20)->nullable();
        $table->string('request_id', 120)->nullable();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('store_alias', 100)->nullable();
        $table->dateTime('window_from');
        $table->dateTime('window_to');
        $table->string('agent_version', 20)->nullable();
        $table->string('agent_machine', 120)->nullable();
        $table->unsignedInteger('ops_count')->default(0);
        $table->json('warnings')->nullable();
        $table->string('status', 20)->default('queued');
        $table->unsignedInteger('timestamp_skew_seconds')->nullable();
        $table->boolean('timestamp_out_of_window')->default(false);
        $table->json('risk_flags')->nullable();
        $table->char('payload_sha256', 64);
        $table->unsignedInteger('payload_bytes');
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->dateTime('received_at');
        $table->dateTime('queued_at')->nullable();
        $table->dateTime('processing_started_at')->nullable();
        $table->dateTime('processed_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_sync_payloads', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('pdv_sync_id')->unique();
        $table->longText('payload');
        $table->string('compression', 20)->default('none');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_turnos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->string('id_turno', 64);
        $table->unsignedSmallInteger('sequencial')->nullable();
        $table->boolean('fechado')->default(false);
        $table->dateTime('data_hora_inicio')->nullable();
        $table->dateTime('data_hora_termino')->nullable();
        $table->unsignedInteger('duracao_minutos')->nullable();
        $table->string('periodo', 20)->nullable();
        $table->unsignedBigInteger('operador_pdv_id')->nullable();
        $table->string('operador_nome', 200)->nullable();
        $table->unsignedBigInteger('responsavel_pdv_id')->nullable();
        $table->string('responsavel_nome', 200)->nullable();
        $table->decimal('total_sistema', 14, 2)->default(0);
        $table->unsignedInteger('qtd_vendas_sistema')->default(0);
        $table->unsignedInteger('qtd_vendas')->default(0);
        $table->decimal('total_vendas', 14, 2)->default(0);
        $table->unsignedInteger('qtd_vendedores')->default(0);
        $table->decimal('total_declarado', 14, 2)->nullable();
        $table->decimal('total_falta', 14, 2)->nullable();
        $table->string('last_sync_id', 128)->nullable();
        $table->dateTime('last_window_to')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_turno'], 'pdv_turnos_store_pdv_id_id_turno_unique');
    });

    Schema::create('pdv_turno_pagamentos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->string('id_turno', 64);
        $table->string('tipo', 20);
        $table->unsignedBigInteger('id_finalizador')->default(0);
        $table->string('meio_pagamento', 120)->nullable();
        $table->decimal('total', 14, 2)->default(0);
        $table->unsignedInteger('qtd_vendas')->default(0);
        $table->string('last_sync_id', 128)->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'], 'pdv_turno_pagamentos_unique_key');
    });

    Schema::create('pdv_vendas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->string('id_turno', 64)->nullable();
        $table->dateTime('data_hora')->nullable();
        $table->decimal('total', 14, 2)->default(0);
        $table->string('sync_id', 128)->nullable();
        $table->dateTime('last_window_to')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_operacao'], 'pdv_vendas_store_pdv_id_canal_id_operacao_unique');
    });

    Schema::create('pdv_venda_itens', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->unsignedBigInteger('line_id')->nullable();
        $table->unsignedInteger('line_no');
        $table->char('row_hash', 64)->nullable();
        $table->unsignedBigInteger('id_produto')->nullable();
        $table->string('codigo_barras', 80)->nullable();
        $table->string('nome_produto', 300)->nullable();
        $table->decimal('qtd', 14, 3)->default(1);
        $table->decimal('preco_unit', 14, 2)->default(0);
        $table->decimal('total', 14, 2)->default(0);
        $table->decimal('desconto', 14, 2)->default(0);
        $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
        $table->string('vendedor_nome', 200)->nullable();
        $table->string('sync_id', 128)->nullable();
        $table->dateTime('last_window_to')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'line_id'], 'pdv_venda_itens_unique_canal_line_id');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], 'pdv_venda_itens_unique_canal_row_hash');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'line_no'], 'pdv_venda_itens_unique_canal_line');
    });

    Schema::create('pdv_venda_pagamentos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->unsignedBigInteger('line_id')->nullable();
        $table->unsignedInteger('line_no');
        $table->char('row_hash', 64)->nullable();
        $table->unsignedBigInteger('id_finalizador')->default(0);
        $table->string('meio_pagamento', 120)->nullable();
        $table->decimal('valor', 14, 2)->default(0);
        $table->decimal('troco', 14, 2)->default(0);
        $table->unsignedSmallInteger('parcelas')->default(1);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'line_id'], 'pdv_venda_pagamentos_unique_canal_line_id');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], 'pdv_venda_pagamentos_unique_canal_row_hash');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'line_no'], 'pdv_venda_pagamentos_unique_canal_line');
    });

    Schema::create('pdv_vendas_resumo', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->dateTime('data_hora_inicio')->nullable();
        $table->dateTime('data_hora_termino')->nullable();
        $table->unsignedInteger('duracao_segundos')->nullable();
        $table->string('id_turno', 64)->nullable();
        $table->unsignedSmallInteger('turno_seq')->nullable();
        $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
        $table->string('vendedor_nome', 200)->nullable();
        $table->unsignedInteger('qtd_itens')->default(0);
        $table->decimal('total_itens', 14, 2)->default(0);
        $table->string('last_sync_id', 128)->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_operacao'], 'pdv_vendas_resumo_unique_key');
    });
});

/**
 * @return array<string, mixed>
 */
function loadPdvFixture(string $fileName, string $syncId): array
{
    $path = base_path('tests/Fixtures/pdv/v3/' . $fileName);
    if (!is_file($path)) {
        throw new RuntimeException("Fixture file not found: {$path}");
    }

    $raw = file_get_contents($path);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new RuntimeException("Invalid fixture JSON: {$path}");
    }

    $payload['integrity']['sync_id'] = $syncId;

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 */
function createSyncFromFixturePayload(array $payload, string $syncId): PdvSync
{
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode fixture payload.');
    }

    $storePdvId = (int) data_get($payload, 'store.id_ponto_venda', 0);
    $windowFrom = (string) data_get($payload, 'window.from', now()->toIso8601String());
    $windowTo = (string) data_get($payload, 'window.to', now()->toIso8601String());

    $now = now();

    $sync = PdvSync::query()->create([
        'sync_id' => $syncId,
        'schema_version' => (string) data_get($payload, 'schema_version', '3.0'),
        'event_type' => (string) data_get($payload, 'event_type', 'sales'),
        'store_pdv_id' => $storePdvId,
        'store_id' => 1,
        'store_alias' => (string) data_get($payload, 'store.alias', 'loja-test'),
        'window_from' => \Carbon\CarbonImmutable::parse($windowFrom)->utc()->toDateTimeString(),
        'window_to' => \Carbon\CarbonImmutable::parse($windowTo)->utc()->toDateTimeString(),
        'agent_version' => (string) data_get($payload, 'agent.version', '3.0.0'),
        'agent_machine' => (string) data_get($payload, 'agent.machine', 'PDV-TEST'),
        'ops_count' => (int) data_get($payload, 'ops.count', 0),
        'warnings' => [],
        'status' => PdvSync::STATUS_QUEUED,
        'timestamp_out_of_window' => false,
        'risk_flags' => [],
        'payload_sha256' => hash('sha256', $rawPayload),
        'payload_bytes' => strlen($rawPayload),
        'attempts' => 0,
        'received_at' => $now,
        'queued_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $sync->id,
        'payload' => $rawPayload,
        'compression' => 'none',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $sync;
}

test('processes mixed fixture with channel collision and responsavel null', function () {
    $payload = loadPdvFixture('mixed_caixa_loja_collision.json', 'sync-pr40-mixed-fixture-001');
    $sync = createSyncFromFixturePayload($payload, 'sync-pr40-mixed-fixture-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 13)
        ->where('id_operacao', 55001)
        ->count())->toBe(2);
    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 13)
        ->where('id_operacao', 55001)
        ->where('canal', 'HIPER_CAIXA')
        ->exists())->toBeTrue();
    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 13)
        ->where('id_operacao', 55001)
        ->where('canal', 'HIPER_LOJA')
        ->exists())->toBeTrue();

    $turno = DB::table('pdv_turnos')
        ->where('store_pdv_id', 13)
        ->where('id_turno', 'A1B2C3D4-E5F6-4701-8A90-000000000014')
        ->first(['responsavel_pdv_id', 'responsavel_nome', 'total_vendas']);

    expect($turno)->not->toBeNull();
    expect($turno->responsavel_pdv_id)->toBeNull();
    expect($turno->responsavel_nome)->toBeNull();
    expect((float) $turno->total_vendas)->toBe(420.0);
});

test('snapshot replay fixture updates persisted turno and venda resumo', function () {
    $payloadA = loadPdvFixture('snapshot_replay_a.json', 'sync-pr40-replay-a');
    $syncA = createSyncFromFixturePayload($payloadA, 'sync-pr40-replay-a');
    (new ProcessPdvSyncJob($syncA->id))->handle();

    $payloadB = loadPdvFixture('snapshot_replay_b.json', 'sync-pr40-replay-b');
    $syncB = createSyncFromFixturePayload($payloadB, 'sync-pr40-replay-b');
    (new ProcessPdvSyncJob($syncB->id))->handle();

    $turno = DB::table('pdv_turnos')
        ->where('store_pdv_id', 15)
        ->where('id_turno', 'C1D2E3F4-A5B6-4701-8A90-000000000015')
        ->first(['responsavel_pdv_id', 'responsavel_nome', 'qtd_vendas', 'total_vendas']);

    expect($turno)->not->toBeNull();
    expect((int) $turno->responsavel_pdv_id)->toBe(67);
    expect($turno->responsavel_nome)->toBe('Vendedor 67');
    expect((int) $turno->qtd_vendas)->toBe(21);
    expect((float) $turno->total_vendas)->toBe(5120.5);

    $resumo = DB::table('pdv_vendas_resumo')
        ->where('store_pdv_id', 15)
        ->where('canal', 'HIPER_CAIXA')
        ->where('id_operacao', 65001)
        ->first(['vendedor_pdv_id', 'vendedor_nome', 'total_itens', 'last_sync_id']);

    expect($resumo)->not->toBeNull();
    expect((int) $resumo->vendedor_pdv_id)->toBe(67);
    expect($resumo->vendedor_nome)->toBe('Vendedor 67');
    expect((float) $resumo->total_itens)->toBe(120.0);
    expect($resumo->last_sync_id)->toBe('sync-pr40-replay-b');
});

test('keeps child rows isolated when line_id collides across canais', function () {
    $payload = [
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-STORE-13',
            'sent_at' => '2026-02-12T10:00:00-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 13,
            'nome' => 'Loja 13',
            'alias' => 'loja-13',
        ],
        'window' => [
            'from' => '2026-02-12T09:50:00-03:00',
            'to' => '2026-02-12T10:00:00-03:00',
            'minutes' => 10,
        ],
        'turnos' => [],
        'vendas' => [
            [
                'id_operacao' => 77701,
                'canal' => 'HIPER_CAIXA',
                'data_hora' => '2026-02-12T09:55:00-03:00',
                'total' => 100.00,
                'itens' => [
                    [
                        'line_id' => 50000,
                        'line_no' => 1,
                        'id_produto' => 1,
                        'nome' => 'Item Caixa',
                        'qtd' => 1,
                        'preco_unit' => 100,
                        'total' => 100,
                        'desconto' => 0,
                    ]
                ],
                'pagamentos' => [
                    [
                        'line_id' => 70000,
                        'line_no' => 1,
                        'id_finalizador' => 5,
                        'meio' => 'Pix',
                        'valor' => 100,
                        'troco' => 0,
                        'parcelas' => 1,
                    ]
                ],
            ],
            [
                'id_operacao' => 77701,
                'canal' => 'HIPER_LOJA',
                'data_hora' => '2026-02-12T09:56:00-03:00',
                'total' => 200.00,
                'itens' => [
                    [
                        'line_id' => 50000,
                        'line_no' => 1,
                        'id_produto' => 2,
                        'nome' => 'Item Loja',
                        'qtd' => 1,
                        'preco_unit' => 200,
                        'total' => 200,
                        'desconto' => 0,
                    ]
                ],
                'pagamentos' => [
                    [
                        'line_id' => 70000,
                        'line_no' => 1,
                        'id_finalizador' => 4,
                        'meio' => 'Credito',
                        'valor' => 200,
                        'troco' => 0,
                        'parcelas' => 1,
                    ]
                ],
            ],
        ],
        'resumo' => [
            'by_vendor' => [],
            'by_payment' => [],
        ],
        'snapshot_turnos' => [],
        'snapshot_vendas' => [],
        'ops' => [
            'count' => 1,
            'ids' => [77701],
            'loja_count' => 1,
            'loja_ids' => [77701],
        ],
        'integrity' => [
            'sync_id' => 'sync-pr41-child-collision-001',
            'warnings' => [],
        ],
    ];

    $sync = createSyncFromFixturePayload($payload, 'sync-pr41-child-collision-001');
    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_venda_itens')
        ->where('store_pdv_id', 13)
        ->where('line_id', 50000)
        ->count())->toBe(2);
    expect(DB::table('pdv_venda_itens')
        ->where('store_pdv_id', 13)
        ->where('line_id', 50000)
        ->where('canal', 'HIPER_CAIXA')
        ->exists())->toBeTrue();
    expect(DB::table('pdv_venda_itens')
        ->where('store_pdv_id', 13)
        ->where('line_id', 50000)
        ->where('canal', 'HIPER_LOJA')
        ->exists())->toBeTrue();

    expect(DB::table('pdv_venda_pagamentos')
        ->where('store_pdv_id', 13)
        ->where('line_id', 70000)
        ->count())->toBe(2);
    expect(DB::table('pdv_venda_pagamentos')
        ->where('store_pdv_id', 13)
        ->where('line_id', 70000)
        ->where('canal', 'HIPER_CAIXA')
        ->exists())->toBeTrue();
    expect(DB::table('pdv_venda_pagamentos')
        ->where('store_pdv_id', 13)
        ->where('line_id', 70000)
        ->where('canal', 'HIPER_LOJA')
        ->exists())->toBeTrue();
});
