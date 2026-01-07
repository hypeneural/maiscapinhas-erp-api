<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Turnos de Caixa
 *
 * Endpoints para gerenciar turnos de caixa.
 * Cada turno representa um período de trabalho de um vendedor em uma loja.
 */
class CashShiftController extends Controller
{
    use ApiResponse;

    /**
     * Listar turnos de caixa
     *
     * Retorna os turnos de caixa das lojas às quais o usuário tem acesso,
     * com filtros por loja, data e status.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Filtros disponíveis:**
     * - `store_id` - Filtrar por loja específica
     * - `date` - Filtrar por data específica (YYYY-MM-DD)
     * - `status` - Filtrar por status (open, closed, pending)
     *
     * **Códigos de turno:**
     * - `M` - Manhã
     * - `T` - Tarde
     * - `N` - Noite
     *
     * @queryParam store_id integer ID da loja para filtrar. Example: 1
     * @queryParam date string Data específica (YYYY-MM-DD). Example: 2026-01-07
     * @queryParam status string Status do turno. Example: open
     * @queryParam per_page integer Itens por página (1-100). Example: 25
     *
     * @response 200 scenario="Lista de turnos" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "store_id": 1,
     *       "date": "2026-01-07",
     *       "shift_code": "M",
     *       "seller_id": 6,
     *       "status": "open",
     *       "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *       "seller": { "id": 6, "name": "João Vendedor" },
     *       "cash_closing": null
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 84 }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,closed,pending'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = CashShift::with(['store:id,name', 'seller:id,name', 'cashClosing:id,cash_shift_id,status'])
            ->whereIn('store_id', $userStoreIds);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        if ($request->filled('date')) {
            $query->where('date', $request->input('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('date')->orderBy('shift_code')->paginate($perPage);

        return $this->paginated($paginator);
    }

    /**
     * Criar novo turno de caixa
     *
     * Cria um novo turno de caixa para uma loja/data/período.
     *
     * **Quem pode usar:** Usuários com acesso à loja.
     *
     * **Regras de negócio:**
     * - Não pode haver dois turnos com mesma loja + data + turno + vendedor
     * - O turno inicia com status `open`
     * - Se `seller_id` não for informado, assume o usuário atual
     *
     * **Códigos de turno:**
     * - `M` - Manhã (08h-14h)
     * - `T` - Tarde (14h-20h)
     * - `N` - Noite (20h-00h)
     *
     * @bodyParam store_id integer required ID da loja. Example: 1
     * @bodyParam date string required Data do turno (YYYY-MM-DD). Example: 2026-01-07
     * @bodyParam shift_code string required Código do turno (M, T ou N). Example: M
     * @bodyParam seller_id integer ID do vendedor (padrão: usuário atual). Example: 6
     *
     * @response 201 scenario="Turno criado" {
     *   "data": {
     *     "id": 1,
     *     "store_id": 1,
     *     "date": "2026-01-07",
     *     "shift_code": "M",
     *     "seller_id": 6,
     *     "status": "open",
     *     "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *     "seller": { "id": 6, "name": "João Vendedor" }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem acesso" {
     *   "error": { "code": 403, "message": "You do not have access to this store." }
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'in:M,T,N'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $sellerId = $request->input('seller_id', $user->id);

        $shift = CashShift::create([
            'store_id' => $storeId,
            'date' => $request->input('date'),
            'shift_code' => $request->input('shift_code'),
            'seller_id' => $sellerId,
            'status' => CashShift::STATUS_OPEN,
        ]);

        return $this->created($shift->load(['store:id,name', 'seller:id,name']));
    }

    /**
     * Obter detalhes de um turno
     *
     * Retorna os detalhes de um turno específico, incluindo o fechamento se existir.
     *
     * **Quem pode usar:** Usuários com acesso à loja.
     *
     * @urlParam shift integer required ID do turno. Example: 1
     *
     * @response 200 scenario="Turno encontrado" {
     *   "data": {
     *     "id": 1,
     *     "store_id": 1,
     *     "date": "2026-01-07",
     *     "shift_code": "M",
     *     "status": "closed",
     *     "cash_closing": {
     *       "id": 1,
     *       "status": "approved",
     *       "lines": []
     *     }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success(
            $shift->load(['store:id,name', 'seller:id,name', 'cashClosing.lines'])
        );
    }
}
