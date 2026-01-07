<?php

declare(strict_types=1);

use App\Enums\BonusStatus;
use App\Models\BonusRule;
use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Domains\Finance\Engines\BonusEngineService;
use App\Services\RulesService;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

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

    // Create bonus rule
    BonusRule::create([
        'store_id' => null,
        'effective_from' => Carbon::now()->subMonth(),
        'config_json' => [
            ['min_sales' => 500, 'bonus' => 10],
            ['min_sales' => 800, 'bonus' => 20],
        ],
        'version' => 1,
    ]);
});

test('bonus is zeroed when divergence is not justified', function () {
    $date = Carbon::today();

    // Create some sales
    Sale::withoutEvents(fn() => Sale::create([
        'store_id' => $this->store->id,
        'seller_id' => $this->vendedor->id,
        'sold_at' => $date,
        'amount' => 600.00,
        'source' => 'pdv',
    ]));

    // Create shift with closing that has unjustified divergence
    $shift = CashShift::create([
        'store_id' => $this->store->id,
        'date' => $date->format('Y-m-d'),
        'shift_code' => 'M',
        'seller_id' => $this->vendedor->id,
        'status' => 'closed',
    ]);

    $closing = CashClosing::withoutEvents(fn() => CashClosing::create([
        'cash_shift_id' => $shift->id,
        'status' => 'approved',
        'version' => 1,
    ]));

    // Line with divergence but NO justification
    CashClosingLine::create([
        'cash_closing_id' => $closing->id,
        'label' => 'Dinheiro',
        'system_value' => 500.00,
        'real_value' => 450.00,
        'diff_value' => -50.00,
        'justification_text' => null, // Not justified!
    ]);

    // Calculate bonus
    $engine = app(BonusEngineService::class);
    $bonus = $engine->calculateDailyBonus($this->store->id, $this->vendedor->id, $date);

    expect($bonus->eligible)->toBeFalse();
    expect($bonus->bonus_amount)->toBe('0.00');
    expect($bonus->status)->toBe(BonusStatus::ZEROED);
});

test('bonus is NOT zeroed when divergence is justified', function () {
    $date = Carbon::today();

    // Create sales > 800 to get 20 bonus
    Sale::withoutEvents(fn() => Sale::create([
        'store_id' => $this->store->id,
        'seller_id' => $this->vendedor->id,
        'sold_at' => $date,
        'amount' => 900.00,
        'source' => 'pdv',
    ]));

    // Create shift with closing that has JUSTIFIED divergence
    $shift = CashShift::create([
        'store_id' => $this->store->id,
        'date' => $date->format('Y-m-d'),
        'shift_code' => 'M',
        'seller_id' => $this->vendedor->id,
        'status' => 'closed',
    ]);

    $closing = CashClosing::withoutEvents(fn() => CashClosing::create([
        'cash_shift_id' => $shift->id,
        'status' => 'approved',
        'version' => 1,
    ]));

    // Line with divergence AND justification
    CashClosingLine::create([
        'cash_closing_id' => $closing->id,
        'label' => 'Dinheiro',
        'system_value' => 500.00,
        'real_value' => 450.00,
        'diff_value' => -50.00,
        'justification_text' => 'Cliente pagou menos', // Justified!
    ]);

    $engine = app(BonusEngineService::class);
    $bonus = $engine->calculateDailyBonus($this->store->id, $this->vendedor->id, $date);

    expect($bonus->eligible)->toBeTrue();
    expect((float) $bonus->bonus_amount)->toBe(20.00);
    expect($bonus->status)->toBe(BonusStatus::PROVISIONAL);
});
