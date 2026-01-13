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

/**
 * @group Fábrica - Pedidos de Produção
 *
 * Portal da fábrica para gestão de pedidos de produção de capas personalizadas.
 * Estes endpoints são exclusivos para usuários com papel de fábrica (middleware `fabrica`).
 *
 * **Fluxo de produção:**
 * 1. Admin envia capas para produção → Pedido criado com status `pending`
 * 2. Fábrica aceita pedido (informa valor) → Status `accepted`
 * 3. Fábrica despacha pedido (informa rastreio) → Status `dispatched`
 * 4. Admin recebe pedido → Status `received`
 *
 * **Permissões:** Apenas usuários com papel de fábrica.
 */
class FabricaPedidoController extends Controller
{
    public function __construct(
        private readonly ProducaoPedidoService $pedidoService
    ) {
    }

    /**
     * Listar pedidos de produção
     *
     * Retorna lista paginada de pedidos visíveis à fábrica.
     * Apenas pedidos com status visível à fábrica são retornados.
     *
     * **Quem pode usar:** Usuários da fábrica.
     *
     * @queryParam status string Filtrar por status: `pending`, `accepted`, `dispatched`. Example: pending
     * @queryParam initial_date string Data inicial (ISO 8601). Example: 2026-01-01
     * @queryParam final_date string Data final (ISO 8601). Example: 2026-01-31
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de pedidos" {
     *   "data": [{
     *     "id": 1,
     *     "status": "pending",
     *     "items_count": 10,
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
     * Retorna detalhes completos do pedido com itens e eventos de timeline.
     *
     * **Quem pode usar:** Usuários da fábrica.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     *
     * @response 200 scenario="Detalhes do pedido" {
     *   "data": {
     *     "id": 1,
     *     "status": "pending",
     *     "items": [{"id": 1, "capa_id": 10, "photo_url": "https://..."}],
     *     "events": [{"type": "created", "created_at": "2026-01-13T15:00:00+00:00"}]
     *   }
     * }
     *
     * @response 403 scenario="Pedido não disponível" {"message": "Pedido não disponível."}
     * @response 404 scenario="Não encontrado" {"message": "Not found."}
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
     * Aceitar pedido
     *
     * A fábrica aceita o pedido e informa o valor total de produção.
     * O status do pedido muda de `pending` para `accepted`.
     *
     * **Quem pode usar:** Usuários da fábrica.
     *
     * **Regras de negócio:**
     * - Apenas pedidos com status `pending` podem ser aceitos
     * - O valor total é obrigatório

     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam factory_total number required Valor total da produção (R$). Example: 150.00
     * @bodyParam factory_notes string Observações da fábrica. Example: Prazo de 5 dias úteis
     *
     * @response 200 scenario="Pedido aceito" {
     *   "message": "Pedido aceito com sucesso.",
     *   "data": {"id": 1, "status": "accepted", "factory_total": 150.00}
     * }
     *
     * @response 422 scenario="Validação falhou" {"message": "The factory_total field is required."}
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
     * Despachar pedido
     *
     * A fábrica registra o despacho do pedido, opcionalmente informando código de rastreio.
     * O status do pedido muda de `accepted` para `dispatched`.
     *
     * **Quem pode usar:** Usuários da fábrica.
     *
     * **Regras de negócio:**
     * - Apenas pedidos com status `accepted` podem ser despachados
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam tracking_code string Código de rastreio dos Correios/transportadora. Example: BR123456789XX
     * @bodyParam factory_notes string Observações do despacho. Example: Enviado via Sedex
     *
     * @response 200 scenario="Pedido despachado" {
     *   "message": "Pedido despachado com sucesso.",
     *   "data": {"id": 1, "status": "dispatched", "tracking_code": "BR123456789XX"}
     * }
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
     * Download da foto do item
     *
     * Faz download da foto da capa personalizada de um item do pedido.
     * Útil para a fábrica baixar as artes para produção.
     *
     * **Quem pode usar:** Usuários da fábrica.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @urlParam item integer required ID do item. Example: 10
     *
     * @response 200 scenario="Download da foto" file
     * @response 404 scenario="Foto não disponível" {"message": "Foto não disponível."}
     * @response 404 scenario="Arquivo não encontrado" {"message": "Arquivo não encontrado."}
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

