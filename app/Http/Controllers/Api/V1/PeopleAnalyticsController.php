<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Analytics\Services\PeopleAnalyticsSyncService;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PeopleKpiShift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group People Analytics
 *
 * Endpoints para consultar e inserir KPIs de fluxo de pessoas por turno.
 * Dados podem vir de sincronização com FastAPI ou inserção manual.
 */
class PeopleAnalyticsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PeopleAnalyticsSyncService $syncService
    ) {
    }

    /**
     * Get People KPIs for a store and date.
     *
     * GET /api/v1/analytics/people/shift?store_id=&date=
     */
    public function shift(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $date = Carbon::parse($request->input('date'));

        $data = $this->syncService->getStoredKpis($storeId, $date);

        return $this->success([
            'date' => $date->format('Y-m-d'),
            'store_id' => $storeId,
            'shifts' => $data['shifts'],
            'totals' => $data['totals'],
        ]);
    }

    /**
     * Manually insert KPI data.
     *
     * POST /api/v1/analytics/people/shift
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'in:M,T,N'],
            'in_count' => ['required', 'integer', 'min:0'],
            'out_count' => ['required', 'integer', 'min:0'],
            'staff_in' => ['sometimes', 'integer', 'min:0'],
            'staff_out' => ['sometimes', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $kpi = $this->syncService->insertManualKpi(
            $storeId,
            Carbon::parse($request->input('date')),
            $request->input('shift_code'),
            $request->only(['in_count', 'out_count', 'staff_in', 'staff_out'])
        );

        return $this->created($kpi);
    }
}
