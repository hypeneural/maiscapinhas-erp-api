<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Models\Sale;
use App\Models\SellerDailyBonus;
use App\Models\StoreGoalSplit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service para geração de rankings de vendedores.
 */
class RankingService
{
    /**
     * Gera ranking de vendedores para um período.
     *
     * @param int|null $storeId ID da loja (null = todas)
     * @param string $month Mês no formato YYYY-MM
     * @param int $limit Limite de resultados
     * @param string $order Ordenação: 'desc' para melhores, 'asc' para piores
     */
    public function getRanking(
        ?int $storeId,
        string $month,
        int $limit = 50,
        string $order = 'desc'
    ): array {
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();

        // Query de vendas agrupadas por vendedor (PDV Data Source)
        $salesQuery = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->join('pdv_user_mappings as pum', function ($join) {
                $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id');
            })
            ->where('pum.active', true)
            ->whereBetween('v.data_hora', [$startOfMonth, $endOfMonth->endOfDay()])
            ->select([
                'pum.user_id as seller_id',
                DB::raw('SUM(vi.total) as total_sold'),
                DB::raw('COUNT(DISTINCT v.id) as sale_count'),
            ])
            ->groupBy('pum.user_id');

        // Ordenação: desc para melhores, asc para piores
        if ($order === 'asc') {
            $salesQuery->orderBy('total_sold', 'asc');
        } else {
            $salesQuery->orderByDesc('total_sold');
        }

        if ($storeId) {
            $salesQuery->where('v.store_id', $storeId);
        }

        $salesData = $salesQuery->limit($limit)->get();


        // Buscar dados complementares dos vendedores
        $sellerIds = $salesData->pluck('seller_id')->toArray();

        $sellers = User::whereIn('id', $sellerIds)
            ->with(['storeUsers.store'])
            ->get()
            ->keyBy('id');

        // Buscar metas individuais
        $splits = StoreGoalSplit::whereIn('user_id', $sellerIds)
            ->whereHas('storeMonthlyGoal', fn($q) => $q->forMonth($month))
            ->with('storeMonthlyGoal')
            ->get()
            ->keyBy('user_id');

        // Buscar bônus acumulado
        $bonuses = SellerDailyBonus::whereIn('user_id', $sellerIds)
            ->whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')])
            ->where('status', 'confirmed')
            ->selectRaw('user_id, SUM(bonus_amount) as total_bonus')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Montar ranking
        $ranking = [];
        $position = 0;

        foreach ($salesData as $sale) {
            $position++;
            $seller = $sellers->get($sale->seller_id);
            $split = $splits->get($sale->seller_id);
            $bonus = $bonuses->get($sale->seller_id);

            if (!$seller) {
                continue;
            }

            $goal = $split?->goal_amount ?? 0;
            $achievementRate = $goal > 0 ? round(($sale->total_sold / $goal) * 100, 2) : 0;

            $primaryStore = $seller->storeUsers->first()?->store;

            $ranking[] = [
                'position' => $position,
                'seller' => [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'avatar_url' => $seller->avatar_url,
                    'store_name' => $primaryStore?->name,
                ],
                'total_sold' => (float) $sale->total_sold,
                'sale_count' => (int) $sale->sale_count,
                'goal' => $goal,
                'achievement_rate' => $achievementRate,
                'bonus_accumulated' => (float) ($bonus?->total_bonus ?? 0),
            ];
        }

        // Separar pódio (top 3)
        $podium = array_slice($ranking, 0, 3);
        $restOfRanking = array_slice($ranking, 3);

        // Estatísticas gerais
        $totalSellers = count($ranking);
        $aboveGoal = collect($ranking)->filter(fn($r) => $r['achievement_rate'] >= 100)->count();
        $avgAchievement = $totalSellers > 0
            ? round(collect($ranking)->avg('achievement_rate'), 2)
            : 0;

        return [
            'period' => $month,
            'store_id' => $storeId,
            'podium' => $podium,
            'ranking' => $restOfRanking,
            'stats' => [
                'total_sellers' => $totalSellers,
                'above_goal' => $aboveGoal,
                'below_goal' => $totalSellers - $aboveGoal,
                'average_achievement' => $avgAchievement,
            ],
        ];
    }

    /**
     * Busca aniversariantes do mês.
     */
    public function getBirthdays(?int $storeId, int $month): Collection
    {
        $query = User::query()
            ->whereRaw('MONTH(birth_date) = ?', [$month])
            ->where('active', true)
            ->orderByRaw('DAY(birth_date)');

        if ($storeId) {
            $query->whereHas('storeUsers', fn($q) => $q->where('store_id', $storeId));
        }

        return $query->get()->map(fn(User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'birth_date' => $user->birth_date?->format('Y-m-d'),
            'day' => $user->birth_date?->day,
            'age' => $user->birth_date?->age,
            'avatar_url' => $user->avatar_url,
        ]);
    }
}
