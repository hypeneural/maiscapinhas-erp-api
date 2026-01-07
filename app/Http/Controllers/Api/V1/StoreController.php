<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Lojas
 *
 * Endpoints para consultar lojas às quais o usuário tem acesso.
 * O usuário só pode visualizar lojas onde está vinculado via `store_users`.
 */
class StoreController extends Controller
{
    use ApiResponse;

    /**
     * Listar lojas do usuário
     *
     * Retorna todas as lojas ativas às quais o usuário autenticado tem acesso.
     * Cada loja inclui o papel (role) do usuário naquela loja.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Regras:**
     * - Apenas lojas ativas (`active = true`) são retornadas
     * - Apenas lojas onde o usuário está vinculado são listadas
     *
     * @response 200 scenario="Lista de lojas" {
     *   "data": [
     *     { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" },
     *     { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "gerente" }
     *   ],
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stores = Store::whereIn('id', $user->storeUsers()->pluck('store_id'))
            ->where('active', true)
            ->get()
            ->map(fn(Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'city' => $store->city,
                'role' => $user->roleInStore($store->id),
            ]);

        return $this->success($stores);
    }

    /**
     * Obter detalhes de uma loja
     *
     * Retorna os detalhes de uma loja específica, se o usuário tiver acesso.
     *
     * **Quem pode usar:** Usuários com vínculo na loja.
     *
     * **Erros possíveis:**
     * - `403` - Usuário não tem acesso a esta loja
     * - `404` - Loja não encontrada
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 scenario="Loja encontrada" {
     *   "data": {
     *     "id": 1,
     *     "name": "Mais Capinhas Tijucas",
     *     "city": "Tijucas",
     *     "active": true,
     *     "role": "admin",
     *     "created_at": "2026-01-01T00:00:00+00:00"
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem acesso" {
     *   "error": {
     *     "code": 403,
     *     "message": "You do not have access to this store."
     *   }
     * }
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($store->id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success([
            'id' => $store->id,
            'name' => $store->name,
            'city' => $store->city,
            'active' => $store->active,
            'role' => $user->roleInStore($store->id),
            'created_at' => $store->created_at->toIso8601String(),
        ]);
    }
}
