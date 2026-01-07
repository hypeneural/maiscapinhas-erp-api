<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\StoreMonthlyGoal;
use App\Services\GoalsService;
use App\Support\Tenancy\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Metas Mensais
 *
 * Gerenciamento de metas mensais por loja e distribuição entre vendedores (splits).
 * Cada split define a porcentagem da meta que cada vendedor deve atingir.
 *
 * **Regra:** A soma dos splits deve ser exatamente 100%.
 */
class MonthlyGoalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GoalsService $goalsService,
        private StoreContext $storeContext
    ) {
    }

    /**
     * List monthly goals.
     *
     * GET /api/v1/goals/monthly
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = StoreMonthlyGoal::with(['store:id,name', 'splits.user:id,name'])
            ->whereIn('store_id', $storeIds)
            ->orderByDesc('month');

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $storeIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        $goals = $query->paginate($request->input('per_page', 25));

        return $this->paginated($goals);
    }

    /**
     * Show a specific goal.
     *
     * GET /api/v1/goals/monthly/{goal}
     */
    public function show(Request $request, StoreMonthlyGoal $goal): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($goal->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success($goal->load(['store:id,name', 'splits.user:id,name']));
    }

    /**
     * Create a new monthly goal.
     *
     * POST /api/v1/goals/monthly
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'goal_amount' => ['required', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        $this->storeContext->validateAccess($storeId, $user)->requireManager();

        // Check if goal already exists
        $existing = StoreMonthlyGoal::forStore($storeId)
            ->forMonth($request->input('month'))
            ->exists();

        if ($existing) {
            return $this->conflict('A goal already exists for this store and month.');
        }

        $goal = $this->goalsService->createGoal($request->all(), $user);

        return $this->created($goal->load(['store:id,name', 'splits.user:id,name']));
    }

    /**
     * Update a monthly goal.
     *
     * PUT /api/v1/goals/monthly/{goal}
     */
    public function update(Request $request, StoreMonthlyGoal $goal): JsonResponse
    {
        $request->validate([
            'goal_amount' => ['sometimes', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $this->storeContext->validateAccess($goal->store_id, $user)->requireManager();

        $goal = $this->goalsService->updateGoal($goal, $request->all(), $user);

        return $this->success($goal->load(['store:id,name', 'splits.user:id,name']));
    }

    /**
     * Set splits for a goal.
     *
     * PUT /api/v1/goals/monthly/{goal}/splits
     */
    public function setSplits(Request $request, StoreMonthlyGoal $goal): JsonResponse
    {
        $request->validate([
            'splits' => ['required', 'array', 'min:1'],
            'splits.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'splits.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $user = $request->user();
        $this->storeContext->validateAccess($goal->store_id, $user)->requireManager();

        $goal = $this->goalsService->setSplits($goal, $request->input('splits'), $user);

        return $this->success($goal->load(['store:id,name', 'splits.user:id,name']));
    }

    /**
     * Delete a monthly goal.
     *
     * DELETE /api/v1/goals/monthly/{goal}
     */
    public function destroy(Request $request, StoreMonthlyGoal $goal): JsonResponse
    {
        $user = $request->user();
        $this->storeContext->validateAccess($goal->store_id, $user)->requireManager();

        $goal->delete();

        return $this->success(['message' => 'Goal deleted.']);
    }
}
