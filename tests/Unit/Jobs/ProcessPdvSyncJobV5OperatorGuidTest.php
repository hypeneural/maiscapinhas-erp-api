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

    // Basic schemas
    Schema::create('pdv_syncs', function (Blueprint $table) {
        $table->id();
        $table->string('sync_id', 128)->unique();
        $table->string('schema_version', 20)->nullable();
        $table->string('event_type', 20)->nullable();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('store_alias', 100)->nullable();
        $table->dateTime('window_from');
        $table->dateTime('window_to');
        $table->string('status', 20)->default('queued');
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
        $table->string('operador_login', 100)->nullable();
        $table->unsignedBigInteger('responsavel_pdv_id')->nullable();
        $table->string('responsavel_nome', 200)->nullable();
        $table->string('responsavel_login', 100)->nullable();
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

        // V5 Fields
        $table->string('operador_guid', 36)->nullable();
        $table->unsignedBigInteger('operador_hiper_id')->nullable();
        $table->string('responsavel_guid', 36)->nullable();
        $table->unsignedBigInteger('responsavel_hiper_id')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_turno'], 'pdv_turnos_store_pdv_id_id_turno_unique');
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

        $table->unique(['store_pdv_id', 'canal', 'id_operacao'], 'pdv_vendas_unique');
    });

    Schema::create('pdv_venda_itens', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->string('line_id', 64)->nullable();
        $table->integer('line_no')->nullable();
        $table->string('row_hash', 64)->nullable();
        $table->unsignedBigInteger('id_produto');
        $table->string('codigo_barras', 128)->nullable();
        $table->string('nome_produto', 200)->nullable();
        $table->decimal('qtd', 14, 3)->default(0);
        $table->decimal('preco_unit', 14, 2)->default(0);
        $table->decimal('total', 14, 2)->default(0);
        $table->decimal('desconto', 14, 2)->default(0);
        $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
        $table->string('vendedor_nome', 200)->nullable();
        $table->string('vendedor_login', 100)->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        // V5 Fields
        $table->string('vendedor_guid', 36)->nullable();
        $table->unsignedBigInteger('vendedor_hiper_id')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'line_id'], 'pdv_venda_itens_unique_line_id');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], 'pdv_venda_itens_unique_row_hash');
    });

    // Also needed for ProcessPdvSyncJob::upsertRows to work without error if it tries to insert payments
    Schema::create('pdv_venda_pagamentos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->string('line_id', 64)->nullable();
        $table->integer('line_no')->nullable();
        $table->string('row_hash', 64)->nullable();
        $table->unsignedBigInteger('id_finalizador');
        $table->string('meio_pagamento', 120)->nullable();
        $table->decimal('valor', 14, 2)->default(0);
        $table->decimal('troco', 14, 2)->default(0);
        $table->integer('parcelas')->default(1);
        $table->dateTime('updated_at')->nullable();
    });
});

function createPdvSyncForV5OperatorTest(array $payloadOverride, string $syncId, string $eventType = 'turno_closure'): PdvSync
{
    $payload = array_merge([
        'schema_version' => '4.0',
        'event_type' => $eventType,
        'store' => ['id_ponto_venda' => 10],
        'window' => ['from' => '2026-02-15T12:00:00-03:00', 'to' => '2026-02-15T12:10:00-03:00'],
        'integrity' => ['sync_id' => $syncId],
    ], $payloadOverride);

    $rawPayload = json_encode($payload);

    $sync = PdvSync::query()->create([
        'sync_id' => $syncId,
        'store_pdv_id' => 10,
        'store_id' => 1,
        'window_from' => '2026-02-15 12:00:00',
        'window_to' => '2026-02-15 12:10:00',
        'payload_sha256' => hash('sha256', $rawPayload),
        'payload_bytes' => strlen($rawPayload),
        'received_at' => now(),
    ]);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $sync->id,
        'payload' => $rawPayload,
    ]);

    return $sync;
}

