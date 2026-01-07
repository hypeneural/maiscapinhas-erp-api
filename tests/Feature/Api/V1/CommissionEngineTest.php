<?php

declare(strict_types=1);

use App\Models\CommissionRule;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreGoalSplit;
use App\Models\StoreMonthlyGoal;
use App\Models\StoreUser;
use App\Models\User;
use App\Domains\Finance\Engines\CommissionEngineService;
use Carbon\Carbon;

beforeEach(function () {
    $this->store = Store::create([
        'name' => 'Test Store',
        'city' => 'Test City',
        'active' => true,
    ]);

    $this->vendedor = User::create([
        'name' => 'Vendedor Test',
        'email' => 'vendedor@test.com',
        'password' => bcrypt('password'),
        'active' => true,
    ]);

    StoreUser::create([
        'store_id' => $this->store->id,
        'user_id' => $this->vendedor->id,
        'role' => 'vendedor',
    ]);

    $this->month = Carbon::now()->format('Y-m');

    // Create commission rule
    CommissionRule::create([
        'store_id' => null,
        'effective_from' => Carbon::now()->subMonth(),
        'config_json' => [
            ['min_attainment' => 0, 'rate' => 2],
            ['min_attainment' => 100, 'rate' => 3],
            ['min_attainment' => 120, 'rate' => 4],
        ],
        'version' => 1,
    ]);
});

test('commission changes when split percent is updated', function () {
    // Initial setup: 100% split, 10000 goal, 5000 sales = 50% attainment = 2% rate
    $goal = StoreMonthlyGoal::create([
        'store_id' => $this->store->id,
        'month' => $this->month,
        'goal_amount' => 10000.00,
        'active' => true,
    ]);

    StoreGoalSplit::create([
        'store_monthly_goal_id' => $goal->id,
        'user_id' => $this->vendedor->id,
        'percent' => 100.00,
    ]);

    // Create sales of 5000
    Sale::withoutEvents(fn() => Sale::create([
        'store_id' => $this->store->id,
        'seller_id' => $this->vendedor->id,
        'sold_at' => Carbon::now(),
        'amount' => 5000.00,
        'source' => 'pdv',
    ]));

    $engine = app(CommissionEngineService::class);
    $commission = $engine->calculateMonthlyCommission($this->store->id, $this->vendedor->id, $this->month);

    // 5000 / 10000 = 50% attainment = 2% rate = 100 commission
    expect((float) $commission->attainment_percent)->toBe(50.00);
    expect((float) $commission->rate_percent)->toBe(2.00);
    expect((float) $commission->commission_amount)->toBe(100.00);
});

test('commission rate increases at 100% attainment', function () {
    $goal = StoreMonthlyGoal::create([
        'store_id' => $this->store->id,
        'month' => $this->month,
        'goal_amount' => 10000.00,
        'active' => true,
    ]);

    StoreGoalSplit::create([
        'store_monthly_goal_id' => $goal->id,
        'user_id' => $this->vendedor->id,
        'percent' => 100.00,
    ]);

    // Create sales exactly at goal
    Sale::withoutEvents(fn() => Sale::create([
        'store_id' => $this->store->id,
        'seller_id' => $this->vendedor->id,
        'sold_at' => Carbon::now(),
        'amount' => 10000.00,
        'source' => 'pdv',
    ]));

    $engine = app(CommissionEngineService::class);
    $commission = $engine->calculateMonthlyCommission($this->store->id, $this->vendedor->id, $this->month);

    expect((float) $commission->attainment_percent)->toBe(100.00);
    expect((float) $commission->rate_percent)->toBe(3.00);
    expect((float) $commission->commission_amount)->toBe(300.00);
});

test('commission rate jumps at 120% attainment', function () {
    $goal = StoreMonthlyGoal::create([
        'store_id' => $this->store->id,
        'month' => $this->month,
        'goal_amount' => 10000.00,
        'active' => true,
    ]);

    StoreGoalSplit::create([
        'store_monthly_goal_id' => $goal->id,
        'user_id' => $this->vendedor->id,
        'percent' => 100.00,
    ]);

    // Create sales 20% above goal
    Sale::withoutEvents(fn() => Sale::create([
        'store_id' => $this->store->id,
        'seller_id' => $this->vendedor->id,
        'sold_at' => Carbon::now(),
        'amount' => 12000.00,
        'source' => 'pdv',
    ]));

    $engine = app(CommissionEngineService::class);
    $commission = $engine->calculateMonthlyCommission($this->store->id, $this->vendedor->id, $this->month);

    expect((float) $commission->attainment_percent)->toBe(120.00);
    expect((float) $commission->rate_percent)->toBe(4.00);
    expect((float) $commission->commission_amount)->toBe(480.00);
});
