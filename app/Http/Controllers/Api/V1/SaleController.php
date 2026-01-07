<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Vendas
 *
 * Endpoints para consultar vendas registradas.
 * Vendas são registradas automaticamente via integração PDV ou manualmente.
 */
class SaleController extends Controller
{
    use ApiResponse;

    /**
     * Listar vendas
     *
     * Retorna a lista de vendas das lojas às quais o usuário tem acesso,
     * com filtros por loja, vendedor e período.
     *
     * **Quem pode usar:** Qualquer usuário autenticado (vê apenas suas lojas).
     *
     * **Filtros disponíveis:**
     * - `store_id` - Filtrar por loja específica
     * - `seller_id` - Filtrar por vendedor específico
     * - `from` / `to` - Período de datas (formato YYYY-MM-DD)
     *
     * **Paginação:** Resultados paginados (padrão 25 por página, máx 100).
     *
     * @queryParam store_id integer ID da loja para filtrar. Example: 1
     * @queryParam seller_id integer ID do vendedor para filtrar. Example: 6
     * @queryParam from string Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to string Data final (YYYY-MM-DD). Example: 2026-01-31
     * @queryParam per_page integer Itens por página (1-100). Example: 25
     *
     * @response 200 scenario="Lista de vendas" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "store_id": 1,
     *       "seller_id": 6,
     *       "sold_at": "2026-01-07T10:30:00+00:00",
     *       "amount": "150.00",
     *       "source": "pdv",
     *       "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *       "seller": { "id": 6, "name": "João Vendedor" }
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "per_page": 25,
     *     "total": 150,
     *     "last_page": 6
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = Sale::with(['store:id,name', 'seller:id,name'])
            ->whereIn('store_id', $userStoreIds);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->input('seller_id'));
        }

        if ($request->filled('from')) {
            $query->where('sold_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('sold_at', '<=', $request->input('to') . ' 23:59:59');
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('sold_at')->paginate($perPage);

        return $this->paginated($paginator);
    }
}
