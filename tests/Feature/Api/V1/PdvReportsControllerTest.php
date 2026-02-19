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
    $canal = (string) ($overrides['canal']
        ?? DB::table('pdv_vendas')
            ->where('store_pdv_id', $storePdvId)
            ->where('id_operacao', $idOperacao)
            ->value('canal')
        ?? 'HIPER_CAIXA');

    $row = array_merge([
        'store_pdv_id' => $storePdvId,
        'store_id' => $overrides['store_id'] ?? null,
        'canal' => $canal,
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
    $canal = (string) ($overrides['canal']
        ?? DB::table('pdv_vendas')
            ->where('store_pdv_id', $storePdvId)
            ->where('id_operacao', $idOperacao)
            ->value('canal')
        ?? 'HIPER_CAIXA');

    $row = array_merge([
        'store_pdv_id' => $storePdvId,
        'store_id' => $overrides['store_id'] ?? null,
        'canal' => $canal,
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

test('pdv reports turnos returns 422 when store_pdv_id is ambiguous without store_alias or store_id', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    linkUserToStore($user, $storeA, 'admin');
    linkUserToStore($user, $storeB, 'admin');
    mapPdvStore(9, $storeA, 'Loja 8 - MC Mata Atlântica');
    mapPdvStore(9, $storeB, 'Loja 10 - MC P4');

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?store_pdv_id=9&date=' . now()->toDateString())
        ->assertStatus(422)
        ->assertJsonPath('errors.store_pdv_id.0', 'store_pdv_id ambiguo. Informe store_id ou store_alias para desambiguar.');
});

test('pdv reports turnos resolves ambiguous store_pdv_id with store_alias', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    linkUserToStore($user, $storeA, 'admin');
    linkUserToStore($user, $storeB, 'admin');
    mapPdvStore(9, $storeA, 'Loja 8 - MC Mata Atlântica');
    mapPdvStore(9, $storeB, 'Loja 10 - MC P4');

    seedPdvTurno([
        'store_pdv_id' => 9,
        'store_id' => $storeB->id,
        'id_turno' => 'turno-ambiguous-alias-001',
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?' . http_build_query([
            'store_pdv_id' => 9,
            'store_alias' => 'Loja 10 - MC P4',
            'date' => now()->toDateString(),
        ]))
        ->assertStatus(200)
        ->assertJsonPath('data.summary.qtd_turnos', 1)
        ->assertJsonPath('data.filters.store_alias', 'Loja 10 - MC P4')
        ->assertJsonPath('data.turnos.0.store_id', $storeB->id);
});

test('pdv reports turnos returns empty dataset when store has no turnos for selected date', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?store_id=' . $store->id . '&date=' . now()->toDateString())
        ->assertStatus(200)
        ->assertJsonPath('data.summary.qtd_turnos', 0)
        ->assertJsonPath('data.summary.qtd_turnos_fechados', 0)
        ->assertJsonPath('data.summary.qtd_turnos_falta', 0)
        ->assertJsonPath('data.summary.qtd_turnos_sobra', 0)
        ->assertJsonPath('data.summary.qtd_turnos_conferido', 0)
        ->assertJsonPath('data.summary.total_sistema', 0.0)
        ->assertJsonPath('data.summary.total_declarado', 0.0)
        ->assertJsonPath('data.summary.total_falta', 0.0)
        ->assertJsonPath('data.summary.total_falta_absoluto', 0.0)
        ->assertJsonCount(0, 'data.turnos');
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

test('pdv reports turnos applies fechado, operador_id and responsavel_id filters', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    $today = now()->toDateString();

    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-filter-001',
        'sequencial' => 1,
        'fechado' => true,
        'operador_pdv_id' => 12,
        'operador_nome' => 'Carlos',
        'responsavel_pdv_id' => 80,
        'responsavel_nome' => 'Daren',
        'data_hora_inicio' => now()->setTime(8, 0, 0)->toDateTimeString(),
    ]);
    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-filter-002',
        'sequencial' => 2,
        'fechado' => false,
        'operador_pdv_id' => 34,
        'operador_nome' => 'Maria',
        'responsavel_pdv_id' => 91,
        'responsavel_nome' => 'Joao',
        'data_hora_inicio' => now()->setTime(10, 0, 0)->toDateTimeString(),
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?' . http_build_query([
            'store_id' => $store->id,
            'date' => $today,
            'fechado' => 1,
            'operador_id' => 12,
            'responsavel_id' => 80,
        ]))
        ->assertStatus(200)
        ->assertJsonPath('data.summary.qtd_turnos', 1)
        ->assertJsonPath('data.turnos.0.id_turno', 'turno-filter-001')
        ->assertJsonPath('data.turnos.0.fechado', true)
        ->assertJsonPath('data.filters.fechado', true)
        ->assertJsonPath('data.filters.operador_id', 12)
        ->assertJsonPath('data.filters.responsavel_id', 80);
});

