<?php

declare(strict_types=1);

use App\Models\PdvSync;
use App\Models\PdvSyncPayload;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('admin pdv sync index requires admin profile', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
    ]);

    actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs')
        ->assertStatus(403);
});

test('super admin can list pdv syncs with filters', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $store = Store::factory()->create();

    PdvSync::query()->create([
        'sync_id' => 'sync-admin-list-001',
        'schema_version' => '2.0',
        'event_type' => 'mixed',
        'request_id' => 'req-admin-list-001',
        'store_pdv_id' => 10,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'ops_loja_count' => 1,
        'ops_loja_ids' => [22380],
        'snapshot_turnos_count' => 3,
        'snapshot_vendas_count' => 4,
        'status' => PdvSync::STATUS_QUEUED,
        'payload_sha256' => str_repeat('a', 64),
        'payload_bytes' => 512,
        'received_at' => now(),
        'queued_at' => now(),
    ]);

    actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs?status=queued&event_type=mixed&store_pdv_id=10&schema_version=2.0&request_id=req-admin-list-001')
        ->assertStatus(200)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.sync_id', 'sync-admin-list-001')
        ->assertJsonPath('data.0.schema_version', '2.0')
        ->assertJsonPath('data.0.event_type', 'mixed')
        ->assertJsonPath('data.0.request_id', 'req-admin-list-001')
        ->assertJsonPath('data.0.status', 'queued')
        ->assertJsonPath('data.0.ops_loja_count', 1)
        ->assertJsonPath('data.0.ops_loja_ids.0', 22380)
        ->assertJsonPath('data.0.snapshot_turnos_count', 3)
        ->assertJsonPath('data.0.snapshot_vendas_count', 4);
});

test('super admin can filter pdv syncs by gestao_db_failure risk flag', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $store = Store::factory()->create();

    PdvSync::query()->create([
        'sync_id' => 'sync-risk-gestao-001',
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'store_pdv_id' => 13,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'warnings' => ['GESTAO_DB_FAILURE: timeout'],
        'risk_flags' => ['gestao_db_failure'],
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('d', 64),
        'payload_bytes' => 300,
        'received_at' => now(),
        'queued_at' => now(),
    ]);

    PdvSync::query()->create([
        'sync_id' => 'sync-risk-other-001',
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'store_pdv_id' => 13,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(20),
        'window_to' => now()->subMinutes(10),
        'warnings' => ['OUTRO_WARNING'],
        'risk_flags' => ['store_alias_mismatch'],
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('e', 64),
        'payload_bytes' => 320,
        'received_at' => now(),
        'queued_at' => now(),
    ]);

    actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs?risk_flag=gestao_db_failure')
        ->assertStatus(200)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.sync_id', 'sync-risk-gestao-001')
        ->assertJsonPath('data.0.risk_flags.0', 'gestao_db_failure');
});

