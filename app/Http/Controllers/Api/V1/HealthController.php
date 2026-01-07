<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * @group Saúde & Versão
 *
 * Endpoints para verificação de saúde e status da API.
 * Estes endpoints são públicos e não requerem autenticação.
 */
class HealthController extends Controller
{
    /**
     * Verificar saúde da API
     *
     * Retorna o status de saúde da API. Use este endpoint para
     * verificar se a API está online e respondendo corretamente.
     *
     * **Casos de uso:**
     * - Monitoramento de uptime (Uptime Robot, Pingdom, etc.)
     * - Health checks em load balancers
     * - Verificação de conectividade antes de operações
     *
     * **Resposta:**
     * - `status: "ok"` - API funcionando normalmente
     * - `timestamp` - Data/hora ISO 8601 da requisição
     *
     * @unauthenticated
     *
     * @response 200 scenario="API saudável" {
     *   "data": {
     *     "status": "ok",
     *     "timestamp": "2026-01-07T12:00:00+00:00"
     *   }
     * }
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
