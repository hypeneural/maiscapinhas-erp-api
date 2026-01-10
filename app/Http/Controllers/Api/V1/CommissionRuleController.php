<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\StoreCommissionRuleRequest;
use App\Http\Traits\ApiResponse;
use App\Models\CommissionRule;
use App\Services\RulesService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Regras de Comissão
 *
 * Gerenciamento de regras de comissão mensal.
 * Regras definem faixas de atingimento de meta e taxas de comissão.
 *
 * **Hierarquia de regras:**
 * - Regra específica de loja tem prioridade sobre global
 * - Comissão calculada: vendas × taxa (baseada no % de atingimento)
 */
class CommissionRuleController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private RulesService $rulesService
    ) {
    }

    /**
     * List commission rules.
     *
     * GET /api/v1/rules/commission
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionRule::class);

        $user = $request->user();

        $query = CommissionRule::with('store:id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('version');

        // Super admin sees all rules; other users filter by their stores
        if (!$user->isSuperAdmin()) {
            $storeIds = $user->storeUsers()->pluck('store_id')->toArray();
            $query->where(function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds)
                    ->orWhereNull('store_id');
            });
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        $rules = $query->paginate($request->input('per_page', 25));

        return $this->paginated($rules);
    }

    /**
     * Show a specific commission rule.
     *
     * GET /api/v1/rules/commission/{rule}
     */
    public function show(CommissionRule $rule): JsonResponse
    {
        $this->authorize('view', $rule);

        return $this->success($rule->load('store:id,name'));
    }

    /**
     * Create a new commission rule.
     *
     * POST /api/v1/rules/commission
     */
    public function store(StoreCommissionRuleRequest $request): JsonResponse
    {
        $this->authorize('create', CommissionRule::class);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!$request->user()->hasAccessToStore($storeId)) {
                return $this->forbidden('You do not have access to this store.');
            }
        }

        $rule = $this->rulesService->createCommissionRule(
            $request->validated(),
            $request->user()
        );

        return $this->created($rule->load('store:id,name'));
    }

    /**
     * Update a commission rule.
     *
     * PUT /api/v1/rules/commission/{rule}
     */
    public function update(StoreCommissionRuleRequest $request, CommissionRule $rule): JsonResponse
    {
        $this->authorize('update', $rule);

        $rule = $this->rulesService->updateCommissionRule(
            $rule,
            $request->validated(),
            $request->user()
        );

        return $this->success($rule->load('store:id,name'));
    }

    /**
     * Delete a commission rule.
     *
     * DELETE /api/v1/rules/commission/{rule}
     */
    public function destroy(Request $request, CommissionRule $rule): JsonResponse
    {
        $this->authorize('delete', $rule);

        $rule->delete();

        return $this->success(['message' => 'Commission rule deleted.']);
    }
}
