<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserKpiRequest;
use App\Http\Resources\UserKpiResource;
use App\Services\UserKpiService;
use Illuminate\Http\JsonResponse;

class UserKpiController extends Controller
{
    public function __construct(
        private readonly UserKpiService $kpiService
    ) {
    }

    /**
     * Get aggregated KPIs for users.
     *
     * Returns statistical data about users without exposing personal information.
     * Useful for dashboard cards and charts.
     */
    public function __invoke(UserKpiRequest $request): JsonResponse
    {
        $filters = $request->getFilters();
        $kpis = $this->kpiService->calculate($filters);

        return response()->json(new UserKpiResource($kpis));
    }
}
