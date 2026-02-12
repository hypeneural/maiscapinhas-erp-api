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
 * @param array<int, mixed> $snapshotVendas
 * @return array<string, mixed>
 */
function buildPayloadForSnapshotVendasTest(array $snapshotVendas, string $syncId): array
{
    return [
        'schema_version' => '3.0',
        'event_type' => 'sales',
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
        'turnos' => [],
        'snapshot_turnos' => [],
        'vendas' => [],
        'snapshot_vendas' => $snapshotVendas,
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
 * @param array<int, mixed> $snapshotVendas
 */
function createSyncForSnapshotVendasTest(array $snapshotVendas, string $syncId): PdvSync
{
    $payload = buildPayloadForSnapshotVendasTest($snapshotVendas, $syncId);
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode snapshot_vendas payload for test.');
    }

    $now = now();

    $sync = PdvSync::query()->create([
        'sync_id' => $syncId,
        'schema_version' => '3.0',
        'event_type' => 'sales',
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

test('snapshot_vendas keeps same id_operacao separated by canal', function () {
    $sync = createSyncForSnapshotVendasTest([
        [
            'id_operacao' => 12380,
            'canal' => 'HIPER_CAIXA',
            'data_hora_inicio' => '2026-02-11T13:05:00-03:00',
            'data_hora_termino' => '2026-02-11T13:05:12-03:00',
            'duracao_segundos' => 12,
            'id_turno' => 'turno-001',
            'turno_seq' => 1,
            'vendedor' => ['id_usuario' => 80, 'nome' => 'Daren'],
            'qtd_itens' => 3,
            'total_itens' => 129.00,
        ],
        [
            'id_operacao' => 12380,
            'canal' => 'HIPER_LOJA',
            'data_hora_inicio' => '2026-02-11T13:06:00-03:00',
            'data_hora_termino' => '2026-02-11T13:06:20-03:00',
            'duracao_segundos' => 20,
            'id_turno' => 'turno-002',
            'turno_seq' => 2,
            'vendedor' => ['id_usuario' => 81, 'nome' => 'Maria'],
            'qtd_itens' => 5,
            'total_itens' => 259.90,
        ],
    ], 'sync-pr35-separate-canal-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_vendas_resumo')->count())->toBe(2);
    expect(DB::table('pdv_vendas_resumo')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 12380)
        ->where('canal', 'HIPER_CAIXA')
        ->exists())->toBeTrue();
    expect(DB::table('pdv_vendas_resumo')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 12380)
        ->where('canal', 'HIPER_LOJA')
        ->exists())->toBeTrue();
});

test('snapshot_vendas replay updates same canonical key without duplicates', function () {
    $firstSync = createSyncForSnapshotVendasTest([
        [
            'id_operacao' => 9001,
            'canal' => 'HIPER_LOJA',
            'data_hora_inicio' => '2026-02-11T13:00:00-03:00',
            'duracao_segundos' => 10,
            'qtd_itens' => 2,
            'total_itens' => 99.90,
        ],
    ], 'sync-pr35-replay-001-a');
    (new ProcessPdvSyncJob($firstSync->id))->handle();

    $secondSync = createSyncForSnapshotVendasTest([
        [
            'id_operacao' => 9001,
            'canal' => 'HIPER_LOJA',
            'data_hora_inicio' => '2026-02-11T13:08:00-03:00',
            'duracao_segundos' => 18,
            'qtd_itens' => 4,
            'total_itens' => 189.50,
        ],
    ], 'sync-pr35-replay-001-b');
    (new ProcessPdvSyncJob($secondSync->id))->handle();

    $row = DB::table('pdv_vendas_resumo')
        ->where('store_pdv_id', 10)
        ->where('canal', 'HIPER_LOJA')
        ->where('id_operacao', 9001)
        ->first(['duracao_segundos', 'qtd_itens', 'total_itens', 'last_sync_id']);

    expect(DB::table('pdv_vendas_resumo')->count())->toBe(1);
    expect($row)->not->toBeNull();
    expect((int) $row->duracao_segundos)->toBe(18);
    expect((int) $row->qtd_itens)->toBe(4);
    expect((float) $row->total_itens)->toBe(189.5);
    expect($row->last_sync_id)->toBe('sync-pr35-replay-001-b');
});

test('snapshot_vendas accepts null optional fields', function () {
    $sync = createSyncForSnapshotVendasTest([
        [
            'id_operacao' => 7777,
            'canal' => 'HIPER_CAIXA',
            'data_hora_inicio' => '2026-02-11T13:05:00-03:00',
            'data_hora_termino' => null,
            'duracao_segundos' => null,
            'id_turno' => null,
            'turno_seq' => null,
            'vendedor' => null,
            'qtd_itens' => 1,
            'total_itens' => 49.90,
        ],
    ], 'sync-pr35-nullable-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $row = DB::table('pdv_vendas_resumo')
        ->where('store_pdv_id', 10)
        ->where('canal', 'HIPER_CAIXA')
        ->where('id_operacao', 7777)
        ->first(['data_hora_termino', 'duracao_segundos', 'id_turno', 'turno_seq', 'vendedor_pdv_id', 'vendedor_nome']);

    expect($row)->not->toBeNull();
    expect($row->data_hora_termino)->toBeNull();
    expect($row->duracao_segundos)->toBeNull();
    expect($row->id_turno)->toBeNull();
    expect($row->turno_seq)->toBeNull();
    expect($row->vendedor_pdv_id)->toBeNull();
    expect($row->vendedor_nome)->toBeNull();
});

test('adds risk flag when snapshot_vendas entry is malformed', function () {
    $sync = createSyncForSnapshotVendasTest([
        'invalid-entry',
        [
            'canal' => 'HIPER_CAIXA',
            'qtd_itens' => 1,
            'total_itens' => 10.00,
        ],
    ], 'sync-pr35-malformed-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $riskFlags = PdvSync::query()->whereKey($sync->id)->value('risk_flags');
    $riskFlags = is_array($riskFlags) ? $riskFlags : [];

    expect($riskFlags)->toContain('snapshot_venda_malformed');
    expect(DB::table('pdv_vendas_resumo')->count())->toBe(0);
});
