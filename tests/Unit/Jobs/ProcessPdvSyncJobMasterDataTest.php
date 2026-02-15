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
        $table->string('meio_pagamento', 100)->nullable();
        $table->decimal('total', 14, 2)->default(0);
        $table->unsignedInteger('qtd_vendas')->default(0);
        $table->string('last_sync_id', 128)->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
        $table->unique(['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'], 'pdv_turno_pagamentos_unique_key');
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

    Schema::create('pdv_lojas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_ponto_venda')->unique();
        $table->string('nome_padronizado', 200);
        $table->string('nome_hiper', 200)->nullable();
        $table->string('alias', 100)->nullable();
        $table->boolean('ativa')->default(true);
        $table->string('fonte', 50)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
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

    Schema::create('pdv_meios_pagamento', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_finalizador')->unique();
        $table->string('nome_padronizado', 100);
        $table->string('nome_hiper', 100)->nullable();
        $table->string('categoria', 50)->nullable();
        $table->boolean('ativo')->default(true);
        $table->string('fonte', 50)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });
});

/**
 * @return array<string, mixed>
 */
function buildMasterDataPayload(array $overrides = []): array
{
    $base = [
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => '2026-02-11T13:10:00-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja Hyper 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => '2026-02-11T13:00:00-03:00',
            'to' => '2026-02-11T13:10:00-03:00',
            'minutes' => 10,
        ],
        'turnos' => [
            [
                'id_turno' => 'turno-master-001',
                'sequencial' => 1,
                'fechado' => true,
                'operador' => ['id_usuario' => 12, 'nome' => 'Carlos'],
                'responsavel' => ['id_usuario' => 80, 'nome' => 'Daren Novo'],
                'totais_sistema' => [
                    'total' => 500.00,
                    'qtd_vendas' => 5,
                    'por_pagamento' => [
                        ['id_finalizador' => 5, 'meio' => 'Pix', 'total' => 300.00, 'qtd_vendas' => 3],
                        ['id_finalizador' => 4, 'meio' => 'Cartao de credito', 'total' => 200.00, 'qtd_vendas' => 2],
                    ],
                ],
                'fechamento_declarado' => [
                    'total' => 500.00,
                    'qtd_vendas' => 5,
                    'por_pagamento' => [],
                ],
                'falta_caixa' => [
                    'total' => 0.00,
                    'qtd_vendas' => 0,
                    'por_pagamento' => [],
                ],
            ]
        ],
        'vendas' => [],
        'snapshot_turnos' => [],
        'snapshot_vendas' => [
            [
                'id_operacao' => 2001,
                'canal' => 'HIPER_CAIXA',
                'vendedor' => ['id_usuario' => 90, 'nome' => 'Vendedor Snapshot'],
                'qtd_itens' => 1,
                'total_itens' => 50.00,
            ]
        ],
        'ops' => [
            'count' => 0,
            'ids' => [],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-master-data-001',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function createSyncForMasterDataTest(array $payload): PdvSync
{
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        throw new RuntimeException('Failed to encode payload for master data test.');
    }

    $now = now();

    $sync = PdvSync::query()->create([
        'sync_id' => (string) data_get($payload, 'integrity.sync_id'),
        'schema_version' => (string) data_get($payload, 'schema_version'),
        'event_type' => (string) data_get($payload, 'event_type', 'sales'),
        'store_pdv_id' => (int) data_get($payload, 'store.id_ponto_venda', 0),
        'store_id' => 1,
        'store_alias' => (string) data_get($payload, 'store.alias', ''),
        'window_from' => '2026-02-11 16:00:00',
        'window_to' => '2026-02-11 16:10:00',
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

test('auto registers master entities and preserves nome_padronizado on updates', function () {
    DB::table('pdv_lojas')->insert([
        'id_ponto_venda' => 10,
        'nome_padronizado' => 'Loja Manual',
        'nome_hiper' => 'Loja Antiga',
        'alias' => 'old-alias',
        'fonte' => 'HIPER',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdv_usuarios')->insert([
        'id_usuario_hiper' => 80,
        'nome_padronizado' => 'Usuario Manual',
        'nome_hiper' => 'Nome Antigo',
        'papel' => 'VENDEDOR',
        'fonte' => 'HIPER',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdv_meios_pagamento')->insert([
        'id_finalizador' => 5,
        'nome_padronizado' => 'PIX Manual',
        'nome_hiper' => 'PIX OLD',
        'categoria' => 'PIX',
        'fonte' => 'HIPER',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = buildMasterDataPayload();
    $sync = createSyncForMasterDataTest($payload);
    (new ProcessPdvSyncJob($sync->id))->handle();

    expect(DB::table('pdv_lojas')->count())->toBe(1);
    expect(DB::table('pdv_lojas')->where('id_ponto_venda', 10)->value('nome_padronizado'))->toBe('Loja Manual');
    expect(DB::table('pdv_lojas')->where('id_ponto_venda', 10)->value('nome_hiper'))->toBe('Loja Hyper 10');
    expect(DB::table('pdv_lojas')->where('id_ponto_venda', 10)->value('alias'))->toBe('loja-10');

    expect(DB::table('pdv_usuarios')->where('id_usuario_hiper', 80)->value('nome_padronizado'))->toBe('Usuario Manual');
    expect(DB::table('pdv_usuarios')->where('id_usuario_hiper', 80)->value('nome_hiper'))->toBe('Daren Novo');
    expect(DB::table('pdv_usuarios')->where('id_usuario_hiper', 12)->value('papel'))->toBe('OPERADOR');
    expect(DB::table('pdv_usuarios')->where('id_usuario_hiper', 90)->value('papel'))->toBe('VENDEDOR');

    expect(DB::table('pdv_meios_pagamento')->where('id_finalizador', 5)->value('nome_padronizado'))->toBe('PIX Manual');
    expect(DB::table('pdv_meios_pagamento')->where('id_finalizador', 5)->value('nome_hiper'))->toBe('Pix');
    expect(DB::table('pdv_meios_pagamento')->where('id_finalizador', 4)->value('categoria'))->toBe('CREDITO');
});

test('master data auto registration is idempotent on replay', function () {
    $firstPayload = buildMasterDataPayload([
        'integrity' => ['sync_id' => 'sync-master-data-replay-001'],
    ]);
    $firstSync = createSyncForMasterDataTest($firstPayload);
    (new ProcessPdvSyncJob($firstSync->id))->handle();

    $secondPayload = buildMasterDataPayload([
        'store' => [
            'nome' => 'Loja Hyper 10 Atualizada',
            'alias' => 'loja-10-new',
        ],
        'turnos' => [
            [
                'id_turno' => 'turno-master-001',
                'sequencial' => 1,
                'fechado' => true,
                'operador' => ['id_usuario' => 12, 'nome' => 'Carlos Atualizado'],
                'responsavel' => ['id_usuario' => 80, 'nome' => 'Daren Atualizado'],
                'totais_sistema' => [
                    'total' => 500.00,
                    'qtd_vendas' => 5,
                    'por_pagamento' => [
                        ['id_finalizador' => 5, 'meio' => 'PIX', 'total' => 500.00, 'qtd_vendas' => 5],
                    ],
                ],
                'fechamento_declarado' => ['total' => 500.00, 'qtd_vendas' => 5, 'por_pagamento' => []],
                'falta_caixa' => ['total' => 0.00, 'qtd_vendas' => 0, 'por_pagamento' => []],
            ]
        ],
        'integrity' => ['sync_id' => 'sync-master-data-replay-002'],
    ]);
    $secondSync = createSyncForMasterDataTest($secondPayload);
    (new ProcessPdvSyncJob($secondSync->id))->handle();

    expect(DB::table('pdv_lojas')->count())->toBe(1);
    expect(DB::table('pdv_usuarios')->whereIn('id_usuario_hiper', [12, 80, 90])->count())->toBe(3);
    expect(DB::table('pdv_meios_pagamento')->whereIn('id_finalizador', [4, 5])->count())->toBe(2);
    expect(DB::table('pdv_lojas')->where('id_ponto_venda', 10)->value('nome_hiper'))->toBe('Loja Hyper 10 Atualizada');
});
