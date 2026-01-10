<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Reports\Services\CashIntegrityService;
use App\Domains\Reports\Services\StorePerformanceService;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Relatórios Gerenciais
 *
 * Endpoints de relatórios avançados para gestão e auditoria.
 *
 * Este módulo fornece KPIs estratégicos para tomada de decisão:
 * - **Performance de Loja** - Atingimento de meta, crescimento YoY, projeções
 * - **Integridade de Caixa** - % de quebra, divergências, alertas
 *
 * **Público-alvo:**
 * - Gerentes e Admins para visão estratégica
 * - Conferentes para auditoria de caixa
 * - Sócios para acompanhamento financeiro
 *
 * **Cache:** Dados históricos (ano passado) são cacheados para performance.
 */
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StorePerformanceService $performanceService,
        private CashIntegrityService $integrityService
    ) {
    }

    /**
     * Performance da Loja
     *
     * Retorna métricas completas de performance de uma loja,
     * incluindo atingimento de meta, comparação YoY e projeções.
     *
     * Este relatório é equivalente à planilha de "Desempenho de Lojas"
     * que o gestor mantinha manualmente.
     *
     * **Métricas retornadas:**
     * | Grupo | Métricas |
     * |-------|----------|
     * | `sales` | Vendas atuais, meta, atingimento %, restante |
     * | `comparison` | Mesmo período ano passado, total ano passado, crescimento YoY |
     * | `forecast` | Projeção linear, projeção por tendência, status |
     *
     * **Status de projeção:**
     * - `ON_TRACK` - Projeção >= 100% da meta
     * - `AT_RISK` - Projeção entre 90-100% da meta
     * - `BEHIND` - Projeção < 90% da meta
     *
     * **Quem pode usar:** Gerentes e Admins da loja.
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam month string Mês (YYYY-MM), default: mês atual. Example: 2026-01
     *
     * @response 200 scenario="Performance da loja" {
     *   "data": {
     *     "store_id": 1,
     *     "period": "2026-01",
     *     "days_elapsed": 15,
     *     "days_total": 31,
     *     "sales": {
     *       "current_amount": 31981.29,
     *       "goal_amount": 52000.00,
     *       "achievement_rate": 61.50,
     *       "remaining_to_goal": 20018.71
     *     },
     *     "comparison": {
     *       "same_period_last_year": 26950.00,
     *       "total_last_year_month": 55835.00,
     *       "yoy_growth": 18.60
     *     },
     *     "forecast": {
     *       "linear_projection": 66100.00,
     *       "trend_projection": 66220.31,
     *       "status": "ON_TRACK"
     *     }
     *   }
     * }
     */
    public function storePerformance(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        // Verificar se é gerente ou admin
        $role = $user->roleInStore($storeId);
        if (!in_array($role, ['admin', 'gerente'])) {
            return $this->forbidden('Apenas gerentes e admins podem acessar este relatório.');
        }

        $performance = $this->performanceService->getPerformance($storeId, $month);

        return $this->success($performance);
    }

    /**
     * Performance Consolidada (Multi-Loja)
     *
     * Retorna performance de todas as lojas que o usuário administra,
     * com totais consolidados.
     *
     * Ideal para sócios e admins que gerenciam múltiplas unidades.
     *
     * **Quem pode usar:** Admins e Gerentes.
     *
     * @queryParam month string Mês (YYYY-MM), default: mês atual. Example: 2026-01
     *
     * @response 200 scenario="Performance multi-loja" {
     *   "data": {
     *     "period": "2026-01",
     *     "stores": [
     *       { "store_id": 1, "sales": { "current_amount": 31981.29 }, "forecast": { "status": "ON_TRACK" } }
     *     ],
     *     "consolidated": {
     *       "total_sales": 95000.00,
     *       "total_goal": 156000.00,
     *       "total_achievement_rate": 60.90,
     *       "total_linear_projection": 198000.00
     *     }
     *   }
     * }
     */
    public function consolidatedPerformance(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        // Super admin vê todas as lojas
        if ($user->isSuperAdmin()) {
            $userStoreIds = \App\Models\Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()
                ->whereIn('role', ['admin', 'gerente'])
                ->pluck('store_id')
                ->toArray();
        }

        if (empty($userStoreIds)) {
            return $this->forbidden('Você não tem acesso administrativo a nenhuma loja.');
        }


        $performance = $this->performanceService->getMultiStorePerformance($userStoreIds, $month);

        return $this->success($performance);
    }

    /**
     * Integridade de Caixa
     *
     * Retorna métricas de auditoria de caixa para uma loja,
     * incluindo % de quebra, divergências e alertas.
     *
     * Este relatório é crítico para:
     * - Identificar possíveis fraudes ou roubos
     * - Monitorar qualidade operacional
     * - Decidir pagamento de bônus
     *
     * **Métricas retornadas:**
     * | Grupo | Métricas |
     * |-------|----------|
     * | `cash_integrity` | Valores sistema vs real, % quebra, status |
     * | `divergence_analysis` | Justificadas vs não justificadas |
     * | `workflow_status` | Turnos totais, fechados, pendentes |
     * | `alerts` | Alertas automáticos baseados em thresholds |
     *
     * **Alertas gerados:**
     * - `HIGH_CASH_BREAK` (CRITICAL) - Quebra > 5%
     * - `ELEVATED_CASH_BREAK` (WARNING) - Quebra > 2%
     * - `UNJUSTIFIED_DIVERGENCES` (WARNING) - Divergências sem justificativa
     * - `PENDING_BACKLOG` (INFO) - Muitos fechamentos pendentes
     *
     * **Quem pode usar:** Conferentes, Gerentes e Admins.
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam month string Mês (YYYY-MM), default: mês atual. Example: 2026-01
     *
     * @response 200 scenario="Integridade de caixa" {
     *   "data": {
     *     "store_id": 1,
     *     "period": "2026-01",
     *     "cash_integrity": {
     *       "total_system_value": 150000.00,
     *       "total_real_value": 146250.00,
     *       "total_divergence": -3750.00,
     *       "cash_break_percentage": 2.5,
     *       "status": "YELLOW"
     *     },
     *     "divergence_analysis": {
     *       "total_lines_with_divergence": 15,
     *       "justified_count": 12,
     *       "unjustified_count": 3,
     *       "justified_rate": 80.00
     *     },
     *     "workflow_status": {
     *       "total_shifts": 45,
     *       "closed_count": 40,
     *       "pending_approval": 3,
     *       "completion_rate": 88.89
     *     },
     *     "alerts": [
     *       { "type": "WARNING", "code": "ELEVATED_CASH_BREAK", "message": "Quebra de 2.50% acima do limite" }
     *     ]
     *   }
     * }
     */
    public function cashIntegrity(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        // Verificar se pode ver integridade (conferente+)
        $role = $user->roleInStore($storeId);
        if (!in_array($role, ['admin', 'gerente', 'conferente'])) {
            return $this->forbidden('Apenas conferentes, gerentes e admins podem acessar este relatório.');
        }

        $integrity = $this->integrityService->getIntegrityReport($storeId, $month);

        return $this->success($integrity);
    }
}
