<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProducaoEventoResource;
use App\Http\Resources\ProducaoPedidoResource;
use App\Models\ProducaoPedido;
use App\Services\ProducaoPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Produção - Pedidos (Admin)
 *
 * Gestão de pedidos de produção pelo administrador.
 * Estes endpoints são utilizados após o fechamento do carrinho de produção.
 *
 * **Fluxo do pedido:**
 * 1. Pedido criado ao fechar carrinho → Status `pending`
 * 2. Fábrica aceita → Status `accepted`
 * 3. Fábrica despacha → Status `dispatched`
 * 4. Admin recebe → Status `received` (este controller)
 * 5. Ou Admin cancela → Status `cancelled`
 *
 * **Permissões:** Administradores e gerentes.
 */
class ProducaoPedidoController extends Controller
{
    public function __construct(
        private readonly ProducaoPedidoService $pedidoService
    ) {
    }

    /**
     * Listar pedidos de produção
     *
     * Retorna lista paginada de pedidos de produção com filtros.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @queryParam status string Filtrar por status: `cart`, `pending`, `accepted`, `dispatched`, `received`, `cancelled`. Example: pending
     * @queryParam initial_date string Data inicial (ISO 8601). Example: 2026-01-01
     * @queryParam final_date string Data final (ISO 8601). Example: 2026-01-31
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de pedidos" {
     *   "data": [{
     *     "id": 1,
     *     "status": "dispatched",
     *     "items_count": 10,
     *     "factory_total": 150.00,
     *     "tracking_code": "BR123456789XX",
     *     "created_at": "2026-01-13T15:00:00+00:00"
     *   }],
     *   "meta": {"current_page": 1, "total": 25}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'initial_date', 'final_date']);
        $perPage = min((int) $request->input('per_page', 15), 100);

        $pedidos = $this->pedidoService->list($filters, $perPage);

        return ProducaoPedidoResource::collection($pedidos);
    }

    /**
     * Detalhes do pedido
     *
     * Retorna detalhes completos do pedido com itens e timeline de eventos.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     *
     * @response 200 scenario="Detalhes do pedido" {
     *   "data": {
     *     "id": 1,
     *     "status": "dispatched",
     *     "items": [{"id": 1, "capa_id": 10, "customer_name": "João"}],
     *     "events": [{"type": "dispatched", "created_at": "2026-01-13T15:00:00+00:00"}],
     *     "factory_total": 150.00,
     *     "tracking_code": "BR123456789XX"
     *   }
     * }
     *
     * @response 404 scenario="Não encontrado" {"message": "Not found."}
     */
    public function show(int $pedidoId): JsonResponse
    {
        $pedido = $this->pedidoService->getDetails($pedidoId);

        return response()->json([
            'data' => new ProducaoPedidoResource($pedido),
        ]);
    }

    /**
     * Timeline do pedido
     *
     * Retorna apenas a timeline de eventos do pedido, ordenada do mais recente.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     *
     * @response 200 scenario="Timeline" {
     *   "data": [
     *     {"type": "dispatched", "description": "Pedido despachado", "created_at": "2026-01-15T10:00:00+00:00"},
     *     {"type": "accepted", "description": "Pedido aceito pela fábrica", "created_at": "2026-01-14T10:00:00+00:00"},
     *     {"type": "created", "description": "Pedido criado", "created_at": "2026-01-13T15:00:00+00:00"}
     *   ]
     * }
     */
    public function timeline(ProducaoPedido $pedido): AnonymousResourceCollection
    {
        $eventos = $pedido->eventos()->orderBy('created_at', 'desc')->get();

        return ProducaoEventoResource::collection($eventos);
    }

    /**
     * Receber pedido
     *
     * Registra o recebimento do pedido da fábrica.
     * O status do pedido muda de `dispatched` para `received`.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * **Regras de negócio:**
     * - Apenas pedidos com status `dispatched` podem ser recebidos
     * - As capas do pedido são atualizadas para "Pronta para entrega"
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam observation string Observações do recebimento. Example: Recebido em bom estado
     *
     * @response 200 scenario="Pedido recebido" {
     *   "message": "Pedido recebido com sucesso.",
     *   "data": {"id": 1, "status": "received"}
     * }
     */
    public function receive(Request $request, ProducaoPedido $pedido): JsonResponse
    {
        $request->validate([
            'observation' => ['nullable', 'string', 'max:2000'],
        ]);

        $pedido = $this->pedidoService->receive($pedido, $request->input('observation'));

        return response()->json([
            'message' => 'Pedido recebido com sucesso.',
            'data' => new ProducaoPedidoResource($pedido->load(['itens', 'eventos'])),
        ]);
    }

    /**
     * Cancelar pedido
     *
     * Cancela um pedido de produção. As capas são liberadas e voltam ao status anterior.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * **Regras de negócio:**
     * - Apenas pedidos com status `pending` ou `cart` podem ser cancelados
     * - A fábrica deve ser notificada manualmente se necessário
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam reason string Motivo do cancelamento. Example: Cliente desistiu
     *
     * @response 200 scenario="Pedido cancelado" {
     *   "message": "Pedido cancelado.",
     *   "data": {"id": 1, "status": "cancelled"}
     * }
     *
     * @response 422 scenario="Não pode cancelar" {"message": "Pedido não pode ser cancelado neste status."}
     */
    public function cancel(Request $request, ProducaoPedido $pedido): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $pedido = $this->pedidoService->cancel($pedido, $request->input('reason'));

        return response()->json([
            'message' => 'Pedido cancelado.',
            'data' => new ProducaoPedidoResource($pedido),
        ]);
    }
}

