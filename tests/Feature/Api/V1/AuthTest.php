<?php

declare(strict_types=1);

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    // Create test store and user
    $this->store = Store::create([
        'name' => 'Test Store',
        'city' => 'Test City',
        'active' => true,
    ]);

    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'active' => true,
    ]);

    StoreUser::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'role' => 'vendedor',
    ]);
});

test('login returns token with valid credentials', function () {
    $response = postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'user' => ['id', 'name', 'email'],
            ],
            'meta' => ['request_id', 'timestamp'],
        ])
        ->assertJsonPath('data.token_type', 'Bearer');
});

test('login fails with invalid credentials', function () {
    $response = postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
});

test('login fails for inactive user', function () {
    $this->user->update(['active' => false]);

    $response = postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This account is deactivated.');
});

test('me endpoint returns user with stores and roles', function () {
    $response = actingAs($this->user)->getJson('/api/v1/me');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'name', 'email', 'active'],
                'stores' => [
                    '*' => ['id', 'name', 'city', 'role'],
                ],
            ],
            'meta' => ['request_id', 'timestamp'],
        ])
        ->assertJsonPath('data.stores.0.role', 'vendedor');
});

test('me endpoint requires authentication', function () {
    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(401);
});

test('stores endpoint returns user stores', function () {
    $response = actingAs($this->user)->getJson('/api/v1/stores');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Test Store');
});

test('sales endpoint returns filtered sales', function () {
    $response = actingAs($this->user)->getJson('/api/v1/sales?store_id=' . $this->store->id);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'meta' => ['request_id', 'timestamp', 'pagination'],
        ]);
});