test('super admin can view pdv sync metrics including stale store tracking', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $storeHealthy = Store::factory()->create();
    $storeStale = Store::factory()->create();

    DB::table('pdv_store_mappings')->insert([
        [
            'pdv_store_id' => 10,
            'store_id' => $storeHealthy->id,
            'alias' => 'loja-10',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'pdv_store_id' => 11,
            'store_id' => $storeStale->id,
            'alias' => 'loja-11',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    PdvSync::query()->create([
        'sync_id' => 'sync-metrics-healthy',
        'schema_version' => '3.0',
        'event_type' => 'turno_closure',
        'store_pdv_id' => 10,
        'store_id' => $storeHealthy->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('b', 64),
        'payload_bytes' => 1024,
        'snapshot_turnos_count' => 10,
        'snapshot_vendas_count' => 10,
        'received_at' => now()->subMinutes(5),
        'processing_started_at' => now()->subMinutes(4),
        'processed_at' => now()->subMinutes(3),
    ]);

    PdvSync::query()->create([
        'sync_id' => 'sync-metrics-failed',
        'schema_version' => '2.0',
        'event_type' => 'sales',
        'store_pdv_id' => 10,
        'store_id' => $storeHealthy->id,
        'window_from' => now()->subMinutes(25),
        'window_to' => now()->subMinutes(15),
        'status' => PdvSync::STATUS_FAILED,
        'payload_sha256' => str_repeat('c', 64),
        'payload_bytes' => 200,
        'attempts' => 3,
        'snapshot_turnos_count' => 2,
        'snapshot_vendas_count' => 1,
        'received_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $response = actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs/metrics?minutes_without_sync=20')
        ->assertStatus(200)
        ->assertJsonPath('data.last_24h.total', 2)
        ->assertJsonPath('data.last_24h.failed', 1)
        ->assertJsonPath('data.status_breakdown.failed', 1)
        ->assertJsonPath('data.status_breakdown.processed', 1)
        ->assertJsonPath('data.last_24h.status_breakdown.failed', 1)
        ->assertJsonPath('data.by_event_type.sales', 1)
        ->assertJsonPath('data.by_event_type.turno_closure', 1)
        ->assertJsonPath('data.by_schema_version.2.0', 1)
        ->assertJsonPath('data.by_schema_version.3.0', 1)
        ->assertJsonPath('data.by_canal.totals.HIPER_CAIXA', 0)
        ->assertJsonPath('data.by_canal.totals.HIPER_LOJA', 0)
        ->assertJsonPath('data.snapshots.available', true)
        ->assertJsonPath('data.snapshots.turnos_processed_total', 12)
        ->assertJsonPath('data.snapshots.vendas_processed_total', 11)
        ->assertJsonPath('data.stores.max_stale_stores', 0)
        ->assertJsonPath('data.risk_flags.gestao_db_failure', 0)
        ->assertJsonPath('data.risk_flags.vendedor_null', 0)
        ->assertJsonPath('data.risk_flags.meio_pagamento_null', 0)
        ->json('data');

    expect($response['stores']['stale_count'])->toBeGreaterThanOrEqual(1);
    assertDatabaseHas('pdv_store_mappings', [
        'pdv_store_id' => 11,
    ]);
});

test('super admin can list debug syncs filtered by payload content', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $store = Store::factory()->create([
        'name' => 'Loja Debug Teste',
    ]);

    $targetGuid = 'CBFA4E39-C3DB-45CF-8B9B-A9A6B6574227';

    $syncMatch = PdvSync::query()->create([
        'sync_id' => 'sync-debug-match-001',
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'request_id' => 'req-debug-match-001',
        'store_pdv_id' => 77,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('1', 64),
        'payload_bytes' => 2048,
        'received_at' => now()->subMinute(),
        'processing_started_at' => now()->subSeconds(40),
        'processed_at' => now()->subSeconds(20),
    ]);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $syncMatch->id,
        'payload' => json_encode([
            'store' => ['LojaId' => $targetGuid],
            'integrity' => ['sync_id' => 'sync-debug-match-001'],
        ], JSON_THROW_ON_ERROR),
        'compression' => 'none',
    ]);

    $syncOther = PdvSync::query()->create([
        'sync_id' => 'sync-debug-other-001',
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'request_id' => 'req-debug-other-001',
        'store_pdv_id' => 88,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(20),
        'window_to' => now()->subMinutes(15),
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('2', 64),
        'payload_bytes' => 512,
        'received_at' => now()->subMinutes(15),
    ]);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $syncOther->id,
        'payload' => json_encode([
            'store' => ['LojaId' => 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'],
        ], JSON_THROW_ON_ERROR),
        'compression' => 'none',
    ]);

    actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs/debug?payload_contains=' . $targetGuid)
        ->assertStatus(200)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.sync_id', 'sync-debug-match-001')
        ->assertJsonPath('data.0.store.pdv_id', 77)
        ->assertJsonPath('data.0.payload.available', true)
        ->assertJsonPath('data.0.payload.compression', 'none');
});

test('super admin can view debug payload detail', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $store = Store::factory()->create();

    $sync = PdvSync::query()->create([
        'sync_id' => 'sync-debug-detail-001',
        'schema_version' => '3.0',
        'event_type' => 'mixed',
        'request_id' => 'req-debug-detail-001',
        'store_pdv_id' => 90,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(20),
        'window_to' => now()->subMinutes(10),
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('3', 64),
        'payload_bytes' => 1024,
        'received_at' => now()->subMinutes(9),
        'processing_started_at' => now()->subMinutes(8),
        'processed_at' => now()->subMinutes(7),
    ]);

    $rawPayload = json_encode([
        'store' => ['LojaId' => 'ABC-123'],
        'integrity' => ['sync_id' => 'sync-debug-detail-001'],
    ], JSON_THROW_ON_ERROR);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $sync->id,
        'payload' => $rawPayload,
        'compression' => 'none',
    ]);

    actingAs($user)
        ->getJson("/api/v1/admin/pdv/syncs/{$sync->id}/debug")
        ->assertStatus(200)
        ->assertJsonPath('data.sync.sync_id', 'sync-debug-detail-001')
        ->assertJsonPath('data.payload.available', true)
        ->assertJsonPath('data.payload.raw', $rawPayload)
        ->assertJsonPath('data.payload.decoded.store.LojaId', 'ABC-123')
        ->assertJsonPath('data.payload.parse_error', null);
});

test('super admin can list debug filter options', function () {
    $user = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $store = Store::factory()->create([
        'name' => 'Loja Filtros Debug',
    ]);

    $sync = PdvSync::query()->create([
        'sync_id' => 'sync-debug-filters-001',
        'schema_version' => '3.1',
        'event_type' => 'turno_closure',
        'request_id' => 'req-debug-filters-001',
        'store_pdv_id' => 155,
        'store_id' => $store->id,
        'window_from' => now()->subHours(2),
        'window_to' => now()->subHour(),
        'status' => PdvSync::STATUS_FAILED,
        'payload_sha256' => str_repeat('4', 64),
        'payload_bytes' => 3000,
        'received_at' => now()->subHour(),
    ]);

    PdvSyncPayload::query()->create([
        'pdv_sync_id' => $sync->id,
        'payload' => '{"debug":true}',
        'compression' => 'none',
    ]);

    $data = actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs/debug/filters')
        ->assertStatus(200)
        ->json('data');

    expect($data['statuses'])->toContain(PdvSync::STATUS_FAILED);
    expect($data['event_types'])->toContain(PdvSync::EVENT_TYPE_TURNO_CLOSURE);
    expect($data['schema_versions'])->toContain('3.1');
    expect(collect($data['stores'])->pluck('store_pdv_id')->all())->toContain(155);
});
