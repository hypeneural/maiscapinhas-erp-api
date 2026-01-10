<?php

declare(strict_types=1);

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create bio-enabled store with opening hours
    Store::factory()->create([
        'name' => 'Bio Store 1',
        'city' => 'Tijucas',
        'active' => true,
        'bio_enabled' => true,
        'opening_hours' => [
            'tz' => 'America/Sao_Paulo',
            'weekly' => [
                'mon' => [['start' => '08:30', 'end' => '20:30']],
                'tue' => [['start' => '08:30', 'end' => '20:30']],
                'wed' => [['start' => '08:30', 'end' => '20:30']],
                'thu' => [['start' => '08:30', 'end' => '20:30']],
                'fri' => [['start' => '08:30', 'end' => '20:30']],
                'sat' => [['start' => '08:30', 'end' => '20:30']],
                'sun' => [],
            ],
        ],
    ]);

    // Create non-bio store
    Store::factory()->create([
        'name' => 'Non-Bio Store',
        'city' => 'Itapema',
        'active' => true,
        'bio_enabled' => false,
    ]);

    // Create inactive bio store
    Store::factory()->create([
        'name' => 'Inactive Bio Store',
        'city' => 'Tijucas',
        'active' => false,
        'bio_enabled' => true,
    ]);
});

test('bio stores endpoint returns only bio-enabled active stores', function () {
    $response = $this->getJson('/api/v1/bio/stores');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'Bio Store 1');
});

test('bio stores endpoint does not require authentication', function () {
    $response = $this->getJson('/api/v1/bio/stores');

    $response->assertStatus(200);
});

test('bio stores endpoint returns hours_human', function () {
    $response = $this->getJson('/api/v1/bio/stores');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'city',
                'hours_human' => [
                    'timezone',
                    'is_open_now',
                    'status',
                    'status_label',
                ],
            ],
        ],
        'meta' => ['total'],
    ]);
});

test('bio stores endpoint filters by city', function () {
    Store::factory()->create([
        'name' => 'Bio Store Itapema',
        'city' => 'Itapema',
        'active' => true,
        'bio_enabled' => true,
    ]);

    $response = $this->getJson('/api/v1/bio/stores?city=Tijucas');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.city', 'Tijucas');
});

test('bio store show endpoint returns single store', function () {
    $store = Store::where('bio_enabled', true)->where('active', true)->first();

    $response = $this->getJson("/api/v1/bio/stores/{$store->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', $store->id);
    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'city',
            'hours_human',
        ],
    ]);
});

test('bio store show returns 404 for non-bio store', function () {
    $store = Store::where('bio_enabled', false)->first();

    $response = $this->getJson("/api/v1/bio/stores/{$store->id}");

    $response->assertStatus(404);
});

test('bio store show returns 404 for inactive store', function () {
    $store = Store::where('active', false)->where('bio_enabled', true)->first();

    $response = $this->getJson("/api/v1/bio/stores/{$store->id}");

    $response->assertStatus(404);
});

test('bio store excludes sensitive fields', function () {
    $response = $this->getJson('/api/v1/bio/stores');

    $response->assertStatus(200);
    $response->assertJsonMissing(['cnpj']);
    $response->assertJsonMissing(['troco_padrao']);
    $response->assertJsonMissing(['codigo']);
});
