<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Finance\Engines\BonusEngineService;
use App\Domains\Finance\Engines\CommissionEngineService;
use App\Enums\StoreUserRole;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\BonusRule;
use App\Models\Sale;
use App\Models\SellerDailyBonus;
use App\Models\SellerMonthlyCommission;
use App\Models\StoreGoalSplit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Extrato Financeiro
 *
 * Endpoints para consultar o extrato de bônus diário e comissão mensal.
 * Vendedores veem apenas seus próprios dados; gerentes/admins veem todos.
 */
class FinanceController extends Controller
{
    use ApiResponse;

    /**
     * Get bonus ledger.
     *
     * GET /api/v1/finance/bonus?store_id=&from=&to=
     */
    public function bonus(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = SellerDailyBonus::with(['store:id,name', 'user:id,name'])
            ->whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $storeIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        // For vendedor, only show their own bonuses
        $isManager = $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value, StoreUserRole::CONFERENTE->value])
            ->exists();

        if (!$isManager) {
            $query->where('user_id', $user->id);
        }

        // Date range
        if ($request->filled('from')) {
            $query->where('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->input('to'));
        }

        $bonuses = $query->orderByDesc('date')->paginate($request->input('per_page', 25));

        return $this->paginated($bonuses);
    }

    /**
     * Get commission ledger.
     *
     * GET /api/v1/finance/commission?store_id=&month=
     */
    public function commission(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = SellerMonthlyCommission::with(['store:id,name', 'user:id,name'])
            ->whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $storeIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        // For vendedor, only show their own commissions
        $isManager = $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value, StoreUserRole::CONFERENTE->value])
            ->exists();

        if (!$isManager) {
            $query->where('user_id', $user->id);
        }

        // Month filter
        if ($request->filled('month')) {
            $query->where('month', $request->input('month'));
        }

        $commissions = $query->orderByDesc('month')->paginate($request->input('per_page', 25));

        return $this->paginated($commissions);
    }

    /**
     * Extrato de bônus de um vendedor
     *
     * Retorna o histórico de bônus de um vendedor específico com resumo.
     *
     * **Quem pode usar:** O próprio vendedor ou Gerentes/Admins.
     *
     * @urlParam seller integer required ID do vendedor. Example: 5
     * @queryParam from string Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to string Data final (YYYY-MM-DD). Example: 2026-01-31
     *
     * @response 200 scenario="Extrato de bônus" {
     *   "data": {
     *     "seller": { "id": 5, "name": "João Silva" },
     *     "period": { "from": "2026-01-01", "to": "2026-01-31" },
     *     "summary": { "approved": 350.00, "pending": 50.00, "rejected": 25.00, "total_shifts": 22 },
     *     "items": [
     *       { "date": "2026-01-08", "shift_code": "M", "store_name": "Tijucas", "sales_total": 1250.00, "bonus": 50.00, "status": "approved" }
     *     ]
     *   }
     * }
     */
    public function sellerBonus(Request $request, User $seller): JsonResponse
    {
        // Authorization check
        $authUser = $request->user();
        $isOwnData = $authUser->id === $seller->id;
        $isManager = $authUser->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();

        if (!$isOwnData && !$isManager) {
            return $this->forbidden('Você não tem permissão para ver esses dados.');
        }

        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $from = $request->input('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->input('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

        $bonuses = SellerDailyBonus::with(['store:id,name'])
            ->where('user_id', $seller->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->get();

        $summary = [
            'approved' => $bonuses->where('status', 'approved')->sum('bonus_value'),
            'pending' => $bonuses->where('status', 'pending')->sum('bonus_value'),
            'rejected' => $bonuses->where('status', 'rejected')->sum('bonus_value'),
            'total_shifts' => $bonuses->count(),
        ];

        $items = $bonuses->map(fn($b) => [
            'date' => $b->date,
            'shift_code' => $b->shift_code,
            'store_name' => $b->store?->name,
            'sales_total' => (float) $b->sales_total,
            'bonus' => (float) $b->bonus_value,
            'status' => $b->status,
        ]);

        return $this->success([
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'period' => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    /**
     * Comissão de um vendedor
     *
     * Retorna a comissão de um vendedor para um mês específico.
     *
     * @urlParam seller integer required ID do vendedor. Example: 5
     * @queryParam month string Mês (YYYY-MM). Example: 2026-01
     */
    public function sellerCommission(Request $request, User $seller): JsonResponse
    {
        $authUser = $request->user();
        $isOwnData = $authUser->id === $seller->id;
        $isManager = $authUser->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();

        if (!$isOwnData && !$isManager) {
            return $this->forbidden('Você não tem permissão para ver esses dados.');
        }

        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $month = $request->input('month', Carbon::now()->format('Y-m'));

        $commission = SellerMonthlyCommission::with(['store:id,name'])
            ->where('user_id', $seller->id)
            ->where('month', $month)
            ->first();

        if (!$commission) {
            return $this->success([
                'seller' => ['id' => $seller->id, 'name' => $seller->name],
                'month' => $month,
                'message' => 'Nenhuma comissão calculada para este período.',
            ]);
        }

        return $this->success([
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'month' => $month,
            'store' => ['id' => $commission->store_id, 'name' => $commission->store?->name],
            'goal' => (float) $commission->goal_value,
            'sold' => (float) $commission->total_sold,
            'achievement_rate' => (float) $commission->achievement_rate,
            'commission_rate' => (float) $commission->commission_rate,
            'commission_value' => (float) $commission->commission_value,
            'status' => $commission->status,
        ]);
    }

    /**
     * Simulador de bônus
     *
     * Calcula o bônus para um valor de venda (simulação).
     *
     * @queryParam amount number required Valor da venda. Example: 1250
     * @queryParam store_id integer ID da loja (usa regra específica se houver). Example: 1
     *
     * @response 200 scenario="Simulação" {
     *   "data": {
     *     "amount": 1250.00,
     *     "current_tier": { "min_sales": 1000, "bonus": 50.00 },
     *     "bonus_value": 50.00,
     *     "next_tier": { "min_sales": 1500, "bonus": 75.00, "gap": 250.00 }
     *   }
     * }
     */
    public function calculateBonus(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
        ]);

        $amount = (float) $request->input('amount');
        $storeId = $request->input('store_id');

        // Find applicable rule
        $rule = BonusRule::query()
            ->where('active', true)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id');
                if ($storeId) {
                    $q->orWhere('store_id', $storeId);
                }
            })
            ->orderByDesc('store_id') // Prefer store-specific
            ->first();

        if (!$rule) {
            return $this->success([
                'amount' => $amount,
                'bonus_value' => 0,
                'message' => 'Nenhuma regra de bônus configurada.',
            ]);
        }

        $config = $rule->config_json ?? [];
        $currentTier = null;
        $nextTier = null;
        $bonusValue = 0;

        // Sort tiers by min_sales
        usort($config, fn($a, $b) => $a['min_sales'] <=> $b['min_sales']);

        foreach ($config as $tier) {
            if ($amount >= $tier['min_sales']) {
                $currentTier = $tier;
                $bonusValue = $tier['bonus'];
            } else {
                $nextTier = $tier;
                break;
            }
        }

        $result = [
            'amount' => $amount,
            'bonus_value' => $bonusValue,
        ];

        if ($currentTier) {
            $result['current_tier'] = $currentTier;
        }

        if ($nextTier) {
            $result['next_tier'] = [
                'min_sales' => $nextTier['min_sales'],
                'bonus' => $nextTier['bonus'],
                'gap' => round($nextTier['min_sales'] - $amount, 2),
            ];
        }

        return $this->success($result);
    }

    /**
     * Projeção de comissão
     *
     * Projeta a comissão do vendedor baseada no ritmo atual de vendas.
     *
     * @urlParam seller integer required ID do vendedor. Example: 5
     * @queryParam month string Mês (YYYY-MM). Example: 2026-01
     *
     * @response 200 scenario="Projeção" {
     *   "data": {
     *     "seller": { "id": 5, "name": "João" },
     *     "current": { "sold": 28000, "goal": 75000, "achievement": 37.33, "daily_average": 3500 },
     *     "projection": { "estimated_total": 77000, "estimated_achievement": 102.67, "estimated_commission": 2310 },
     *     "scenarios": [
     *       { "scenario": "pessimist", "achievement": 85, "commission": 1275 },
     *       { "scenario": "realistic", "achievement": 103, "commission": 2310 },
     *       { "scenario": "optimist", "achievement": 125, "commission": 3750 }
     *     ]
     *   }
     * }
     */
    public function commissionProjection(Request $request, User $seller): JsonResponse
    {
        $authUser = $request->user();
        $isOwnData = $authUser->id === $seller->id;
        $isManager = $authUser->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value])
            ->exists();

        if (!$isOwnData && !$isManager) {
            return $this->forbidden('Você não tem permissão para ver esses dados.');
        }

        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();
        $today = Carbon::now()->min($endOfMonth);

        // Get seller's goal
        $split = StoreGoalSplit::whereHas('storeMonthlyGoal', fn($q) => $q->forMonth($month))
            ->where('user_id', $seller->id)
            ->with('storeMonthlyGoal')
            ->first();

        $goal = $split?->goal_amount ?? 0;

        // Get current sales
        $soldToDate = Sale::where('seller_id', $seller->id)
            ->whereBetween('sold_at', [$startOfMonth, $today->endOfDay()])
            ->sum('amount');

        $daysWorked = max(1, $startOfMonth->diffInDays($today) + 1);
        $totalDays = $startOfMonth->diffInDays($endOfMonth) + 1;
        $daysRemaining = $endOfMonth->diffInDays($today);

        $dailyAverage = $soldToDate / $daysWorked;
        $projectedTotal = $soldToDate + ($dailyAverage * $daysRemaining);
        $currentAchievement = $goal > 0 ? round(($soldToDate / $goal) * 100, 2) : 0;
        $projectedAchievement = $goal > 0 ? round(($projectedTotal / $goal) * 100, 2) : 0;

        // Calculate commission for projection
        $commissionEngine = app(CommissionEngineService::class);
        $projectedCommission = $goal > 0 ? $commissionEngine->calculateCommissionValue($projectedTotal, $projectedAchievement) : 0;

        // Scenarios
        $scenarios = [];
        $scenarioRates = [
            'pessimist' => 0.75,
            'realistic' => 1.0,
            'optimist' => 1.25,
        ];

        foreach ($scenarioRates as $name => $rate) {
            $scenarioTotal = $soldToDate + ($dailyAverage * $rate * $daysRemaining);
            $scenarioAchievement = $goal > 0 ? round(($scenarioTotal / $goal) * 100, 2) : 0;
            $scenarioCommission = $goal > 0 ? $commissionEngine->calculateCommissionValue($scenarioTotal, $scenarioAchievement) : 0;

            $scenarios[] = [
                'scenario' => $name,
                'estimated_total' => round($scenarioTotal, 2),
                'achievement' => $scenarioAchievement,
                'commission' => round($scenarioCommission, 2),
            ];
        }

        return $this->success([
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'month' => $month,
            'goal' => $goal,
            'current' => [
                'sold' => round($soldToDate, 2),
                'achievement' => $currentAchievement,
                'daily_average' => round($dailyAverage, 2),
                'days_worked' => $daysWorked,
                'days_remaining' => $daysRemaining,
            ],
            'projection' => [
                'estimated_total' => round($projectedTotal, 2),
                'estimated_achievement' => $projectedAchievement,
                'estimated_commission' => round($projectedCommission, 2),
            ],
            'scenarios' => $scenarios,
        ]);
    }
}

