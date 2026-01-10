<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\StoreBonusRuleRequest;
use App\Http\Traits\ApiResponse;
use App\Models\BonusRule;
use App\Services\RulesService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Regras de Bônus
 *
 * Gerenciamento de regras de bônus diário.
 * Regras definem faixas de vendas mínimas e valores de bônus correspondentes.
 *
 * **Hierarquia de regras:**
 * - Regra específica de loja (`store_id` preenchido) tem prioridade sobre regra global
 * - Regra mais recente (`effective_from` maior) tem prioridade
 * - Versionamento automático a cada atualização
 */
class BonusRuleController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private RulesService $rulesService
    ) {
    }

    /**
     * List bonus rules.
     *
     * GET /api/v1/rules/bonus
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BonusRule::class);

        $user = $request->user();

        $query = BonusRule::with('store:id,name')
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
     * Show a specific bonus rule.
     *
     * GET /api/v1/rules/bonus/{rule}
     */
    public function show(BonusRule $rule): JsonResponse
    {
        $this->authorize('view', $rule);

        return $this->success($rule->load('store:id,name'));
    }

    /**
     * Create a new bonus rule.
     *
     * POST /api/v1/rules/bonus
     */
    public function store(StoreBonusRuleRequest $request): JsonResponse
    {
        $this->authorize('create', BonusRule::class);

        // If store_id provided, validate user has access
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!$request->user()->hasAccessToStore($storeId)) {
                return $this->forbidden('You do not have access to this store.');
            }
        }

        $rule = $this->rulesService->createBonusRule(
            $request->validated(),
            $request->user()
        );

        return $this->created($rule->load('store:id,name'));
    }

    /**
     * Update a bonus rule.
     *
     * PUT /api/v1/rules/bonus/{rule}
     */
    public function update(StoreBonusRuleRequest $request, BonusRule $rule): JsonResponse
    {
        $this->authorize('update', $rule);

        $rule = $this->rulesService->updateBonusRule(
            $rule,
            $request->validated(),
            $request->user()
        );

        return $this->success($rule->load('store:id,name'));
    }

    /**
     * Delete a bonus rule.
     *
     * DELETE /api/v1/rules/bonus/{rule}
     */
    public function destroy(Request $request, BonusRule $rule): JsonResponse
    {
        $this->authorize('delete', $rule);

        $rule->delete();

        return $this->success(['message' => 'Bonus rule deleted.']);
    }
}
