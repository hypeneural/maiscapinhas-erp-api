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
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
        $table->unique(['store_pdv_id', 'canal', 'line_id'], 'pdv_venda_itens_unique_canal_line_id');
        $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], 'pdv_venda_itens_unique_canal_row_hash');
    });

    Schema::create('pdv_usuarios', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_usuario_hiper')->unique();
        $table->string('nome_padronizado', 200);
        $table->string('nome_hiper', 200)->nullable();
        $table->string('login_hiper', 100)->nullable();
        $table->string('papel', 50)->default('VENDEDOR');
        $table->boolean('ativo')->default(true);
        $table->string('fonte', 50)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_user_mappings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id')->nullable();
        $table->unsignedBigInteger('pdv_user_id');
        $table->string('pdv_user_name', 100)->nullable();
        $table->string('pdv_user_login', 100)->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->boolean('is_store_operator')->default(false);
        $table->boolean('active')->default(true);
        $table->string('source', 50)->default('manual');
        $table->unsignedInteger('confidence')->default(100);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
        $table->unique(['pdv_user_id'], 'pdv_user_mappings_unique_pdv_user_id');
    });
});

/**
 * @param array<string, mixed> $payload
 */
function createSyncForLoginPersistenceTest(array $payload): PdvSync
{
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode payload for login persistence test.');
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

test('persists vendedor_login into pdv_venda_itens when provided by payload', function () {
    $payload = [
        'schema_version' => '3.1',
        'event_type' => 'sales',
        'agent' => [
            'version' => '3.1.0',
            'machine' => 'PDV-STORE-01',
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
            'id_operacao' => 12345,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => '2026-02-13T10:05:00-03:00',
            'id_turno' => null,
            'total' => 49.90,
            'itens' => [[
                'line_id' => 55001,
                'line_no' => 1,
                'id_produto' => 2001,
                'codigo_barras' => '7891234567890',
                'nome' => 'Capinha iPhone 15',
                'qtd' => 1,
                'preco_unit' => 49.90,
                'total' => 49.90,
                'desconto' => 0.00,
                'vendedor' => [
                    'id_usuario' => 79,
                    'nome' => 'Daren',
                    'login' => 'daren',
                ],
            ]],
            'pagamentos' => [],
        ]],
        'ops' => [
            'count' => 1,
            'ids' => [12345],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-pr51-item-login-001',
            'warnings' => [],
        ],
    ];

    $sync = createSyncForLoginPersistenceTest($payload);
    (new ProcessPdvSyncJob($sync->id))->handle();

    $row = DB::table('pdv_venda_itens')
        ->where('store_pdv_id', 10)
        ->where('canal', 'HIPER_CAIXA')
        ->where('line_id', 55001)
        ->first(['vendedor_pdv_id', 'vendedor_nome', 'vendedor_login']);

    expect($row)->not->toBeNull();
    expect((int) $row->vendedor_pdv_id)->toBe(79);
    expect($row->vendedor_nome)->toBe('Daren');
    expect($row->vendedor_login)->toBe('daren');
    expect(DB::table('pdv_usuarios')->where('id_usuario_hiper', 79)->value('login_hiper'))->toBe('daren');
});

test('backfills pdv_usuarios.login_hiper from mapping when payload login is absent', function () {
    DB::table('pdv_user_mappings')->insert([
        'pdv_user_id' => 66,
        'pdv_user_name' => 'Larissa',
        'pdv_user_login' => 'larissa',
        'user_id' => 24,
        'is_store_operator' => false,
        'active' => true,
        'source' => 'manual',
        'confidence' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'schema_version' => '3.1',
        'event_type' => 'sales',
        'agent' => [
            'version' => '3.1.0',
            'machine' => 'PDV-STORE-01',
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
            'id_operacao' => 88801,
            'canal' => 'HIPER_LOJA',
            'data_hora' => '2026-02-13T10:05:00-03:00',
            'id_turno' => null,
            'total' => 19.90,
            'itens' => [[
                'line_id' => 88001,
                'line_no' => 1,
                'id_produto' => 3001,
                'codigo_barras' => null,
                'nome' => 'Pelicula',
                'qtd' => 1,
                'preco_unit' => 19.90,
                'total' => 19.90,
                'desconto' => 0.00,
                'vendedor' => [
                    'id_usuario' => 66,
                    'nome' => 'Larissa',
                    'login' => null,
                ],
            ]],
            'pagamentos' => [],
        ]],
        'ops' => [
            'count' => 0,
            'ids' => [],
            'loja_count' => 1,
            'loja_ids' => [88801],
        ],
        'integrity' => [
            'sync_id' => 'sync-pr51-master-login-backfill-001',
            'warnings' => [],
        ],
    ];

    $sync = createSyncForLoginPersistenceTest($payload);
    (new ProcessPdvSyncJob($sync->id))->handle();

    $login = DB::table('pdv_usuarios')
        ->where('id_usuario_hiper', 66)
        ->value('login_hiper');

    expect($login)->toBe('larissa');
});
