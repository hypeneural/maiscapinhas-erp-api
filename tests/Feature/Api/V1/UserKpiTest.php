<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    // Create an authenticated user
    $this->user = User::create([
        'name' => 'Auth User',
        'email' => 'auth@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'is_super_admin' => false,
    ]);

    // Create test users with various data combinations
    User::create([
        'name' => 'User Active With Data',
        'email' => 'user1@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'city' => 'Itapema',
        'state' => 'SC',
        'birth_date' => '1990-05-15',
        'hire_date' => '2022-01-10',
    ]);

    User::create([
        'name' => 'User Active SC No Birth',
        'email' => 'user2@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'city' => 'Tijucas',
        'state' => 'SC',
        'birth_date' => null,
        'hire_date' => '2023-06-01',
    ]);

    User::create([
        'name' => 'User Active SP',
        'email' => 'user3@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'city' => 'São Paulo',
        'state' => 'SP',
        'birth_date' => '1985-12-20',
        'hire_date' => '2020-03-15',
    ]);

    User::create([
        'name' => 'User Inactive',
        'email' => 'user4@example.com',
        'password' => bcrypt('password'),
        'active' => false,
        'city' => 'Itapema',
        'state' => 'SC',
        'birth_date' => '1995-08-10',
        'hire_date' => '2021-09-01',
    ]);

    User::create([
        'name' => 'User No City',
        'email' => 'user5@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'city' => null,
        'state' => null,
        'birth_date' => '2000-01-01',
        'hire_date' => null,
    ]);

    User::create([
        'name' => 'User Empty City',
        'email' => 'user6@example.com',
        'password' => bcrypt('password'),
        'active' => true,
        'city' => '',
        'state' => 'SC',
        'birth_date' => null,
        'hire_date' => '2024-01-01',
    ]);
});

test('kpis endpoint requires authentication', function () {
    $response = getJson('/api/v1/users/kpis');

    $response->assertStatus(401);
});

test('kpis endpoint returns 200 with default active filter', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'filters' => ['active', 'state', 'city', 'date_from', 'date_to'],
        'totals' => [
            'users_total',
            'active_total',
            'inactive_total',
            'with_birth_date_total',
            'with_hire_date_total',
            'without_city_total',
        ],
        'age' => [
            'avg_age_years',
            'youngest_age_years',
            'youngest_birth_date',
            'oldest_age_years',
            'oldest_birth_date',
            'age_population_total',
        ],
        'tenure' => [
            'avg_tenure_days',
            'avg_tenure_months',
            'longest_tenure_days',
            'longest_hire_date',
            'newest_tenure_days',
            'newest_hire_date',
            'tenure_population_total',
        ],
        'distribution' => [
            'cities_total_distinct',
            'top_city',
            'by_city',
        ],
    ]);

    // Default filter should be active = 1
    $response->assertJsonPath('filters.active', 1);
});

test('kpis endpoint counts only active users by default', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    // We have 6 active users (including auth user) + 1 inactive
    // Default filter shows only active, so inactive_total relates to filter context
    $data = $response->json();
    expect($data['totals']['users_total'])->toBeGreaterThan(0);
    // When filtering active=1, inactive_total should be 0 in context
    expect($data['totals']['inactive_total'])->toBe(0);
});

test('kpis endpoint with active=all returns all users', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?active=all');

    $response->assertStatus(200);
    $response->assertJsonPath('filters.active', 'all');

    $data = $response->json();
    expect($data['totals']['inactive_total'])->toBeGreaterThan(0);
});

test('kpis endpoint filters by state', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?state=SC');

    $response->assertStatus(200);
    $response->assertJsonPath('filters.state', 'SC');

    $data = $response->json();
    // Should have users in SC
    expect($data['totals']['users_total'])->toBeGreaterThanOrEqual(2);
});

test('kpis endpoint filters by city', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?city=Itapema');

    $response->assertStatus(200);
    $response->assertJsonPath('filters.city', 'Itapema');

    $data = $response->json();
    expect($data['totals']['users_total'])->toBe(1); // Only one active user in Itapema
});

test('kpis endpoint returns null age fields when no birth dates', function () {
    // Update all users to have no birth_date
    User::query()->update(['birth_date' => null]);

    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['age']['avg_age_years'])->toBeNull();
    expect($data['age']['youngest_age_years'])->toBeNull();
    expect($data['age']['oldest_age_years'])->toBeNull();
    expect($data['age']['age_population_total'])->toBe(0);
});

test('kpis endpoint returns null tenure fields when no hire dates', function () {
    // Update all users to have no hire_date
    User::query()->update(['hire_date' => null]);

    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['tenure']['avg_tenure_days'])->toBeNull();
    expect($data['tenure']['longest_tenure_days'])->toBeNull();
    expect($data['tenure']['newest_tenure_days'])->toBeNull();
    expect($data['tenure']['tenure_population_total'])->toBe(0);
});

test('kpis endpoint normalizes empty city as sem cidade', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();
    $cities = collect($data['distribution']['by_city'])->pluck('city')->toArray();

    // Should have "(Sem cidade)" for users with null or empty city
    expect($cities)->toContain('(Sem cidade)');
});

test('kpis endpoint distribution percentages sum approximately to 100', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();
    $totalPct = collect($data['distribution']['by_city'])->sum('pct');

    // Allow for small rounding errors
    expect($totalPct)->toBeGreaterThanOrEqual(99.9);
    expect($totalPct)->toBeLessThanOrEqual(100.1);
});

test('kpis endpoint does not expose personal data', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $content = $response->getContent();

    // Should not contain personal identifiable field keys at root level
    expect($content)->not->toContain('"name":');
    expect($content)->not->toContain('"email":');
    expect($content)->not->toContain('"cpf":');
    expect($content)->not->toContain('"whatsapp":');
    expect($content)->not->toContain('"pix_key":');
    expect($content)->not->toContain('"instagram":');
});

test('kpis endpoint validates active parameter', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?active=invalid');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['active']);
});

test('kpis endpoint validates state size', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?state=INVALID');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['state']);
});

test('kpis endpoint validates date range', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?date_from=2025-12-31&date_to=2025-01-01');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['date_to']);
});

test('kpis endpoint filters by date range', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis?date_from=2020-01-01&date_to=2030-12-31');

    $response->assertStatus(200);
    $response->assertJsonPath('filters.date_from', '2020-01-01');
    $response->assertJsonPath('filters.date_to', '2030-12-31');
});

test('kpis endpoint returns correct distribution order', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();
    $quantities = collect($data['distribution']['by_city'])->pluck('qty')->toArray();

    // Should be ordered by quantity descending
    $sorted = $quantities;
    rsort($sorted);
    expect($quantities)->toBe($sorted);
});

test('kpis endpoint top_city matches first by_city entry', function () {
    $response = actingAs($this->user)->getJson('/api/v1/users/kpis');

    $response->assertStatus(200);

    $data = $response->json();

    if (!empty($data['distribution']['by_city'])) {
        expect($data['distribution']['top_city'])->toBe($data['distribution']['by_city'][0]);
    }
});
