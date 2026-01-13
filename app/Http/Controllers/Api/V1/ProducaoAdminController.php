<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProducaoCarrinhoService;
use Illuminate\Http\JsonResponse;

class ProducaoAdminController extends Controller
{
    public function __construct(
        private readonly ProducaoCarrinhoService $carrinhoService
    ) {
    }

    /**
     * POST /api/v1/producao/admin/limpar-itens-cancelados
     * 
     * Cleanup orphaned capas from cancelled production orders.
     * This releases capas that are stuck in cancelled carts back to "Encomenda Solicitada" status.
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
