<?php

declare(strict_types=1);

use App\Models\CapaPersonalizada;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Storage::fake('public');

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

    $this->customer = Customer::create([
        'name' => 'Test Customer',
        'email' => 'customer@test.com',
    ]);

    $this->capa = CapaPersonalizada::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'customer_id' => $this->customer->id,
        'selected_product' => 'Capa iPhone 15',
        'qty' => 1,
        'price' => 49.90,
        'status' => 1,
        'created_by_id' => $this->user->id,
    ]);
});

// ========================================
// Token Generation Tests
// ========================================

test('token generation requires authentication', function () {
    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/gerar-token-upload");

    $response->assertStatus(401);
});

test('token generation returns valid token', function () {
    $response = actingAs($this->user)->postJson(
        "/api/v1/capas-personalizadas/{$this->capa->id}/gerar-token-upload"
    );

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'data' => [
                'token',
                'expires_at',
                'upload_url',
            ],
        ]);

    $this->capa->refresh();
    expect($this->capa->upload_token)->not->toBeNull();
    expect($this->capa->upload_token_expires_at)->not->toBeNull();
    expect($this->capa->upload_token_expires_at->isFuture())->toBeTrue();
});

// ========================================
// Public Upload Tests
// ========================================

test('public upload fails without token', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('public upload fails without photo', function () {
    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'token' => 'some-token',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['photo']);
});

test('public upload fails with invalid token', function () {
    // Generate a valid token first
    $this->capa->update([
        'upload_token' => 'valid-token',
        'upload_token_expires_at' => now()->addMinutes(5),
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
        'token' => 'invalid-token',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Token inválido ou expirado.');
});

test('public upload fails with expired token', function () {
    // Generate an expired token
    $this->capa->update([
        'upload_token' => 'expired-token',
        'upload_token_expires_at' => now()->subMinutes(1),
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
        'token' => 'expired-token',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Token inválido ou expirado.');
});

test('public upload fails if capa not found', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson('/api/v1/capas-personalizadas/99999/upload-publico', [
        'photo' => $file,
        'token' => 'some-token',
    ]);

    $response->assertStatus(404)
        ->assertJsonPath('message', 'Capa personalizada não encontrada.');
});

test('public upload fails if capa already has photo', function () {
    // Generate valid token and set existing photo
    $this->capa->update([
        'upload_token' => 'valid-token',
        'upload_token_expires_at' => now()->addMinutes(5),
        'photo_path' => 'existing-photo.jpg',
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
        'token' => 'valid-token',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('message', 'Esta capa já possui uma foto.');
});

test('public upload succeeds with valid token', function () {
    // Generate valid token
    $this->capa->update([
        'upload_token' => 'valid-token',
        'upload_token_expires_at' => now()->addMinutes(5),
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
        'token' => 'valid-token',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Foto enviada com sucesso.')
        ->assertJsonStructure([
            'message',
            'data' => [
                'photo_path',
                'photo_url',
                'size',
                'mime',
            ],
        ]);

    // Verify photo was saved
    $this->capa->refresh();
    expect($this->capa->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($this->capa->photo_path);

    // Verify token was cleared
    expect($this->capa->upload_token)->toBeNull();
    expect($this->capa->upload_token_expires_at)->toBeNull();
});

test('full flow: generate token and upload photo', function () {
    // Step 1: Generate token (authenticated)
    $tokenResponse = actingAs($this->user)->postJson(
        "/api/v1/capas-personalizadas/{$this->capa->id}/gerar-token-upload"
    );

    $tokenResponse->assertStatus(200);
    $token = $tokenResponse->json('data.token');

    // Step 2: Upload photo with token (unauthenticated)
    $file = UploadedFile::fake()->image('customer-photo.jpg');

    $uploadResponse = postJson("/api/v1/capas-personalizadas/{$this->capa->id}/upload-publico", [
        'photo' => $file,
        'token' => $token,
    ]);

    $uploadResponse->assertStatus(200)
        ->assertJsonPath('message', 'Foto enviada com sucesso.');

    // Verify state
    $this->capa->refresh();
    expect($this->capa->photo_path)->not->toBeNull();
    expect($this->capa->upload_token)->toBeNull();
});
