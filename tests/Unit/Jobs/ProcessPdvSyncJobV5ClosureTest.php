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
        $table->string('closure_uuid', 36)->nullable();
        $table->dateTime('data_hora_fechamento')->nullable();
        $table->string('falta_uuid', 36)->nullable();
        $table->string('sobra_uuid', 36)->nullable();
        $table->decimal('total_sobra', 14, 2)->nullable();
        $table->string('tipo_operacao_fechamento', 30)->nullable();

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

        // V5 Fields
        $table->string('pagamento_uuid', 36)->nullable();
        $table->string('operacao_uuid', 36)->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'], 'pdv_turno_pagamentos_unique_key');
    });
});

function createPdvSyncForV5ClosureTest(array $turnos, string $syncId): PdvSync
{
    $payload = [
        'schema_version' => '4.0', // Keeping 4.0 for now, but payload has V5 fields
        'event_type' => 'turno_closure',
        'store' => ['id_ponto_venda' => 10],
        'window' => ['from' => '2026-02-15T12:00:00-03:00', 'to' => '2026-02-15T12:10:00-03:00'],
        'turnos' => $turnos,
        'integrity' => ['sync_id' => $syncId],
    ];
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

test('persists V5 closure UUID fields on turno', function () {
    $sync = createPdvSyncForV5ClosureTest([
        [
            'id_turno' => 'v5-turno-01',
            'fechado' => true,
            'fechamento_declarado' => [
                'total' => 1000.00,
                'Id' => 'uuid-closure-123',
                'DataHora' => '2026-02-15T18:00:00-03:00',
                'TipoDaOperacao' => 'FechamentoDeCaixa',
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 10.00,
                'Id' => 'uuid-falta-456',
                'por_pagamento' => [],
            ],
            'sobra_caixa' => [
                'total' => 5.00,
                'Id' => 'uuid-sobra-789',
                'por_pagamento' => [],
            ],
        ]
    ], 'sync-v5-01');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $turno = DB::table('pdv_turnos')->where('id_turno', 'v5-turno-01')->first();

    expect($turno->closure_uuid)->toBe('uuid-closure-123');
    expect($turno->data_hora_fechamento)->toBe('2026-02-15 21:00:00');
    expect($turno->falta_uuid)->toBe('uuid-falta-456');
    expect($turno->sobra_uuid)->toBe('uuid-sobra-789');
    expect((float) $turno->total_sobra)->toBe(5.00);
    expect($turno->tipo_operacao_fechamento)->toBe('FechamentoDeCaixa');
});

test('persists pagamento UUID on turno_pagamentos', function () {
    $sync = createPdvSyncForV5ClosureTest([
        [
            'id_turno' => 'v5-turno-02',
            'fechado' => true,
            'fechamento_declarado' => [
                'total' => 100.00,
                'Id' => 'uuid-closure-op',
                'por_pagamento' => [
                    [
                        'id_finalizador' => 1,
                        'meio' => 'Dinheiro',
                        'total' => 100.00,
                        'Id' => 'uuid-pagamento-999',
                    ]
                ],
            ],
        ]
    ], 'sync-v5-02');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $pagamento = DB::table('pdv_turno_pagamentos')
        ->where('id_turno', 'v5-turno-02')
        ->where('tipo', 'declarado')
        ->where('id_finalizador', 1)
        ->first();

    expect($pagamento->pagamento_uuid)->toBe('uuid-pagamento-999');
    expect($pagamento->operacao_uuid)->toBe('uuid-closure-op');
});

test('adds sobra type payments', function () {
    $sync = createPdvSyncForV5ClosureTest([
        [
            'id_turno' => 'v5-turno-03',
            'fechado' => true,
            'sobra_caixa' => [
                'total' => 50.00,
                'Id' => 'uuid-sobra-op',
                'por_pagamento' => [
                    [
                        'id_finalizador' => 1,
                        'meio' => 'Dinheiro',
                        'total' => 50.00,
                        'Id' => 'uuid-pag-sobra',
                    ]
                ],
            ],
        ]
    ], 'sync-v5-03');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $pagamento = DB::table('pdv_turno_pagamentos')
        ->where('id_turno', 'v5-turno-03')
        ->where('tipo', 'sobra')
        ->first();

    expect($pagamento)->not->toBeNull();
    expect((float) $pagamento->total)->toBe(50.00);
    expect($pagamento->pagamento_uuid)->toBe('uuid-pag-sobra');
    expect($pagamento->operacao_uuid)->toBe('uuid-sobra-op');
});

test('V4 payload without closure UUIDs still works', function () {
    $sync = createPdvSyncForV5ClosureTest([
        [
            'id_turno' => 'v4-legacy-01',
            'fechado' => true,
            'fechamento_declarado' => [
                'total' => 200.00,
                // No Id, No Date
            ],
        ]
    ], 'sync-v4-compat');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $turno = DB::table('pdv_turnos')->where('id_turno', 'v4-legacy-01')->first();

    expect($turno->closure_uuid)->toBeNull();
    expect($turno->data_hora_fechamento)->toBeNull();
    expect((float) $turno->total_declarado)->toBe(200.00);
});
