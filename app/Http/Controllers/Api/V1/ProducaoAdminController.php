<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProducaoCarrinhoService;
use Illuminate\Http\JsonResponse;

/**
 * @group Produção - Administração
 *
 * Ações administrativas do sistema de produção.
 *
 * **Permissões:** Apenas super administradores.
 */
class ProducaoAdminController extends Controller
{
    public function __construct(
        private readonly ProducaoCarrinhoService $carrinhoService
    ) {
    }

    /**
     * Limpar capas órfãs
     *
     * Libera capas que ficaram presas em pedidos cancelados, retornando-as ao status
     * "Encomenda Solicitada" para poderem ser adicionadas a novos pedidos.
     *
     * **Quem pode usar:** Super administradores.
     *
     * **Quando usar:**
     * - Após cancelamento manual de pedidos
     * - Se houver capas em status inconsistente
     * - Manutenção periódica do sistema
     *
     * @response 200 scenario="Capas liberadas" {
     *   "message": "5 capa(s) liberada(s) de pedidos cancelados.",
     *   "data": {"released_count": 5}
     * }
     *
     * @response 200 scenario="Nenhuma capa órfã" {
     *   "message": "0 capa(s) liberada(s) de pedidos cancelados.",
     *   "data": {"released_count": 0}
     * }
     */
    public function cleanupOrphanedItems(): JsonResponse
    {
        $releasedCount = $this->carrinhoService->cleanupOrphanedCapas();

        return response()->json([
            'message' => "{$releasedCount} capa(s) liberada(s) de pedidos cancelados.",
            'data' => [
                'released_count' => $releasedCount,
            ],
        ]);
    }
}