test('pdv reports turnos classifies falta_caixa as falta, sobra or conferido', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-falta-001',
        'sequencial' => 1,
        'total_falta' => 20.00,
    ]);
    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-falta-002',
        'sequencial' => 2,
        'total_falta' => -15.50,
    ]);
    seedPdvTurno([
        'store_id' => $store->id,
        'id_turno' => 'turno-falta-003',
        'sequencial' => 3,
        'total_falta' => 0.00,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/turnos?store_id=' . $store->id . '&date=' . now()->toDateString())
        ->assertStatus(200)
        ->assertJsonPath('data.turnos.0.totais.falta_caixa_tipo', 'FALTA')
        ->assertJsonPath('data.turnos.0.totais.falta_caixa_valor_absoluto', 20.0)
        ->assertJsonPath('data.turnos.1.totais.falta_caixa_tipo', 'SOBRA')
        ->assertJsonPath('data.turnos.1.totais.falta_caixa_valor_absoluto', 15.5)
        ->assertJsonPath('data.turnos.2.totais.falta_caixa_tipo', 'CONFERIDO')
        ->assertJsonPath('data.turnos.2.totais.falta_caixa_valor_absoluto', 0.0)
        ->assertJsonPath('data.summary.qtd_turnos_falta', 1)
        ->assertJsonPath('data.summary.qtd_turnos_sobra', 1)
        ->assertJsonPath('data.summary.qtd_turnos_conferido', 1)
        ->assertJsonPath('data.summary.total_falta_absoluto', 35.5);
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

test('pdv reports vendas applies payment filters id_finalizador and meio_pagamento', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 401,
        'canal' => 'HIPER_CAIXA',
        'total' => 120.0,
        'data_hora' => now()->subHours(6)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 402,
        'canal' => 'HIPER_CAIXA',
        'total' => 180.0,
        'data_hora' => now()->subHours(5)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_id' => $store->id,
        'id_operacao' => 403,
        'canal' => 'HIPER_LOJA',
        'total' => 220.0,
        'data_hora' => now()->subHours(4)->toDateTimeString(),
    ]);

    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 401, 'line_id' => 401001, 'total' => 120.0]);
    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 402, 'line_id' => 402001, 'total' => 180.0]);
    seedPdvVendaItem(['store_id' => $store->id, 'id_operacao' => 403, 'line_id' => 403001, 'total' => 220.0]);

    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'id_operacao' => 401,
        'line_id' => 501001,
        'id_finalizador' => 5,
        'meio_pagamento' => 'Pix',
        'valor' => 120.0,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'id_operacao' => 402,
        'line_id' => 502001,
        'id_finalizador' => 4,
        'meio_pagamento' => 'Cartao de Credito',
        'valor' => 180.0,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'id_operacao' => 403,
        'line_id' => 503001,
        'id_finalizador' => 5,
        'meio_pagamento' => 'PIX',
        'valor' => 220.0,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas?' . http_build_query([
            'store_id' => $store->id,
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'id_finalizador' => 5,
            'canal' => 'HIPER_CAIXA',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.total_vendas', 1)
        ->assertJsonPath('data.0.id_operacao', 401)
        ->assertJsonPath('data.0.canal', 'HIPER_CAIXA')
        ->assertJsonPath('filters.id_finalizador', 5);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas?' . http_build_query([
            'store_id' => $store->id,
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'meio_pagamento' => 'pix',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.total_vendas', 2)
        ->assertJsonPath('filters.meio_pagamento', 'pix');
});

