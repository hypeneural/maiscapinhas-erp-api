<?php

declare(strict_types=1);

use App\Jobs\ProcessPdvSyncJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use function Pest\Laravel\assertDatabaseHas;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    // Force reconnection to use sqlite
    DB::purge('mysql');
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    DB::reconnect('sqlite');

    // Manually create necessary tables to avoid 'wheel_players' migration error
    Schema::create('pdv_syncs', function (Blueprint $table) {
        $table->id();
        $table->string('sync_id', 128)->unique();
        $table->string('schema_version', 20)->nullable();
        $table->string('event_type', 20)->nullable();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('store_alias', 100)->nullable();
        $table->dateTime('window_from')->nullable();
        $table->dateTime('window_to')->nullable();
        $table->string('status', 20)->default('queued');
        $table->json('risk_flags')->nullable();
        $table->char('payload_sha256', 64)->nullable();
        $table->unsignedInteger('payload_bytes')->default(0);
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->dateTime('received_at')->nullable();
        $table->dateTime('queued_at')->nullable();
        $table->dateTime('processing_started_at')->nullable();
        $table->dateTime('processed_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
        $table->string('request_id', 64)->nullable();
        $table->string('agent_version', 20)->nullable();
        $table->string('agent_machine', 120)->nullable();
        $table->unsignedInteger('ops_count')->default(0);
        $table->unsignedInteger('ops_loja_count')->default(0);
        $table->json('ops_loja_ids')->nullable();
        $table->unsignedInteger('snapshot_turnos_count')->default(0);
        $table->unsignedInteger('snapshot_vendas_count')->default(0);
        $table->json('warnings')->nullable();
        $table->integer('timestamp_skew_seconds')->nullable();
        $table->boolean('duplicate')->default(false);
        $table->boolean('timestamp_out_of_window')->default(false);
    });

    Schema::create('pdv_sync_payloads', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('pdv_sync_id')->unique();
        $table->longText('payload');
        $table->string('compression', 20)->default('none');
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_store_mappings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('pdv_store_id');
        $table->unsignedBigInteger('store_id');
        $table->boolean('active')->default(true);
        $table->string('alias', 200)->nullable();
        $table->timestamps();
    });

    // We don't need pdv_lojas/usuarios/turnos/vendas schemas for the Webhook reception test
    // because the webhook endpoint only writes to pdv_syncs and pdv_sync_payloads.
    // The ProcessPdvSyncJob handles the rest, but we are queuing it, not running it.

    config()->set('pdv.auth_mode', 'hmac');
    config()->set('pdv.hmac_secret', 'test-pdv-secret');
    config()->set('pdv.allow_bearer_fallback', false);
    config()->set('pdv.timestamp_mode', 'tolerant');
    config()->set('pdv.timestamp_tolerance_seconds', 600);
    config()->set('pdv.queue_name', 'pdv');
    // Ensure 5.0 is supported
    config()->set('pdv.supported_schema_versions', ['3.0', '3.1', '4.0', '5.0']);
    config()->set('pdv.json_schema_validation_enabled', true);
    config()->set('pdv.json_schema_files', [
        '5.0' => base_path('docs/schema_v4.0.json'),
    ]);
    Queue::fake();
});

function pdvPayloadV5(array $overrides = []): array
{
    $base = [
        'schema_version' => '5.0',
        'event_type' => 'mixed',
        'agent' => [
            'version' => '5.0.0',
            'machine' => 'PDV-V5-TEST',
            'sent_at' => now()->toIso8601String(),
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja V5',
            'alias' => 'loja-v5',
            'guid' => 'store-guid-v5',
            'id_hiper' => 9910,
            'cnpj' => '12345678000199',
        ],
        'window' => [
            'from' => now()->subMinutes(10)->toIso8601String(),
            'to' => now()->toIso8601String(),
            'minutes' => 10,
        ],
        'turnos' => [
            [
                'id_turno' => 'turno-v5-001',
                'sequencial' => 1,
                'fechado' => true,
                'data_hora_inicio' => now()->subHours(8)->toIso8601String(),
                'data_hora_termino' => now()->subMinutes(5)->toIso8601String(),
                'duracao_minutos' => 475,
                'periodo' => 'MATUTINO',
                'operador' => [
                    'id_usuario' => 12,
                    'nome' => 'Operador V5',
                    'guid' => 'user-guid-op',
                    'id_hiper' => 1200,
                    'email' => 'op@example.com',
                ],
                'responsavel' => [
                    'id_usuario' => 80,
                    'nome' => 'Vendedor Lider',
                    'guid' => 'user-guid-resp',
                ],
                'qtd_vendas' => 1,
                'total_vendas' => 100.00,
                'qtd_vendedores' => 1,
                'totais_sistema' => [
                    'total' => 100.00,
                    'qtd_vendas' => 1,
                    'por_pagamento' => [],
                ],
                'fechamento_declarado' => [
                    'Id' => 'closure-uuid-1',
                    'data_hora' => now()->toIso8601String(),
                    'total' => 100.00,
                    'qtd_vendas' => 1,
                    'por_pagamento' => [],
                ],
                'falta_caixa' => null,
                'sobra_caixa' => null,
            ]
        ],
        'vendas' => [
            [
                'id_operacao' => 1001,
                'canal' => 'HIPER_CAIXA',
                'data_hora' => now()->subMinutes(6)->toIso8601String(),
                'id_turno' => 'turno-v5-001',
                'itens' => [
                    [
                        'line_id' => 1,
                        'line_no' => 1,
                        'id_produto' => 500,
                        'nome' => 'Produto V5',
                        'qtd' => 1,
                        'preco_unit' => 100.00,
                        'total' => 100.00,
                        'desconto' => 0,
                        'vendedor' => [
                            'id_usuario' => 80,
                            'nome' => 'Vendedor Lider',
                            'guid' => 'user-guid-resp',
                        ],
                    ]
                ],
                'pagamentos' => [],
                'total' => 100.00,
            ]
        ],
        'resumo' => [
            'by_vendor' => [
                [
                    'id_usuario' => 80,
                    'nome' => 'Vendedor Lider',
                    'qtd_cupons' => 1,
                    'total_vendido' => 100.00,
                ]
            ],
            'by_payment' => [],
        ],
        'snapshot_turnos' => [],
        'snapshot_vendas' => [],
        'ops' => [
            'count' => 1,
            'ids' => [1001],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-v5-test-001',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function signedPdvRequestV5(array $payload)
{
    $timestamp = now()->timestamp;
    $secret = config('pdv.hmac_secret');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return test()->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PDV_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PDV_SIGNATURE' => $signature,
            'HTTP_X_PDV_SCHEMA_VERSION' => '5.0',
            'HTTP_X_REQUEST_ID' => 'req-v5-test',
        ],
        $body
    );
}

test('accepts valid v5.0 webhook and queues processing', function () {
    $payload = pdvPayloadV5();

    $response = signedPdvRequestV5($payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.schema_version', '5.0')
        ->assertJsonPath('data.sync_id', 'sync-v5-test-001');

    $this->assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-v5-test-001',
        'schema_version' => '5.0',
        'event_type' => 'mixed',
        'status' => 'queued',
        'store_pdv_id' => 10,
    ]);

    Queue::assertPushed(ProcessPdvSyncJob::class, function (ProcessPdvSyncJob $job) {
        return $job->queue === 'pdv';
    });
});
