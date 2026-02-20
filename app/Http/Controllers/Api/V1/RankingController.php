<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Reports\Services\RankingService;
use App\Http\Controllers\Api\V1\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Relatorios - Ranking
 *
 * Endpoints para ranking de vendedores e consultas relacionadas.
 */
class RankingController extends Controller
{
    use ApiResponse;
    use ResolvesReportFilters;

    public function __construct(
        private RankingService $rankingService
    ) {
    }

    /**
     * Ranking de Vendedores
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'period' => ['sometimes', 'string', 'in:today,yesterday,last_7_days,last_30_days,this_month,last_month'],
            'store_id' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $window = $this->resolveReportWindow($validated, 'America/Sao_Paulo');
        $storeId = $this->resolveStoreIdFilter($validated['store_id'] ?? null);
        $limit = (int) ($validated['limit'] ?? 50);
        $order = (string) ($validated['order'] ?? 'desc');

        // Verificar acesso a loja se informada
        if ($storeId !== null && !$request->user()->hasAccessToStore($storeId)) {
            return $this->forbidden('Voce nao tem acesso a esta loja.');
        }

        $ranking = $this->rankingService->getRanking(
            $storeId,
            $window['month'],
            $limit,
            $order,
            $window['from_utc'],
            $window['to_utc'],
            $window['period_label']
        );

        $ranking['filters'] = [
            'store_id' => $storeId,
            'mode' => $window['mode'],
            'month' => $window['month'],
            'period' => $window['period_label'],
            'from' => $window['from_utc']->toIso8601String(),
            'to' => $window['to_utc']->toIso8601String(),
            'timezone' => $window['timezone'],
            'limit' => $limit,
            'order' => $order,
        ];

        return $this->success($ranking);
    }

    /**
     * Aniversariantes do Mes
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
