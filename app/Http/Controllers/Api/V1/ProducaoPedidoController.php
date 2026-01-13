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

class ProducaoPedidoController extends Controller
{
    public function __construct(
        private readonly ProducaoPedidoService $pedidoService
    ) {
    }

    /**
     * GET /api/v1/producao/pedidos
     * 
     * List production orders with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'initial_date', 'final_date']);
        $perPage = min((int) $request->input('per_page', 15), 100);

        $pedidos = $this->pedidoService->list($filters, $perPage);

        return ProducaoPedidoResource::collection($pedidos);
    }

    /**
     * GET /api/v1/producao/pedidos/{pedido}
     * 
     * Get production order details with items and timeline.
     */
    public function show(int $pedidoId): JsonResponse
    {
        $pedido = $this->pedidoService->getDetails($pedidoId);

        return response()->json([
            'data' => new ProducaoPedidoResource($pedido),
        ]);
    }

    /**
     * GET /api/v1/producao/pedidos/{pedido}/timeline
     * 
     * Get production order timeline only.
     */
    public function timeline(ProducaoPedido $pedido): AnonymousResourceCollection
    {
        $eventos = $pedido->eventos()->orderBy('created_at', 'desc')->get();

        return ProducaoEventoResource::collection($eventos);
    }

    /**
     * PATCH /api/v1/producao/pedidos/{pedido}/receber
     * 
     * Admin receives the order.
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
     * DELETE /api/v1/producao/pedidos/{pedido}
     * 
     * Cancel the order.
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
