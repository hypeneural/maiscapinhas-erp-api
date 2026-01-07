<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * @group Saúde & Versão
 *
 * Endpoints para verificação de saúde e status da API.
 */
class VersionController extends Controller
{
    /**
     * Obter versão da API
     *
     * Retorna informações sobre a versão atual da API, incluindo
     * versões do PHP e Laravel utilizadas.
     *
     * **Casos de uso:**
     * - Verificar compatibilidade de versão antes de integrações
     * - Debug e troubleshooting
     * - Documentação técnica
     *
     * **Resposta:**
     * - `name` - Nome da API
     * - `api` - Versão da API (v1, v2, etc.)
     * - `php` - Versão do PHP
     * - `laravel` - Versão do Laravel
     *
     * @unauthenticated
     *
     * @response 200 scenario="Versão atual" {
     *   "data": {
     *     "name": "MaisCapinhas ERP API",
     *     "api": "v1",
     *     "php": "8.3.16",
     *     "laravel": "12.45.2"
     *   }
     * }
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => 'MaisCapinhas ERP API',
                'api' => 'v1',
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
        ]);
    }
}
