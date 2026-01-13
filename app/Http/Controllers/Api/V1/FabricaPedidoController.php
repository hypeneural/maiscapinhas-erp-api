<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProducaoPedidoResource;
use App\Models\ProducaoPedido;
use App\Services\ProducaoPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FabricaPedidoController extends Controller
{
    public function __construct(
        private readonly ProducaoPedidoService $pedidoService
    ) {
    }

    /**
     * GET /api/v1/fabrica/pedidos
     * 
     * List production orders visible to factory.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'initial_date', 'final_date']);
        $perPage = min((int) $request->input('per_page', 15), 100);

        $pedidos = $this->pedidoService->list($filters, $perPage);

        return ProducaoPedidoResource::collection($pedidos);
    }

    /**
     * GET /api/v1/fabrica/pedidos/{pedido}
     * 
     * Get production order details.
     */
    public function show(int $pedidoId): JsonResponse
    {
        $pedido = $this->pedidoService->getDetails($pedidoId);

        // Ensure factory can only see visible orders
        if (!$pedido->status->isVisibleToFactory()) {
            abort(403, 'Pedido não disponível.');
        }

        return response()->json([
            'data' => new ProducaoPedidoResource($pedido),
        ]);
    }

    /**
     * PATCH /api/v1/fabrica/pedidos/{pedido}/aceitar
     * 
     * Factory accepts the order.
     */
    public function accept(Request $request, ProducaoPedido $pedido): JsonResponse
    {
        $validated = $request->validate([
            'factory_total' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'factory_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pedido = $this->pedidoService->accept(
            $pedido,
            (float) $validated['factory_total'],
            $validated['factory_notes'] ?? null
        );

        return response()->json([
            'message' => 'Pedido aceito com sucesso.',
            'data' => new ProducaoPedidoResource($pedido->load(['itens', 'eventos'])),
        ]);
    }

    /**
     * PATCH /api/v1/fabrica/pedidos/{pedido}/despachar
     * 
     * Factory dispatches the order.
     */
    public function dispatch(Request $request, ProducaoPedido $pedido): JsonResponse
    {
        $validated = $request->validate([
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'factory_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pedido = $this->pedidoService->dispatch(
            $pedido,
            $validated['tracking_code'] ?? null,
            $validated['factory_notes'] ?? null
        );

        return response()->json([
            'message' => 'Pedido despachado com sucesso.',
            'data' => new ProducaoPedidoResource($pedido->load(['itens', 'eventos'])),
        ]);
    }

    /**
     * GET /api/v1/fabrica/pedidos/{pedido}/itens/{item}/foto
     * 
     * Download item photo.
     */
    public function downloadPhoto(ProducaoPedido $pedido, int $itemId): StreamedResponse|JsonResponse
    {
        $item = $pedido->itens()->findOrFail($itemId);

        if (!$item->photo_url) {
            return response()->json(['message' => 'Foto não disponível.'], 404);
        }

        // Extract path from URL if needed
        $photoPath = $item->capaPersonalizada?->photo_path;

        if (!$photoPath || !Storage::disk('public')->exists($photoPath)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($photoPath, 'capa_' . $item->capa_personalizada_id . '.jpg');
    }
}