test('persists operator GUID and hiper_id on turnos', function () {
    $sync = createPdvSyncForV5OperatorTest([
        'turnos' => [
            [
                'id_turno' => 'v5-turno-op-01',
                'fechado' => true,
                'operador' => [
                    'id_usuario' => 10,
                    'nome' => 'Operador 1',
                    'login' => 'op1',
                    'guid' => 'guid-op-1',
                    'id_hiper' => 1001,
                ],
                'responsavel' => [
                    'id_usuario' => 20,
                    'nome' => 'Responsavel 1',
                    'login' => 'resp1',
                    'guid' => 'guid-resp-1',
                    'id_hiper' => 2001,
                ],
            ]
        ]
    ], 'sync-v5-op-01');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $turno = DB::table('pdv_turnos')->where('id_turno', 'v5-turno-op-01')->first();

    expect($turno->operador_guid)->toBe('guid-op-1');
    expect((int) $turno->operador_hiper_id)->toBe(1001);
    expect($turno->responsavel_guid)->toBe('guid-resp-1');
    expect((int) $turno->responsavel_hiper_id)->toBe(2001);
});

test('persists operator GUID and hiper_id on snapshot turnos', function () {
    $sync = createPdvSyncForV5OperatorTest([
        'snapshot_turnos' => [
            [
                'id_turno' => 'v5-snap-turn-01',
                'sequencial' => 1,
                'fechado' => true,
                'operador' => [
                    'id_usuario' => 15,
                    'nome' => 'Operador Snapshot',
                    'guid' => 'guid-snap-op',
                    'id_hiper' => 1005,
                ],
                'responsavel' => [
                    'id_usuario' => 25,
                    'nome' => 'Resp Snapshot',
                    'guid' => 'guid-snap-resp',
                    'id_hiper' => 2005,
                ],
            ]
        ]
    ], 'sync-v5-op-02', 'snapshot');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $turno = DB::table('pdv_turnos')->where('id_turno', 'v5-snap-turn-01')->first();

    expect($turno->operador_guid)->toBe('guid-snap-op');
    expect((int) $turno->operador_hiper_id)->toBe(1005);
    expect($turno->responsavel_guid)->toBe('guid-snap-resp');
    expect((int) $turno->responsavel_hiper_id)->toBe(2005);
});

test('persists vendedor GUID and hiper_id on venda itens', function () {
    $sync = createPdvSyncForV5OperatorTest([
        'vendas' => [
            [
                'id_operacao' => 9901,
                'data_hora' => '2026-02-15T12:05:00-03:00',
                'total' => 50.00,
                'itens' => [
                    [
                        'id_produto' => 77,
                        'line_id' => 101,
                        'vendedor' => [
                            'id_usuario' => 30,
                            'nome' => 'Vendedor 1',
                            'guid' => 'guid-vend-1',
                            'id_hiper' => 3001,
                        ],
                        'qtd' => 1,
                        'preco_unit' => 50.00,
                        'total' => 50.00,
                    ]
                ],
            ]
        ]
    ], 'sync-v5-op-03', 'sales');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $item = DB::table('pdv_venda_itens')->where('line_id', 101)->first();

    expect($item->vendedor_guid)->toBe('guid-vend-1');
    expect((int) $item->vendedor_hiper_id)->toBe(3001);
});

test('V4 payload without GUIDs still works', function () {
    $sync = createPdvSyncForV5OperatorTest([
        'turnos' => [
            [
                'id_turno' => 'v4-turno-legacy',
                'operador' => ['id_usuario' => 10, 'nome' => 'Old Op'],
                'responsavel' => ['id_usuario' => 20, 'nome' => 'Old Resp'],
            ]
        ],
        'vendas' => [
            [
                'id_operacao' => 9999,
                'data_hora' => '2026-02-15T12:05:00-03:00',
                'total' => 10.00,
                'itens' => [
                    [
                        'id_produto' => 88,
                        'line_id' => 102,
                        'vendedor' => ['id_usuario' => 30, 'nome' => 'Old Vend'],
                        'qtd' => 1,
                        'preco_unit' => 10.00,
                        'total' => 10.00,
                    ]
                ]
            ]
        ]
    ], 'sync-v4-op-compat');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $turno = DB::table('pdv_turnos')->where('id_turno', 'v4-turno-legacy')->first();
    expect($turno->operador_guid)->toBeNull();
    expect($turno->responsavel_guid)->toBeNull();

    $item = DB::table('pdv_venda_itens')->where('line_id', 102)->first();
    expect($item->vendedor_guid)->toBeNull();
});
