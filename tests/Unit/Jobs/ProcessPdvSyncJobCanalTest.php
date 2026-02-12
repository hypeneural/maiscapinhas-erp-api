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
});

function buildPdvSyncPayloadForCanalTest(array $vendas, string $syncId): array
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
        'vendas' => $vendas,
        'ops' => [
            'count' => count($vendas),
            'ids' => array_values(array_map(
                static fn (array $venda): int => (int) ($venda['id_operacao'] ?? 0),
                $vendas
            )),
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => $syncId,
            'warnings' => [],
        ],
    ];
}

function createPdvSyncWithPayloadForCanalTest(array $vendas, string $syncId): PdvSync
{
    $payload = buildPdvSyncPayloadForCanalTest($vendas, $syncId);
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode PDV payload for test.');
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
        'ops_count' => count($vendas),
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

test('stores same id_operacao from different canais as distinct rows', function () {
    $sync = createPdvSyncWithPayloadForCanalTest([
        [
            'id_operacao' => 12380,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => '2026-02-11T13:05:00-03:00',
            'total' => 100.00,
            'itens' => [],
            'pagamentos' => [],
        ],
        [
            'id_operacao' => 12380,
            'canal' => 'HIPER_LOJA',
            'data_hora' => '2026-02-11T13:06:00-03:00',
            'total' => 200.00,
            'itens' => [],
            'pagamentos' => [],
        ],
    ], 'sync-pr32-distinct-canal-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_vendas')->count())->toBe(2);
    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 12380)
        ->where('canal', 'HIPER_CAIXA')
        ->exists())->toBeTrue();
    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 12380)
        ->where('canal', 'HIPER_LOJA')
        ->exists())->toBeTrue();
});

test('replay for same canonical key updates row without duplication', function () {
    $firstSync = createPdvSyncWithPayloadForCanalTest([
        [
            'id_operacao' => 9001,
            'canal' => 'HIPER_LOJA',
            'data_hora' => '2026-02-11T13:05:00-03:00',
            'total' => 80.00,
            'itens' => [],
            'pagamentos' => [],
        ],
    ], 'sync-pr32-replay-001');
    (new ProcessPdvSyncJob($firstSync->id))->handle();

    $secondSync = createPdvSyncWithPayloadForCanalTest([
        [
            'id_operacao' => 9001,
            'canal' => 'HIPER_LOJA',
            'data_hora' => '2026-02-11T13:08:00-03:00',
            'total' => 95.50,
            'itens' => [],
            'pagamentos' => [],
        ],
    ], 'sync-pr32-replay-002');
    (new ProcessPdvSyncJob($secondSync->id))->handle();

    $row = DB::table('pdv_vendas')
        ->where('store_pdv_id', 10)
        ->where('canal', 'HIPER_LOJA')
        ->where('id_operacao', 9001)
        ->first(['total', 'sync_id']);

    expect(DB::table('pdv_vendas')->count())->toBe(1);
    expect($row)->not->toBeNull();
    expect((float) $row->total)->toBe(95.5);
    expect($row->sync_id)->toBe('sync-pr32-replay-002');
});

test('defaults missing canal to HIPER_CAIXA', function () {
    $sync = createPdvSyncWithPayloadForCanalTest([
        [
            'id_operacao' => 7777,
            'data_hora' => '2026-02-11T13:05:00-03:00',
            'total' => 49.90,
            'itens' => [],
            'pagamentos' => [],
        ],
    ], 'sync-pr32-default-canal-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 7777)
        ->value('canal'))->toBe('HIPER_CAIXA');
});

test('falls back invalid canal and adds risk flag', function () {
    $sync = createPdvSyncWithPayloadForCanalTest([
        [
            'id_operacao' => 8888,
            'canal' => 'canal_desconhecido',
            'data_hora' => '2026-02-11T13:05:00-03:00',
            'total' => 59.90,
            'itens' => [],
            'pagamentos' => [],
        ],
    ], 'sync-pr32-invalid-canal-001');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $processedSync = PdvSync::query()->findOrFail($sync->id);
    $riskFlags = is_array($processedSync->risk_flags) ? $processedSync->risk_flags : [];

    expect(DB::table('pdv_vendas')
        ->where('store_pdv_id', 10)
        ->where('id_operacao', 8888)
        ->value('canal'))->toBe('HIPER_CAIXA');
    expect($riskFlags)->toContain('venda_canal_invalid');
});
