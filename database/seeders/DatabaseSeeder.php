<?php

namespace Database\Seeders;

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\Divergence;
use App\Models\Sale;
use App\Models\Store;
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
    public function run(): void
    {
        // Create 3 stores
        $stores = $this->createStores();

        // Create users with different roles
        $users = $this->createUsers();

        // Link users to stores with roles
        $this->createStoreUsers($stores, $users);

        // Create sales data (30 days)
        $this->createSales($stores, $users['vendedores']);

        // Create targets
        $this->createTargets($stores, $users['vendedores']);

        // Create cash shifts and closings
        $this->createCashShiftsAndClosings($stores, $users['vendedores'], $users['conferentes']);
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
        // Admin global
        $admin = User::create([
            'name' => 'Admin Sistema',
            'email' => 'admin@maiscapinhas.com.br',
            'password' => Hash::make('password'),
            'active' => true,
        ]);

        // Gerentes (2)
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

        // Conferentes (2)
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

        // Vendedores (5)
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
        $storeList = array_values($stores);

        // Admin is admin in all stores
        foreach ($storeList as $store) {
            StoreUser::create([
                'store_id' => $store->id,
                'user_id' => $users['admin']->id,
                'role' => StoreUser::ROLE_ADMIN,
            ]);
        }

        // Gerente 1 -> Tijucas, Itapema
        StoreUser::create([
            'store_id' => $stores['tijucas']->id,
            'user_id' => $users['gerentes'][0]->id,
            'role' => StoreUser::ROLE_GERENTE,
        ]);
        StoreUser::create([
            'store_id' => $stores['itapema']->id,
            'user_id' => $users['gerentes'][0]->id,
            'role' => StoreUser::ROLE_GERENTE,
        ]);

        // Gerente 2 -> Bombinhas
        StoreUser::create([
            'store_id' => $stores['bombinhas']->id,
            'user_id' => $users['gerentes'][1]->id,
            'role' => StoreUser::ROLE_GERENTE,
        ]);

        // Conferente 1 -> Tijucas, Itapema
        StoreUser::create([
            'store_id' => $stores['tijucas']->id,
            'user_id' => $users['conferentes'][0]->id,
            'role' => StoreUser::ROLE_CONFERENTE,
        ]);
        StoreUser::create([
            'store_id' => $stores['itapema']->id,
            'user_id' => $users['conferentes'][0]->id,
            'role' => StoreUser::ROLE_CONFERENTE,
        ]);

        // Conferente 2 -> Bombinhas
        StoreUser::create([
            'store_id' => $stores['bombinhas']->id,
            'user_id' => $users['conferentes'][1]->id,
            'role' => StoreUser::ROLE_CONFERENTE,
        ]);

        // Vendedores distributed across stores
        // Vendedor 1,2 -> Tijucas
        StoreUser::create([
            'store_id' => $stores['tijucas']->id,
            'user_id' => $users['vendedores'][0]->id,
            'role' => StoreUser::ROLE_VENDEDOR,
        ]);
        StoreUser::create([
            'store_id' => $stores['tijucas']->id,
            'user_id' => $users['vendedores'][1]->id,
            'role' => StoreUser::ROLE_VENDEDOR,
        ]);

        // Vendedor 3,4 -> Itapema
        StoreUser::create([
            'store_id' => $stores['itapema']->id,
            'user_id' => $users['vendedores'][2]->id,
            'role' => StoreUser::ROLE_VENDEDOR,
        ]);
        StoreUser::create([
            'store_id' => $stores['itapema']->id,
            'user_id' => $users['vendedores'][3]->id,
            'role' => StoreUser::ROLE_VENDEDOR,
        ]);

        // Vendedor 5 -> Bombinhas
        StoreUser::create([
            'store_id' => $stores['bombinhas']->id,
            'user_id' => $users['vendedores'][4]->id,
            'role' => StoreUser::ROLE_VENDEDOR,
        ]);
    }

    private function createSales(array $stores, array $vendedores): void
    {
        $storeVendedorMap = [
            'tijucas' => [$vendedores[0], $vendedores[1]],
            'itapema' => [$vendedores[2], $vendedores[3]],
            'bombinhas' => [$vendedores[4]],
        ];

        // Create sales for last 30 days
        for ($day = 0; $day < 30; $day++) {
            $date = Carbon::now()->subDays($day);

            foreach ($stores as $key => $store) {
                $storeSellers = $storeVendedorMap[$key];

                // 3-8 sales per store per day
                $salesCount = rand(3, 8);

                for ($i = 0; $i < $salesCount; $i++) {
                    $seller = $storeSellers[array_rand($storeSellers)];
                    $hour = rand(9, 21);
                    $minute = rand(0, 59);

                    Sale::create([
                        'store_id' => $store->id,
                        'seller_id' => $seller->id,
                        'sold_at' => $date->copy()->setTime($hour, $minute),
                        'amount' => round(rand(1500, 50000) / 100, 2), // R$15 - R$500
                        'source' => rand(1, 10) <= 8 ? Sale::SOURCE_PDV : Sale::SOURCE_MANUAL,
                    ]);
                }
            }
        }
    }

    private function createTargets(array $stores, array $vendedores): void
    {
        $currentMonth = Carbon::now()->format('Y-m');
        $lastMonth = Carbon::now()->subMonth()->format('Y-m');

        foreach ($stores as $store) {
            // Monthly targets
            TargetMonthly::create([
                'store_id' => $store->id,
                'month' => $currentMonth,
                'target_amount' => rand(50000, 100000),
            ]);
            TargetMonthly::create([
                'store_id' => $store->id,
                'month' => $lastMonth,
                'target_amount' => rand(50000, 100000),
            ]);

            // Daily targets for last 14 days
            for ($day = 0; $day < 14; $day++) {
                $date = Carbon::now()->subDays($day)->format('Y-m-d');

                TargetDaily::create([
                    'store_id' => $store->id,
                    'date' => $date,
                    'target_amount' => rand(2000, 5000),
                    'seller_id' => null, // Store-level target
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

        $shiftCodes = ['M', 'T'];

        // Create shifts for last 14 days
        for ($day = 0; $day < 14; $day++) {
            $date = Carbon::now()->subDays($day)->format('Y-m-d');

            foreach ($stores as $key => $store) {
                $storeSellers = $storeVendedorMap[$key];
                $conferente = $storeConferenteMap[$key];

                foreach ($shiftCodes as $shiftCode) {
                    $seller = $storeSellers[array_rand($storeSellers)];

                    // Determine shift status based on age
                    $status = $day > 2 ? CashShift::STATUS_CLOSED : CashShift::STATUS_OPEN;

                    $shift = CashShift::create([
                        'store_id' => $store->id,
                        'date' => $date,
                        'shift_code' => $shiftCode,
                        'seller_id' => $seller->id,
                        'status' => $status,
                    ]);

                    // Create closing for older shifts
                    if ($day > 1) {
                        $closingStatus = $this->determineClosingStatus($day);

                        $closing = CashClosing::create([
                            'cash_shift_id' => $shift->id,
                            'status' => $closingStatus,
                            'closed_by' => $closingStatus === CashClosing::STATUS_APPROVED ? $conferente->id : null,
                            'closed_at' => $closingStatus === CashClosing::STATUS_APPROVED ? Carbon::now()->subDays($day - 1) : null,
                            'version' => rand(1, 3),
                        ]);

                        // Create closing lines
                        $this->createClosingLines($closing, $day);
                    }
                }
            }
        }
    }

    private function determineClosingStatus(int $daysAgo): string
    {
        if ($daysAgo > 7) {
            return CashClosing::STATUS_APPROVED;
        }
        if ($daysAgo > 4) {
            return rand(1, 3) === 1 ? CashClosing::STATUS_REJECTED : CashClosing::STATUS_APPROVED;
        }
        if ($daysAgo > 2) {
            return CashClosing::STATUS_SUBMITTED;
        }
        return CashClosing::STATUS_DRAFT;
    }

    private function createClosingLines(CashClosing $closing, int $daysAgo): void
    {
        $labels = [
            CashClosingLine::LABEL_CASH,
            CashClosingLine::LABEL_CREDIT_CARD,
            CashClosingLine::LABEL_DEBIT_CARD,
            CashClosingLine::LABEL_PIX,
        ];

        foreach ($labels as $label) {
            $systemValue = round(rand(20000, 100000) / 100, 2);

            // Add some divergence
            $hasDivergence = rand(1, 5) === 1;
            $diff = $hasDivergence ? round(rand(-5000, 5000) / 100, 2) : 0;
            $realValue = $systemValue + $diff;

            // Justify if submitted/approved and has divergence
            $needsJustification = $hasDivergence && in_array($closing->status, [
                CashClosing::STATUS_SUBMITTED,
                CashClosing::STATUS_APPROVED,
            ]);

            $line = CashClosingLine::create([
                'cash_closing_id' => $closing->id,
                'label' => $label,
                'system_value' => $systemValue,
                'real_value' => $realValue,
                'diff_value' => $diff,
                'justification_text' => $needsJustification ? 'Erro de contagem corrigido' : null,
            ]);

            // Create divergence record if there's a diff
            if ($hasDivergence) {
                Divergence::create([
                    'cash_closing_line_id' => $line->id,
                    'status' => $needsJustification ? Divergence::STATUS_RESOLVED : Divergence::STATUS_PENDING,
                    'justification_required' => true,
                ]);
            }
        }
    }
}
