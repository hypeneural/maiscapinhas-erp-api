<?php

declare(strict_types=1);

use App\Models\PeopleKpiShift;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Enums\KpiSource;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
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

    $this->date = Carbon::today();

    // Create some KPIs
    foreach (['M', 'T'] as $shift) {
        PeopleKpiShift::create([
            'store_id' => $this->store->id,
            'date' => $this->date->format('Y-m-d'),
            'shift_code' => $shift,
            'in_count' => 100,
            'out_count' => 25,
            'staff_in' => 3,
            'staff_out' => 3,
            'source' => KpiSource::MANUAL,
        ]);
    }
});

test('people analytics endpoint returns shift data', function () {
    $response = actingAs($this->user)
        ->getJson("/api/v1/analytics/people/shift?store_id={$this->store->id}&date={$this->date->format('Y-m-d')}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'date',
                'store_id',
                'shifts',
                'totals' => [
                    'in_count',
                    'out_count',
                    'staff_in',
                    'staff_out',
                    'conversion_rate',
                ],
            ],
            'meta',
        ]);
});

test('people analytics returns totals correctly', function () {
    $response = actingAs($this->user)
        ->getJson("/api/v1/analytics/people/shift?store_id={$this->store->id}&date={$this->date->format('Y-m-d')}");

    $response->assertStatus(200);

    $data = $response->json('data');

    // 2 shifts × 100 in = 200 total in
    expect($data['totals']['in_count'])->toBe(200);
    // 2 shifts × 25 out = 50 total out
    expect($data['totals']['out_count'])->toBe(50);
    // 50/200 = 25% conversion rate
    expect($data['totals']['conversion_rate'])->toBe(25.00);
});

test('vendedor cannot access other store analytics', function () {
    $otherStore = Store::create([
        'name' => 'Other Store',
        'city' => 'Other City',
        'active' => true,
    ]);

    $response = actingAs($this->user)
        ->getJson("/api/v1/analytics/people/shift?store_id={$otherStore->id}&date={$this->date->format('Y-m-d')}");

    $response->assertStatus(403);
});
