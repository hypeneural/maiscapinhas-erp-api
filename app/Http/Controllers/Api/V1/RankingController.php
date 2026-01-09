<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Reports\Services\RankingService;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Relatórios - Ranking
 *
 * Endpoints para ranking de vendedores e consultas relacionadas.
 */
class RankingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RankingService $rankingService
    ) {
    }

    /**
     * Ranking de Vendedores
     *
     * Retorna ranking completo de vendedores ordenado por vendas no período.
     *
     * **Inclui:**
     * - Pódio (top 3) em destaque
     * - Ranking completo com estatísticas
     * - Meta, atingimento e bônus acumulado de cada vendedor
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam month string Mês (YYYY-MM), default: mês atual. Example: 2026-01
     * @queryParam store_id integer Filtrar por loja. Example: 1
     * @queryParam limit integer Limite de resultados (default: 50). Example: 10
     *
     * @response 200 scenario="Ranking mensal" {
     *   "data": {
     *     "period": "2026-01",
     *     "podium": [
     *       {
     *         "position": 1,
     *         "seller": { "id": 5, "name": "João Silva", "avatar_url": "...", "store_name": "Tijucas" },
     *         "total_sold": 85000.00,
     *         "goal": 75000.00,
     *         "achievement_rate": 113.33,
     *         "bonus_accumulated": 450.00
     *       }
     *     ],
     *     "ranking": [...],
     *     "stats": { "total_sellers": 25, "above_goal": 12, "average_achievement": 92.5 }
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $storeId = $request->input('store_id') ? (int) $request->input('store_id') : null;
        $limit = (int) $request->input('limit', 50);

        // Verificar acesso à loja se informada
        if ($storeId && !$request->user()->hasAccessToStore($storeId)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        $ranking = $this->rankingService->getRanking($storeId, $month, $limit);

        return $this->success($ranking);
    }

    /**
     * Aniversariantes do Mês
     *
     * Lista vendedores que fazem aniversário no mês especificado.
     *
     * **Quem pode usar:** Gerentes e Admins.
     *
     * @queryParam month integer Mês (1-12), default: mês atual. Example: 1
     * @queryParam store_id integer Filtrar por loja. Example: 1
     *
     * @response 200 scenario="Aniversariantes de janeiro" {
     *   "data": [
     *     {
     *       "id": 5,
     *       "name": "João Silva",
     *       "birth_date": "1990-01-15",
     *       "day": 15,
     *       "age": 36,
     *       "avatar_url": "https://..."
     *     }
     *   ]
     * }
     */
    public function birthdays(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
        ]);

        $month = $request->input('month', Carbon::now()->month);
        $storeId = $request->input('store_id');

        $birthdays = $this->rankingService->getBirthdays($storeId, $month);

        return $this->success($birthdays);
    }
}