test('pdv reports vendas accepts store uuid in store_id filter', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeGuid = '4dcbc02b-f765-4f2e-9ceb-ef8c14b40f80';
    $store = Store::factory()->create(['guid' => $storeGuid]);
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'canal' => 'HIPER_CAIXA',
        'total' => 110.0,
        'data_hora' => now()->subMinutes(10)->toDateTimeString(),
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13620001,
        'total' => 110.0,
        'qtd' => 1.0,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13621001,
        'valor' => 110.0,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas?' . http_build_query([
            'store_id' => $storeGuid,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'sort' => 'desc',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.total_vendas', 1)
        ->assertJsonPath('data.0.id_operacao', 13620)
        ->assertJsonPath('data.0.store_id', $store->id)
        ->assertJsonPath('filters.store_id', $store->id);
});

test('pdv reports vendas detalhe returns venda with itens and pagamentos ordered by line_no', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $seller = User::factory()->create();
    $store = Store::factory()->create();
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 777,
        'canal' => 'HIPER_CAIXA',
        'total' => 100.0,
        'data_hora' => now()->subMinutes(2)->toDateTimeString(),
    ]);

    seedPdvVendaItem([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 777,
        'line_id' => 777002,
        'line_no' => 2,
        'total' => 60.0,
        'qtd' => 1.0,
        'preco_unit' => 60.0,
        'desconto' => 0.0,
        'vendedor_user_id' => $seller->id,
        'vendedor_login' => 'daren',
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 777,
        'line_id' => 777001,
        'line_no' => 1,
        'total' => 40.0,
        'qtd' => 1.0,
        'preco_unit' => 40.0,
        'desconto' => 0.0,
        'vendedor_user_id' => $seller->id,
        'vendedor_login' => 'daren',
    ]);

    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 777,
        'line_id' => 888002,
        'line_no' => 2,
        'id_finalizador' => 4,
        'meio_pagamento' => 'Cartao de Credito',
        'valor' => 60.0,
        'troco' => 0.0,
        'parcelas' => 1,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 777,
        'line_id' => 888001,
        'line_no' => 1,
        'id_finalizador' => 1,
        'meio_pagamento' => 'Dinheiro',
        'valor' => 40.0,
        'troco' => 0.0,
        'parcelas' => 1,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas/detalhe?' . http_build_query([
            'store_id' => $store->id,
            'store_pdv_id' => 13,
            'canal' => 'HIPER_CAIXA',
            'id_operacao' => 777,
        ]))
        ->assertStatus(200)
        ->assertJsonPath('data.venda.store_id', $store->id)
        ->assertJsonPath('data.venda.store_pdv_id', 13)
        ->assertJsonPath('data.venda.canal', 'HIPER_CAIXA')
        ->assertJsonPath('data.venda.id_operacao', 777)
        ->assertJsonPath('data.itens.0.line_no', 1)
        ->assertJsonPath('data.itens.1.line_no', 2)
        ->assertJsonPath('data.itens.0.vendedor_login', 'daren')
        ->assertJsonPath('data.pagamentos.0.line_no', 1)
        ->assertJsonPath('data.pagamentos.1.line_no', 2)
        ->assertJsonPath('data.summary.itens.valor_total', 100.0)
        ->assertJsonPath('data.summary.pagamentos.valor_total', 100.0);
});

