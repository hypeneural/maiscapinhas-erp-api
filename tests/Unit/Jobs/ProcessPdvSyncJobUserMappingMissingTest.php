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
        $table->unique(['store_pdv_id', 'canal', 'id_operacao'], 'pdv_vendas_unique_canal');
    });

    Schema::create('pdv_venda_itens', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->unsignedBigInteger('line_id')->nullable();
        $table->unsignedInteger('line_no')->default(1);
        $table->string('row_hash', 64);
        $table->unsignedBigInteger('id_produto')->nullable();
        $table->string('codigo_barras', 50)->nullable();
        $table->string('nome_produto', 255)->nullable();
        $table->decimal('qtd', 14, 3)->default(0);
        $table->decimal('preco_unit', 14, 2)->default(0);
        $table->decimal('total', 14, 2)->default(0);
        $table->decimal('desconto', 14, 2)->default(0);
        $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
        $table->string('vendedor_nome', 200)->nullable();
        $table->string('vendedor_login', 100)->nullable();
        $table->unsignedBigInteger('vendedor_user_id')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
        $table->unique(['store_pdv_id', 'canal', 'line_id'], 'pdv_venda_itens_unique_canal_line_id');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], 'pdv_venda_itens_unique_canal_row_hash');
    });
});

/**
 * @param array<string, mixed> $payload
 */
function createSyncForUserMappingMissingTest(array $payload): PdvSync
{
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode payload for user mapping missing test.');
    }

    $now = now();
    $sync = PdvSync::query()->create([
        'sync_id' => (string) data_get($payload, 'integrity.sync_id'),
        'schema_version' => (string) data_get($payload, 'schema_version'),
        'event_type' => (string) data_get($payload, 'event_type', 'sales'),
        'store_pdv_id' => (int) data_get($payload, 'store.id_ponto_venda', 0),
        'store_id' => 1,
        'store_alias' => (string) data_get($payload, 'store.alias', ''),
        'window_from' => '2026-02-13 10:00:00',
        'window_to' => '2026-02-13 10:10:00',
        'agent_version' => (string) data_get($payload, 'agent.version'),
        'agent_machine' => (string) data_get($payload, 'agent.machine'),
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

test('does not add user_mapping_missing when item has no vendedor info', function () {
    $payload = [
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-TEST',
            'sent_at' => '2026-02-13T10:10:00-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
            'cnpj' => '29094289000137',
        ],
        'window' => [
            'from' => '2026-02-13T10:00:00-03:00',
            'to' => '2026-02-13T10:10:00-03:00',
            'minutes' => 10,
        ],
        'turnos' => [],
        'snapshot_turnos' => [],
        'snapshot_vendas' => [],
        'vendas' => [[
            'id_operacao' => 123,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => '2026-02-13T10:05:00-03:00',
            'id_turno' => null,
            'total' => 10.00,
            'itens' => [[
                'line_id' => 1,
                'line_no' => 1,
                'id_produto' => 2001,
                'codigo_barras' => 'E2E',
                'nome' => 'Produto E2E',
                'qtd' => '1',
                'preco_unit' => '10.00',
                'total' => '10.00',
                'desconto' => '0.00',
                // vendedor intentionally missing
            ]],
            'pagamentos' => [],
        ]],
        'ops' => [
            'count' => 1,
            'ids' => [123],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-test-user-mapping-missing-001',
            'warnings' => [],
        ],
    ];

    $sync = createSyncForUserMappingMissingTest($payload);

    (new ProcessPdvSyncJob($sync->id))->handle();

    $processedSync = PdvSync::query()->findOrFail($sync->id);
    $riskFlags = is_array($processedSync->risk_flags) ? $processedSync->risk_flags : [];

    expect($riskFlags)->not->toContain('user_mapping_missing');
});

