<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

function linkUserToStore(User $user, Store $store, string $role = 'admin'): void
{
    DB::table('store_users')->insert([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mapPdvStore(int $pdvStoreId, Store $store, ?string $alias = null): void
{
    DB::table('pdv_store_mappings')->insert([
        'pdv_store_id' => $pdvStoreId,
        'store_id' => $store->id,
        'alias' => $alias ?? ('loja-' . $pdvStoreId),
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedPdvTurno(array $overrides = []): void
{
    $row = array_merge([
        'store_pdv_id' => 13,
        'store_id' => null,
        'id_turno' => 'turno-test-001',
        'sequencial' => 1,
        'fechado' => true,
        'data_hora_inicio' => now()->setTime(8, 0, 0)->toDateTimeString(),
        'data_hora_termino' => now()->setTime(14, 0, 0)->toDateTimeString(),
        'duracao_minutos' => 360,
        'periodo' => 'MATUTINO',
        'operador_pdv_id' => 12,
        'operador_nome' => 'Carlos',
        'responsavel_pdv_id' => 80,
        'responsavel_nome' => 'Daren',
        'total_sistema' => 1000.00,
        'qtd_vendas_sistema' => 10,
        'qtd_vendas' => 10,
        'total_vendas' => 1000.00,
        'qtd_vendedores' => 2,
        'total_declarado' => 1000.00,
        'total_falta' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('pdv_turnos')->insert($row);
}

function seedPdvTurnoPagamento(array $overrides = []): void
{
    $row = array_merge([
        'store_pdv_id' => 13,
        'store_id' => null,
        'id_turno' => 'turno-test-001',
        'tipo' => 'sistema',
        'id_finalizador' => 5,
        'meio_pagamento' => 'Pix',
        'total' => 1000.00,
        'qtd_vendas' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('pdv_turno_pagamentos')->insert($row);
}

function seedPdvVenda(array $overrides = []): void
{
    $row = array_merge([
        'store_pdv_id' => 13,
        'store_id' => null,
        'id_operacao' => 100,
        'canal' => 'HIPER_CAIXA',
        'id_turno' => 'turno-test-001',
        'data_hora' => now()->subMinutes(5)->toDateTimeString(),
        'total' => 100.00,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('pdv_vendas')->insert($row);
}

function seedPdvVendaItem(array $overrides = []): void
{
    $storePdvId = (int) ($overrides['store_pdv_id'] ?? 13);
    $idOperacao = (int) ($overrides['id_operacao'] ?? 100);
    $lineId = (int) ($overrides['line_id'] ?? random_int(100000, 999999));

    $row = array_merge([
        'store_pdv_id' => $storePdvId,
        'store_id' => $overrides['store_id'] ?? null,
        'id_operacao' => $idOperacao,
        'line_id' => $lineId,
        'line_no' => 1,
        'row_hash' => hash('sha256', "item|{$storePdvId}|{$idOperacao}|{$lineId}"),
        'id_produto' => 5402,
        'codigo_barras' => '7891234567890',
        'nome_produto' => 'Produto Teste',
        'qtd' => 1.0,
        'preco_unit' => 100.0,
        'total' => 100.0,
        'desconto' => 0.0,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('pdv_venda_itens')->insert($row);
}

function seedPdvVendaPagamento(array $overrides = []): void
{
    $storePdvId = (int) ($overrides['store_pdv_id'] ?? 13);
    $idOperacao = (int) ($overrides['id_operacao'] ?? 100);
    $lineId = (int) ($overrides['line_id'] ?? random_int(200000, 999999));

    $row = array_merge([
        'store_pdv_id' => $storePdvId,
        'store_id' => $overrides['store_id'] ?? null,
        'id_operacao' => $idOperacao,
        'line_id' => $lineId,
        'line_no' => 1,
        'row_hash' => hash('sha256', "pay|{$storePdvId}|{$idOperacao}|{$lineId}"),
        'id_finalizador' => 5,
        'meio_pagamento' => 'Pix',
        'valor' => 100.0,
        'troco' => 0.0,
        'parcelas' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('pdv_venda_pagamentos')->insert($row);
}

test('pdv reports turnos enforces store authorization', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeAllowed = Store::factory()->create();
    $storeBlocked = Store::factory()->create();

    linkUserToStore($user, $storeAllowed, 'admin');
    mapPdvStore(13, $storeAllowed);
    mapPdvStore(14, $storeBlocked);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?store_id=' . $storeBlocked->id . '&date=' . now()->toDateString())
        ->assertStatus(403);
});

test('pdv reports turnos returns totals and pagamentos grouped by tipo', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-test-001',
        'sequencial' => 1,
        'total_sistema' => 1000.0,
        'total_declarado' => 980.0,
        'total_falta' => 20.0,
    ]);
    seedPdvTurnoPagamento([
        'store_id' => $store->id,
        'id_turno' => 'turno-test-001',
        'tipo' => 'sistema',
        'total' => 1000.0,
    ]);
    seedPdvTurnoPagamento([
        'store_id' => $store->id,
        'id_turno' => 'turno-test-001',
        'tipo' => 'declarado',
        'id_finalizador' => 4,
        'meio_pagamento' => 'Credito',
        'total' => 980.0,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?store_id=' . $store->id . '&date=' . now()->toDateString())
        ->assertStatus(200)
        ->assertJsonPath('data.summary.qtd_turnos', 1)
        ->assertJsonPath('data.summary.total_sistema', 1000.0)
        ->assertJsonPath('data.summary.total_declarado', 980.0)
        ->assertJsonPath('data.summary.total_falta', 20.0)
        ->assertJsonPath('data.turnos.0.pagamentos.sistema.0.total', 1000.0)
        ->assertJsonPath('data.turnos.0.pagamentos.declarado.0.total', 980.0);
});

test('pdv reports vendas applies canal filter and supports pagination', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 100,
        'canal' => 'HIPER_CAIXA',
        'total' => 100.0,
        'data_hora' => now()->subDays(1)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 200,
        'canal' => 'HIPER_LOJA',
        'total' => 200.0,
        'data_hora' => now()->subHours(20)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 300,
        'canal' => 'HIPER_CAIXA',
        'total' => 300.0,
        'data_hora' => now()->subHours(10)->toDateTimeString(),
    ]);

    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 100, 'line_id' => 100001, 'total' => 100.0]);
    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 200, 'line_id' => 100002, 'total' => 200.0]);
    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 300, 'line_id' => 100003, 'total' => 300.0]);

    seedPdvVendaPagamento(['store_id' => $store->id, 'id_operacao' => 100, 'line_id' => 200001, 'valor' => 100.0]);
    seedPdvVendaPagamento(['store_id' => $store->id, 'id_operacao' => 200, 'line_id' => 200002, 'valor' => 200.0]);
    seedPdvVendaPagamento(['store_id' => $store->id, 'id_operacao' => 300, 'line_id' => 200003, 'valor' => 300.0]);

    actingAs($user)
        ->getJson(
            '/api/v1/pdv/reports/vendas?' . http_build_query([
                'store_id' => $store->id,
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
                'canal' => 'HIPER_LOJA',
                'per_page' => 1,
                'sort' => 'asc',
            ])
        )
        ->assertStatus(200)
        ->assertJsonPath('summary.total_vendas', 1)
        ->assertJsonPath('summary.total_vendido', 200.0)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('data.0.canal', 'HIPER_LOJA');

    actingAs($user)
        ->getJson(
            '/api/v1/pdv/reports/vendas?' . http_build_query([
                'store_id' => $store->id,
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
                'per_page' => 2,
                'sort' => 'asc',
            ])
        )
        ->assertStatus(200)
        ->assertJsonPath('summary.total_vendas', 3)
        ->assertJsonPath('summary.total_vendido', 600.0)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.last_page', 2);
});

test('pdv reports ranking vendedores keeps aggregation consistency and canal filter', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 100,
        'canal' => 'HIPER_CAIXA',
        'total' => 100.0,
        'data_hora' => now()->subHours(5)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 101,
        'canal' => 'HIPER_CAIXA',
        'total' => 50.0,
        'data_hora' => now()->subHours(4)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 200,
        'canal' => 'HIPER_LOJA',
        'total' => 200.0,
        'data_hora' => now()->subHours(3)->toDateTimeString(),
    ]);

    seedPdvVendaItem([
        'store_id' => $store->id,
        'id_operacao' => 100,
        'line_id' => 300001,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'qtd' => 2,
        'total' => 100.0,
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'id_operacao' => 101,
        'line_id' => 300002,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'qtd' => 1,
        'total' => 50.0,
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'id_operacao' => 200,
        'line_id' => 300003,
        'vendedor_pdv_id' => 12,
        'vendedor_nome' => 'Carlos',
        'qtd' => 3,
        'total' => 200.0,
    ]);

    actingAs($user)
        ->getJson(
            '/api/v1/pdv/reports/ranking-vendedores?' . http_build_query([
                'store_id' => $store->id,
                'mode' => 'daily',
                'reference_date' => now()->toDateString(),
                'canal' => 'HIPER_CAIXA',
            ])
        )
        ->assertStatus(200)
        ->assertJsonPath('data.summary.vendedores', 1)
        ->assertJsonPath('data.summary.total_vendido', 150.0)
        ->assertJsonPath('data.summary.qtd_vendas', 2)
        ->assertJsonPath('data.ranking.0.vendedor_id', 80)
        ->assertJsonPath('data.ranking.0.total_vendido', 150.0)
        ->assertJsonPath('data.ranking.0.qtd_vendas', 2);
});