test('pdv reports vendas detalhe accepts store uuid in store_id filter', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeGuid = '4dcbc02b-f765-4f2e-9ceb-ef8c14b40f80';
    $store = Store::factory()->create(['guid' => $storeGuid]);
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'canal' => 'HIPER_CAIXA',
        'total' => 90.0,
        'data_hora' => now()->subMinutes(8)->toDateTimeString(),
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13622001,
        'line_no' => 1,
        'total' => 90.0,
        'qtd' => 1.0,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13623001,
        'line_no' => 1,
        'valor' => 90.0,
        'troco' => 0.0,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas/detalhe?' . http_build_query([
            'store_id' => $storeGuid,
            'canal' => 'HIPER_CAIXA',
            'id_operacao' => 13620,
        ]))
        ->assertStatus(200)
        ->assertJsonPath('data.venda.store_id', $store->id)
        ->assertJsonPath('data.venda.id_operacao', 13620)
        ->assertJsonPath('data.filters.store_id', $store->id);
});

test('pdv reports vendas detalhe returns 422 when store_pdv_id is ambiguous without store_alias or store_id', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    linkUserToStore($user, $storeA, 'admin');
    linkUserToStore($user, $storeB, 'admin');
    mapPdvStore(9, $storeA, 'Loja 8 - MC Mata AtlÃ¢ntica');
    mapPdvStore(9, $storeB, 'Loja 10 - MC P4');

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/vendas/detalhe?' . http_build_query([
            'store_pdv_id' => 9,
            'canal' => 'HIPER_CAIXA',
            'id_operacao' => 123,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.store_pdv_id.0', 'store_pdv_id ambiguo. Informe store_id ou store_alias para desambiguar.');
});

