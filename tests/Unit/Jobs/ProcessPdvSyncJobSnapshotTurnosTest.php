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
});

/**
 * @param array<int, array<string, mixed>> $turnos
 * @param array<int, mixed> $snapshotTurnos
 * @return array<string, mixed>
 */
function buildPayloadForSnapshotTurnosTest(array $turnos, array $snapshotTurnos, string $syncId): array
{
    return [
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => '2026-02-11T13:10:00-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => '2026-02-11T13:00:00-03:00',
            'to' => '2026-02-11T13:10:00-03:00',
            'minutes' => 10,
        ],
        'turnos' => $turnos,
        'snapshot_turnos' => $snapshotTurnos,
        'vendas' => [],
        'ops' => [
            'count' => 0,
            'ids' => [],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => $syncId,
            'warnings' => [],
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $turnos
 * @param array<int, mixed> $snapshotTurnos
 */
function createSyncForSnapshotTurnosTest(array $turnos, array $snapshotTurnos, string $syncId): PdvSync
{
    $payload = buildPayloadForSnapshotTurnosTest($turnos, $snapshotTurnos, $syncId);
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode snapshot_turnos payload for test.');
    }

    $now = now();

    $sync = PdvSync::query()->create([
        'sync_id' => $syncId,
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'store_pdv_id' => 10,
        'store_id' => 1,
        'store_alias' => 'loja-10',
        'window_from' => '2026-02-11 16:00:00',
        'window_to' => '2026-02-11 16:10:00',
        'agent_version' => '3.0.0',
        'agent_machine' => 'PDV-STORE-01',
        'ops_count' => 0,
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

test('snapshot_turnos updates existing turno with latest values', function () {
    $sync = createSyncForSnapshotTurnosTest(
        [
            [
                'id_turno' => 'turno-snapshot-001',
                'sequencial' => 2,
                'fechado' => true,
                'duracao_minutos' => 320,
                'periodo' => 'MATUTINO',
                'operador' => ['id_usuario' => 12, 'nome' => 'Carlos'],
                'responsavel' => ['id_usuario' => 70, 'nome' => 'Leo'],
                'qtd_vendas' => 20,
                'total_vendas' => 4500.00,
                'qtd_vendedores' => 2,
                'totais_sistema' => ['total' => 4500.00, 'qtd_vendas' => 20, 'por_pagamento' => []],
                'fechamento_declarado' => ['total' => 4500.00, 'qtd_vendas' => 20, 'por_pagamento' => []],
                'falta_caixa' => ['total' => 0.00, 'qtd_vendas' => 0, 'por_pagamento' => []],
            ],
        ],
        [
            [
                'id_turno' => 'turno-snapshot-001',
                'sequencial' => 2,
                'fechado' => true,
                'duracao_minutos' => 390,
                'periodo' => 'MATUTINO',
                'operador' => ['id_usuario' => 12, 'nome' => 'Carlos'],
                'responsavel' => ['id_usuario' => 80, 'nome' => 'Daren'],
                'qtd_vendas' => 45,
                'total_vendas' => 12500.00,
                'qtd_vendedores' => 3,
            ],
        ],
        'sync-pr34-snapshot-update-001'
    );

    (new ProcessPdvSyncJob($sync->id))->handle();

    $row = DB::table('pdv_turnos')
        ->where('store_pdv_id', 10)
        ->where('id_turno', 'turno-snapshot-001')
        ->first(['duracao_minutos', 'responsavel_pdv_id', 'responsavel_nome', 'qtd_vendas', 'total_vendas', 'qtd_vendedores']);

    expect(DB::table('pdv_turnos')->count())->toBe(1);
    expect($row)->not->toBeNull();
    expect((int) $row->duracao_minutos)->toBe(390);
    expect((int) $row->responsavel_pdv_id)->toBe(80);
    expect($row->responsavel_nome)->toBe('Daren');
    expect((int) $row->qtd_vendas)->toBe(45);
    expect((float) $row->total_vendas)->toBe(12500.0);
    expect((int) $row->qtd_vendedores)->toBe(3);
});

test('snapshot_turnos creates missing turno when main turnos is empty', function () {
    $sync = createSyncForSnapshotTurnosTest(
        [],
        [
            [
                'id_turno' => 'turno-snapshot-create-001',
                'sequencial' => 5,
                'fechado' => true,
                'duracao_minutos' => 310,
                'periodo' => 'VESPERTINO',
                'operador' => ['id_usuario' => 20, 'nome' => 'Ana'],
                'responsavel' => null,
                'qtd_vendas' => 14,
                'total_vendas' => 3900.00,
                'qtd_vendedores' => 2,
            ],
        ],
        'sync-pr34-snapshot-create-001'
    );

    (new ProcessPdvSyncJob($sync->id))->handle();

    $row = DB::table('pdv_turnos')
        ->where('store_pdv_id', 10)
        ->where('id_turno', 'turno-snapshot-create-001')
        ->first(['sequencial', 'periodo', 'responsavel_pdv_id', 'qtd_vendas']);

    expect(DB::table('pdv_turnos')->count())->toBe(1);
    expect($row)->not->toBeNull();
    expect((int) $row->sequencial)->toBe(5);
    expect($row->periodo)->toBe('VESPERTINO');
    expect($row->responsavel_pdv_id)->toBeNull();
    expect((int) $row->qtd_vendas)->toBe(14);
});

test('snapshot_turnos replay with different data corrects existing turno', function () {
    $firstSync = createSyncForSnapshotTurnosTest(
        [],
        [
            [
                'id_turno' => 'turno-snapshot-replay-001',
                'sequencial' => 7,
                'fechado' => true,
                'duracao_minutos' => 280,
                'periodo' => 'NOTURNO',
                'operador' => ['id_usuario' => 33, 'nome' => 'Rafa'],
                'responsavel' => ['id_usuario' => 45, 'nome' => 'Bia'],
                'qtd_vendas' => 9,
                'total_vendas' => 2100.00,
                'qtd_vendedores' => 2,
            ]
        ],
        'sync-pr34-snapshot-replay-001-a'
    );
    (new ProcessPdvSyncJob($firstSync->id))->handle();

    $secondSync = createSyncForSnapshotTurnosTest(
        [],
        [
            [
                'id_turno' => 'turno-snapshot-replay-001',
                'sequencial' => 7,
                'fechado' => true,
                'duracao_minutos' => 295,
                'periodo' => 'NOTURNO',
                'operador' => ['id_usuario' => 33, 'nome' => 'Rafa'],
                'responsavel' => ['id_usuario' => 46, 'nome' => 'Luca'],
                'qtd_vendas' => 11,
                'total_vendas' => 2590.50,
                'qtd_vendedores' => 3,
            ]
        ],
        'sync-pr34-snapshot-replay-001-b'
    );
    (new ProcessPdvSyncJob($secondSync->id))->handle();

    $row = DB::table('pdv_turnos')
        ->where('store_pdv_id', 10)
        ->where('id_turno', 'turno-snapshot-replay-001')
        ->first(['duracao_minutos', 'responsavel_pdv_id', 'responsavel_nome', 'qtd_vendas', 'total_vendas']);

    expect(DB::table('pdv_turnos')->count())->toBe(1);
    expect($row)->not->toBeNull();
    expect((int) $row->duracao_minutos)->toBe(295);
    expect((int) $row->responsavel_pdv_id)->toBe(46);
    expect($row->responsavel_nome)->toBe('Luca');
    expect((int) $row->qtd_vendas)->toBe(11);
    expect((float) $row->total_vendas)->toBe(2590.5);
});

test('adds risk flag when snapshot_turnos entry is malformed', function () {
    $sync = createSyncForSnapshotTurnosTest(
        [],
        [
            'invalid-entry',
            [
                'sequencial' => 1,
                'fechado' => true,
            ],
        ],
        'sync-pr34-snapshot-malformed-001'
    );

    (new ProcessPdvSyncJob($sync->id))->handle();

    $riskFlags = PdvSync::query()->whereKey($sync->id)->value('risk_flags');
    $riskFlags = is_array($riskFlags) ? $riskFlags : [];

    expect($riskFlags)->toContain('snapshot_turno_malformed');
    expect(DB::table('pdv_turnos')->count())->toBe(0);
});
