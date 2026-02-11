<?php

declare(strict_types=1);

use App\Models\PdvSync;
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
        'request_id' => 'req-admin-list-001',
        'store_pdv_id' => 10,
        'store_id' => $store->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'status' => PdvSync::STATUS_QUEUED,
        'payload_sha256' => str_repeat('a', 64),
        'payload_bytes' => 512,
        'received_at' => now(),
        'queued_at' => now(),
    ]);

    actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs?status=queued&store_pdv_id=10&schema_version=2.0&request_id=req-admin-list-001')
        ->assertStatus(200)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.sync_id', 'sync-admin-list-001')
        ->assertJsonPath('data.0.schema_version', '2.0')
        ->assertJsonPath('data.0.request_id', 'req-admin-list-001')
        ->assertJsonPath('data.0.status', 'queued');
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
        'store_pdv_id' => 10,
        'store_id' => $storeHealthy->id,
        'window_from' => now()->subMinutes(10),
        'window_to' => now(),
        'status' => PdvSync::STATUS_PROCESSED,
        'payload_sha256' => str_repeat('b', 64),
        'payload_bytes' => 1024,
        'received_at' => now()->subMinutes(5),
        'processing_started_at' => now()->subMinutes(4),
        'processed_at' => now()->subMinutes(3),
    ]);

    PdvSync::query()->create([
        'sync_id' => 'sync-metrics-failed',
        'store_pdv_id' => 10,
        'store_id' => $storeHealthy->id,
        'window_from' => now()->subMinutes(25),
        'window_to' => now()->subMinutes(15),
        'status' => PdvSync::STATUS_FAILED,
        'payload_sha256' => str_repeat('c', 64),
        'payload_bytes' => 200,
        'attempts' => 3,
        'received_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $response = actingAs($user)
        ->getJson('/api/v1/admin/pdv/syncs/metrics?minutes_without_sync=20')
        ->assertStatus(200)
        ->assertJsonPath('data.last_24h.total', 2)
        ->assertJsonPath('data.last_24h.failed', 1)
        ->json('data');

    expect($response['stores']['stale_count'])->toBeGreaterThanOrEqual(1);
    assertDatabaseHas('pdv_store_mappings', [
        'pdv_store_id' => 11,
    ]);
});