test('pdv reports operacoes accepts store uuid in store_id filter', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeGuid = '4dcbc02b-f765-4f2e-9ceb-ef8c14b40f80';
    $store = Store::factory()->create(['guid' => $storeGuid]);
    linkUserToStore($user, $store, 'admin');
    mapPdvStore(13, $store);

    seedPdvVenda([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'canal' => 'HIPER_CAIXA',
        'total' => 150.0,
        'data_hora' => now()->subMinutes(5)->toDateTimeString(),
    ]);
    seedPdvVendaItem([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13624001,
        'total' => 150.0,
        'qtd' => 1.0,
    ]);
    seedPdvVendaPagamento([
        'store_id' => $store->id,
        'store_pdv_id' => 13,
        'id_operacao' => 13620,
        'line_id' => 13625001,
        'valor' => 150.0,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/operacoes?' . http_build_query([
            'store_id' => $storeGuid,
            'tipo_operacao' => 'venda',
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'sort' => 'desc',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.total_operacoes', 1)
        ->assertJsonPath('summary.total_vendas', 1)
        ->assertJsonPath('data.0.operacao_id', 13620)
        ->assertJsonPath('data.0.store_id', $store->id)
        ->assertJsonPath('filters.store_id', $store->id);
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

test('pdv reports ranking vendedor x loja returns grouped rows with filters', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    linkUserToStore($user, $storeA, 'admin');
    linkUserToStore($user, $storeB, 'admin');
    mapPdvStore(13, $storeA);
    mapPdvStore(14, $storeB);

    seedPdvVenda([
        'store_pdv_id' => 13,
        'store_id' => $storeA->id,
        'id_operacao' => 901,
        'canal' => 'HIPER_CAIXA',
        'total' => 100.0,
        'data_hora' => now()->subHours(8)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 902,
        'canal' => 'HIPER_CAIXA',
        'total' => 150.0,
        'data_hora' => now()->subHours(7)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 903,
        'canal' => 'HIPER_LOJA',
        'total' => 200.0,
        'data_hora' => now()->subHours(6)->toDateTimeString(),
    ]);

    seedPdvVendaItem([
        'store_pdv_id' => 13,
        'store_id' => $storeA->id,
        'id_operacao' => 901,
        'line_id' => 901001,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'total' => 100.0,
        'qtd' => 1,
    ]);
    seedPdvVendaItem([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 902,
        'line_id' => 902001,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'total' => 150.0,
        'qtd' => 2,
    ]);
    seedPdvVendaItem([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 903,
        'line_id' => 903001,
        'vendedor_pdv_id' => 12,
        'vendedor_nome' => 'Carlos',
        'total' => 200.0,
        'qtd' => 3,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/ranking-vendedor-loja?' . http_build_query([
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'canal' => 'HIPER_CAIXA',
            'sort_by' => 'total_vendido',
            'sort' => 'desc',
            'per_page' => 50,
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.linhas', 2)
        ->assertJsonPath('summary.total_vendido', 250.0)
        ->assertJsonPath('summary.qtd_vendas', 2)
        ->assertJsonPath('data.0.store_pdv_id', 14)
        ->assertJsonPath('data.0.vendedor_id', 80)
        ->assertJsonPath('data.0.total_vendido', 150.0);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/ranking-vendedor-loja?' . http_build_query([
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'vendedor_id' => 80,
            'sort_by' => 'total_vendido',
            'sort' => 'desc',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.linhas', 2)
        ->assertJsonPath('summary.total_vendido', 250.0)
        ->assertJsonPath('filters.vendedor_id', 80);
});

test('pdv reports ranking vendedor x loja supports specific store filter', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    linkUserToStore($user, $storeA, 'admin');
    linkUserToStore($user, $storeB, 'admin');
    mapPdvStore(13, $storeA);
    mapPdvStore(14, $storeB);

    seedPdvVenda([
        'store_pdv_id' => 13,
        'store_id' => $storeA->id,
        'id_operacao' => 910,
        'canal' => 'HIPER_CAIXA',
        'total' => 100.0,
        'data_hora' => now()->subHours(8)->toDateTimeString(),
    ]);
    seedPdvVenda([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 911,
        'canal' => 'HIPER_CAIXA',
        'total' => 150.0,
        'data_hora' => now()->subHours(7)->toDateTimeString(),
    ]);

    seedPdvVendaItem([
        'store_pdv_id' => 13,
        'store_id' => $storeA->id,
        'id_operacao' => 910,
        'line_id' => 910001,
        'vendedor_pdv_id' => 80,
        'vendedor_nome' => 'Daren',
        'total' => 100.0,
        'qtd' => 1,
    ]);
    seedPdvVendaItem([
        'store_pdv_id' => 14,
        'store_id' => $storeB->id,
        'id_operacao' => 911,
        'line_id' => 911001,
        'vendedor_pdv_id' => 12,
        'vendedor_nome' => 'Carlos',
        'total' => 150.0,
        'qtd' => 2,
    ]);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/ranking-vendedor-loja?' . http_build_query([
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'store_id' => $storeB->id,
            'sort_by' => 'total_vendido',
            'sort' => 'desc',
        ]))
        ->assertStatus(200)
        ->assertJsonPath('summary.linhas', 1)
        ->assertJsonPath('summary.total_vendido', 150.0)
        ->assertJsonPath('data.0.store_id', $storeB->id)
        ->assertJsonPath('data.0.store_pdv_id', 14)
        ->assertJsonPath('filters.store_id', $storeB->id);
});

test('pdv reports ranking vendedor x loja enforces store authorization', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $storeAllowed = Store::factory()->create();
    $storeBlocked = Store::factory()->create();

    linkUserToStore($user, $storeAllowed, 'admin');
    mapPdvStore(13, $storeAllowed);
    mapPdvStore(14, $storeBlocked);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/ranking-vendedor-loja?' . http_build_query([
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'store_id' => $storeBlocked->id,
        ]))
        ->assertStatus(403);

    actingAs($user)
        ->getJson('/api/v1/pdv/reports/ranking-vendedor-loja?' . http_build_query([
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
            'store_pdv_id' => 14,
        ]))
        ->assertStatus(403);
});
