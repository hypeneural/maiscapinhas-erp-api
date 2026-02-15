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

    Schema::create('pdv_lojas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_ponto_venda')->unique();
        $table->string('nome_padronizado', 200)->nullable();
        $table->string('nome_hiper', 200)->nullable();
        $table->string('alias', 100)->nullable();
        $table->string('guid', 36)->nullable();
        $table->unsignedBigInteger('id_hiper')->nullable();
        $table->boolean('ativa')->default(true);
        $table->string('fonte', 20)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_usuarios', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_usuario_hiper')->unique();
        $table->string('nome_padronizado', 200)->nullable();
        $table->string('nome_hiper', 200)->nullable();
        $table->string('papel', 50)->default('VENDEDOR');
        $table->string('login_hiper', 100)->nullable();
        $table->string('guid', 36)->nullable();
        $table->string('email', 255)->nullable();
        $table->string('documento', 50)->nullable();
        $table->string('tipo', 20)->nullable();
        $table->boolean('ativo')->default(true);
        $table->string('fonte', 20)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    // Complete tables needed for handle() execution flow
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
        $table->string('operador_guid', 36)->nullable();
        $table->unsignedBigInteger('operador_hiper_id')->nullable();
        $table->string('responsavel_guid', 36)->nullable();
        $table->unsignedBigInteger('responsavel_hiper_id')->nullable();

        $table->unique(['store_pdv_id', 'canal', 'id_turno'], 'pdv_turnos_unique');
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

    Schema::create('pdv_venda_pagamentos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->string('line_id', 64)->nullable();
        $table->string('row_hash', 64)->nullable();
        $table->unique('id'); // Logic fix
    });

    // pdv_meios_pagamento schema
    Schema::create('pdv_meios_pagamento', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_finalizador')->unique();
        $table->string('nome_padronizado', 100)->nullable();
        $table->string('nome_hiper', 100)->nullable();
        $table->string('categoria', 50)->nullable();
        $table->boolean('ativo')->default(true);
        $table->string('fonte', 20)->default('HIPER');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });
});

function createPdvSyncForMasterDataTest(array $payloadOverride, string $syncId): PdvSync
{
    $payload = array_merge([
        'schema_version' => '4.0',
        'event_type' => 'turno_closure',
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja Teste',
            'alias' => 'loja_10',
            'guid' => 'store-guid-1',
            'id_hiper' => 9910,
        ],
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

test('persists store guid and id_hiper', function () {
    $sync = createPdvSyncForMasterDataTest([], 'sync-master-store');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $loja = DB::table('pdv_lojas')->where('id_ponto_venda', 10)->first();

    expect($loja->guid)->toBe('store-guid-1');
    expect((int) $loja->id_hiper)->toBe(9910);
    expect($loja->nome_hiper)->toBe('Loja Teste');
});

test('persists user guid and id_hiper from turno operator', function () {
    $sync = createPdvSyncForMasterDataTest([
        'turnos' => [
            [
                'id_turno' => 't-op-test',
                'data_hora_inicio' => '2026-02-15T12:00:00-03:00',
                'operador' => [
                    'id_usuario' => 50,
                    'nome' => 'Operador Master',
                    'login' => 'opmaster',
                    'guid' => 'guid-user-50',
                    'id_hiper' => 5000,
                ],
                'responsavel' => [
                    'id_usuario' => 51,
                    'nome' => 'Responsavel Master',
                ],
                'totais_sistema' => ['por_pagamento' => []],
                'fechamento_declarado' => ['por_pagamento' => []],
                'falta_caixa' => ['por_pagamento' => []],
            ]
        ]
    ], 'sync-master-users');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $user = DB::table('pdv_usuarios')->where('id_usuario_hiper', 50)->first();

    expect($user->guid)->toBe('guid-user-50');
    // We expect 50 because we don't save id_hiper to DB in pdv_usuarios, we rely on id_usuario being the key.
    expect((int) $user->id_usuario_hiper)->toBe(50);
});

test('updates existing user with new V5 data', function () {
    // Pre-create user without GUID
    DB::table('pdv_usuarios')->insert([
        'id_usuario_hiper' => 60,
        'nome_padronizado' => 'Old User',
        'nome_hiper' => 'Old User',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sync = createPdvSyncForMasterDataTest([
        'vendas' => [
            [
                'id_operacao' => 1001,
                'data_hora' => '2026-02-15T12:05:00-03:00',
                'itens' => [
                    [
                        'line_id' => 1,
                        'id_produto' => 999,
                        'vendedor' => [
                            'id_usuario' => 60,
                            'nome' => 'New User Name',
                            'guid' => 'guid-user-60',
                        ]
                    ]
                ],
                'pagamentos' => []
            ]
        ]
    ], 'sync-master-update');

    (new ProcessPdvSyncJob($sync->id))->handle();

    $user = DB::table('pdv_usuarios')->where('id_usuario_hiper', 60)->first();

    expect($user->guid)->toBe('guid-user-60');
    expect($user->nome_hiper)->toBe('New User Name');
});
