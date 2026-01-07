<?php

namespace Database\Seeders;

use App\Enums\KpiSource;
use App\Models\BonusRule;
use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\CommissionRule;
use App\Models\Divergence;
use App\Models\PeopleKpiShift;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreGoalSplit;
use App\Models\StoreMonthlyGoal;
use App\Models\StoreUser;
use App\Models\TargetDaily;
use App\Models\TargetMonthly;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\StoreFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private array $stores;
    private array $users;

    public function run(): void
    {
        // Create 3 stores
        $this->stores = $this->createStores();

        // Create users with different roles
        $this->users = $this->createUsers();

        // Link users to stores with roles
        $this->createStoreUsers($this->stores, $this->users);

        // Create sales data (30 days)
        $this->createSales($this->stores, $this->users['vendedores']);

        // Create targets
        $this->createTargets($this->stores, $this->users['vendedores']);

        // Create cash shifts and closings
        $this->createCashShiftsAndClosings($this->stores, $this->users['vendedores'], $this->users['conferentes']);

        // ====== PASSO 3 DATA ======

        // Create bonus and commission rules
        $this->createRules();

        // Create monthly goals with splits
        $this->createMonthlyGoalsWithSplits();

        // Create people KPI data
        $this->createPeopleKpis();
    }

    private function createStores(): array
    {
        return [
            'tijucas' => Store::create(StoreFactory::tijucas()),
            'itapema' => Store::create(StoreFactory::itapema()),
            'bombinhas' => Store::create(StoreFactory::bombinhas()),
        ];
    }

    private function createUsers(): array
    {
        $admin = User::create([
            'name' => 'Admin Sistema',
            'email' => 'admin@maiscapinhas.com.br',
            'password' => Hash::make('password'),
            'active' => true,
        ]);

        $gerentes = [
            User::create([
                'name' => 'Carlos Gerente',
                'email' => 'carlos.gerente@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Maria Gerente',
                'email' => 'maria.gerente@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
        ];

        $conferentes = [
            User::create([
                'name' => 'Ana Conferente',
                'email' => 'ana.conferente@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Paulo Conferente',
                'email' => 'paulo.conferente@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
        ];

        $vendedores = [
            User::create([
                'name' => 'João Vendedor',
                'email' => 'joao.vendedor@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Fernanda Vendedora',
                'email' => 'fernanda.vendedora@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Ricardo Vendedor',
                'email' => 'ricardo.vendedor@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Luciana Vendedora',
                'email' => 'luciana.vendedora@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
            User::create([
                'name' => 'Pedro Vendedor',
                'email' => 'pedro.vendedor@maiscapinhas.com.br',
                'password' => Hash::make('password'),
                'active' => true,
            ]),
        ];

        return [
            'admin' => $admin,
            'gerentes' => $gerentes,
            'conferentes' => $conferentes,
            'vendedores' => $vendedores,
        ];
    }

    private function createStoreUsers(array $stores, array $users): void
    {
        foreach (array_values($stores) as $store) {
            StoreUser::create([
                'store_id' => $store->id,
                'user_id' => $users['admin']->id,
                'role' => StoreUser::ROLE_ADMIN,
            ]);
        }

        StoreUser::create(['store_id' => $stores['tijucas']->id, 'user_id' => $users['gerentes'][0]->id, 'role' => StoreUser::ROLE_GERENTE]);
        StoreUser::create(['store_id' => $stores['itapema']->id, 'user_id' => $users['gerentes'][0]->id, 'role' => StoreUser::ROLE_GERENTE]);
        StoreUser::create(['store_id' => $stores['bombinhas']->id, 'user_id' => $users['gerentes'][1]->id, 'role' => StoreUser::ROLE_GERENTE]);

        StoreUser::create(['store_id' => $stores['tijucas']->id, 'user_id' => $users['conferentes'][0]->id, 'role' => StoreUser::ROLE_CONFERENTE]);
        StoreUser::create(['store_id' => $stores['itapema']->id, 'user_id' => $users['conferentes'][0]->id, 'role' => StoreUser::ROLE_CONFERENTE]);
        StoreUser::create(['store_id' => $stores['bombinhas']->id, 'user_id' => $users['conferentes'][1]->id, 'role' => StoreUser::ROLE_CONFERENTE]);

        StoreUser::create(['store_id' => $stores['tijucas']->id, 'user_id' => $users['vendedores'][0]->id, 'role' => StoreUser::ROLE_VENDEDOR]);
        StoreUser::create(['store_id' => $stores['tijucas']->id, 'user_id' => $users['vendedores'][1]->id, 'role' => StoreUser::ROLE_VENDEDOR]);
        StoreUser::create(['store_id' => $stores['itapema']->id, 'user_id' => $users['vendedores'][2]->id, 'role' => StoreUser::ROLE_VENDEDOR]);
        StoreUser::create(['store_id' => $stores['itapema']->id, 'user_id' => $users['vendedores'][3]->id, 'role' => StoreUser::ROLE_VENDEDOR]);
        StoreUser::create(['store_id' => $stores['bombinhas']->id, 'user_id' => $users['vendedores'][4]->id, 'role' => StoreUser::ROLE_VENDEDOR]);
    }

    private function createSales(array $stores, array $vendedores): void
    {
        $storeVendedorMap = [
            'tijucas' => [$vendedores[0], $vendedores[1]],
            'itapema' => [$vendedores[2], $vendedores[3]],
            'bombinhas' => [$vendedores[4]],
        ];

        for ($day = 0; $day < 30; $day++) {
            $date = Carbon::now()->subDays($day);
            foreach ($stores as $key => $store) {
                $storeSellers = $storeVendedorMap[$key];
                $salesCount = rand(3, 8);
                for ($i = 0; $i < $salesCount; $i++) {
                    $seller = $storeSellers[array_rand($storeSellers)];
                    Sale::withoutEvents(fn() => Sale::create([
                        'store_id' => $store->id,
                        'seller_id' => $seller->id,
                        'sold_at' => $date->copy()->setTime(rand(9, 21), rand(0, 59)),
                        'amount' => round(rand(1500, 50000) / 100, 2),
                        'source' => rand(1, 10) <= 8 ? Sale::SOURCE_PDV : Sale::SOURCE_MANUAL,
                    ]));
                }
            }
        }
    }

    private function createTargets(array $stores, array $vendedores): void
    {
        $currentMonth = Carbon::now()->format('Y-m');
        $lastMonth = Carbon::now()->subMonth()->format('Y-m');

        foreach ($stores as $store) {
            TargetMonthly::create(['store_id' => $store->id, 'month' => $currentMonth, 'target_amount' => rand(50000, 100000)]);
            TargetMonthly::create(['store_id' => $store->id, 'month' => $lastMonth, 'target_amount' => rand(50000, 100000)]);
            for ($day = 0; $day < 14; $day++) {
                TargetDaily::create([
                    'store_id' => $store->id,
                    'date' => Carbon::now()->subDays($day)->format('Y-m-d'),
                    'target_amount' => rand(2000, 5000),
                    'seller_id' => null,
                ]);
            }
        }
    }

    private function createCashShiftsAndClosings(array $stores, array $vendedores, array $conferentes): void
    {
        $storeVendedorMap = [
            'tijucas' => [$vendedores[0], $vendedores[1]],
            'itapema' => [$vendedores[2], $vendedores[3]],
            'bombinhas' => [$vendedores[4]],
        ];
        $storeConferenteMap = [
            'tijucas' => $conferentes[0],
            'itapema' => $conferentes[0],
            'bombinhas' => $conferentes[1],
        ];

        for ($day = 0; $day < 14; $day++) {
            $date = Carbon::now()->subDays($day)->format('Y-m-d');
            foreach ($stores as $key => $store) {
                $storeSellers = $storeVendedorMap[$key];
                $conferente = $storeConferenteMap[$key];
                foreach (['M', 'T'] as $shiftCode) {
                    $seller = $storeSellers[array_rand($storeSellers)];
                    $status = $day > 2 ? CashShift::STATUS_CLOSED : CashShift::STATUS_OPEN;
                    $shift = CashShift::create([
                        'store_id' => $store->id,
                        'date' => $date,
                        'shift_code' => $shiftCode,
                        'seller_id' => $seller->id,
                        'status' => $status,
                    ]);

                    if ($day > 1) {
                        $closingStatus = $this->determineClosingStatus($day);
                        $closing = CashClosing::withoutEvents(fn() => CashClosing::create([
                            'cash_shift_id' => $shift->id,
                            'status' => $closingStatus,
                            'closed_by' => $closingStatus === CashClosing::STATUS_APPROVED ? $conferente->id : null,
                            'closed_at' => $closingStatus === CashClosing::STATUS_APPROVED ? Carbon::now()->subDays($day - 1) : null,
                            'version' => rand(1, 3),
                        ]));
                        $this->createClosingLines($closing, $day);
                    }
                }
            }
        }
    }

    private function determineClosingStatus(int $daysAgo): string
    {
        if ($daysAgo > 7)
            return CashClosing::STATUS_APPROVED;
        if ($daysAgo > 4)
            return rand(1, 3) === 1 ? CashClosing::STATUS_REJECTED : CashClosing::STATUS_APPROVED;
        if ($daysAgo > 2)
            return CashClosing::STATUS_SUBMITTED;
        return CashClosing::STATUS_DRAFT;
    }

    private function createClosingLines(CashClosing $closing, int $daysAgo): void
    {
        foreach ([CashClosingLine::LABEL_CASH, CashClosingLine::LABEL_CREDIT_CARD, CashClosingLine::LABEL_DEBIT_CARD, CashClosingLine::LABEL_PIX] as $label) {
            $systemValue = round(rand(20000, 100000) / 100, 2);
            $hasDivergence = rand(1, 5) === 1;
            $diff = $hasDivergence ? round(rand(-5000, 5000) / 100, 2) : 0;
            $needsJustification = $hasDivergence && in_array($closing->status, [CashClosing::STATUS_SUBMITTED, CashClosing::STATUS_APPROVED]);

            $line = CashClosingLine::create([
                'cash_closing_id' => $closing->id,
                'label' => $label,
                'system_value' => $systemValue,
                'real_value' => $systemValue + $diff,
                'diff_value' => $diff,
                'justification_text' => $needsJustification ? 'Erro de contagem corrigido' : null,
            ]);

            if ($hasDivergence) {
                Divergence::create([
                    'cash_closing_line_id' => $line->id,
                    'status' => $needsJustification ? Divergence::STATUS_RESOLVED : Divergence::STATUS_PENDING,
                    'justification_required' => true,
                ]);
            }
        }
    }

    // ============================================
    // PASSO 3 SEEDING METHODS
    // ============================================

    private function createRules(): void
    {
        // Global bonus rule
        BonusRule::create([
            'store_id' => null,
            'effective_from' => Carbon::now()->subMonths(6),
            'config_json' => [
                ['min_sales' => 500, 'bonus' => 10],
                ['min_sales' => 800, 'bonus' => 20],
                ['min_sales' => 1200, 'bonus' => 35],
            ],
            'version' => 1,
        ]);

        // Store-specific bonus rule for Tijucas (higher bonuses)
        BonusRule::create([
            'store_id' => $this->stores['tijucas']->id,
            'effective_from' => Carbon::now()->subMonth(),
            'config_json' => [
                ['min_sales' => 400, 'bonus' => 15],
                ['min_sales' => 700, 'bonus' => 25],
                ['min_sales' => 1000, 'bonus' => 40],
            ],
            'version' => 1,
        ]);

        // Global commission rule
        CommissionRule::create([
            'store_id' => null,
            'effective_from' => Carbon::now()->subMonths(6),
            'config_json' => [
                ['min_attainment' => 0, 'rate' => 2],
                ['min_attainment' => 80, 'rate' => 2.5],
                ['min_attainment' => 100, 'rate' => 3],
                ['min_attainment' => 120, 'rate' => 4],
            ],
            'version' => 1,
        ]);

        // Store-specific commission rule for Bombinhas
        CommissionRule::create([
            'store_id' => $this->stores['bombinhas']->id,
            'effective_from' => Carbon::now()->subMonth(),
            'config_json' => [
                ['min_attainment' => 0, 'rate' => 2.5],
                ['min_attainment' => 100, 'rate' => 3.5],
                ['min_attainment' => 120, 'rate' => 5],
            ],
            'version' => 1,
        ]);
    }

    private function createMonthlyGoalsWithSplits(): void
    {
        $currentMonth = Carbon::now()->format('Y-m');

        // Tijucas: 50/50 split
        $goal1 = StoreMonthlyGoal::create([
            'store_id' => $this->stores['tijucas']->id,
            'month' => $currentMonth,
            'goal_amount' => 60000.00,
            'active' => true,
        ]);
        StoreGoalSplit::create(['store_monthly_goal_id' => $goal1->id, 'user_id' => $this->users['vendedores'][0]->id, 'percent' => 50.00]);
        StoreGoalSplit::create(['store_monthly_goal_id' => $goal1->id, 'user_id' => $this->users['vendedores'][1]->id, 'percent' => 50.00]);

        // Itapema: 47/53 split
        $goal2 = StoreMonthlyGoal::create([
            'store_id' => $this->stores['itapema']->id,
            'month' => $currentMonth,
            'goal_amount' => 55000.00,
            'active' => true,
        ]);
        StoreGoalSplit::create(['store_monthly_goal_id' => $goal2->id, 'user_id' => $this->users['vendedores'][2]->id, 'percent' => 47.00]);
        StoreGoalSplit::create(['store_monthly_goal_id' => $goal2->id, 'user_id' => $this->users['vendedores'][3]->id, 'percent' => 53.00]);

        // Bombinhas: 100% to single seller
        $goal3 = StoreMonthlyGoal::create([
            'store_id' => $this->stores['bombinhas']->id,
            'month' => $currentMonth,
            'goal_amount' => 40000.00,
            'active' => true,
        ]);
        StoreGoalSplit::create(['store_monthly_goal_id' => $goal3->id, 'user_id' => $this->users['vendedores'][4]->id, 'percent' => 100.00]);
    }

    private function createPeopleKpis(): void
    {
        foreach ($this->stores as $store) {
            for ($day = 0; $day < 14; $day++) {
                $date = Carbon::now()->subDays($day);
                foreach (['M', 'T', 'N'] as $shiftCode) {
                    $inCount = rand(30, 120);
                    PeopleKpiShift::create([
                        'store_id' => $store->id,
                        'date' => $date->format('Y-m-d'),
                        'shift_code' => $shiftCode,
                        'in_count' => $inCount,
                        'out_count' => rand((int) ($inCount * 0.1), (int) ($inCount * 0.35)),
                        'staff_in' => rand(2, 5),
                        'staff_out' => rand(2, 5),
                        'source' => KpiSource::MANUAL,
                        'raw_json' => null,
                    ]);
                }
            }
        }
    }
}
