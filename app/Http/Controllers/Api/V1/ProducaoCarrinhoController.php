<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Producao\AddToCartRequest;
use App\Http\Requests\Producao\CloseCartRequest;
use App\Http\Resources\ProducaoPedidoResource;
use App\Services\ProducaoCarrinhoService;
use Illuminate\Http\JsonResponse;

class ProducaoCarrinhoController extends Controller
{
    public function __construct(
        private readonly ProducaoCarrinhoService $carrinhoService
    ) {
    }

    /**
     * GET /api/v1/producao/carrinho
     * 
     * Get or create the current open cart.
     */
    public function index(): JsonResponse
    {
        $carrinho = $this->carrinhoService->getOrCreateOpenCart();

        return response()->json([
            'data' => new ProducaoPedidoResource($carrinho->load(['itens.capaPersonalizada.customer', 'createdBy'])),
        ]);
    }

    /**
     * POST /api/v1/producao/carrinho/itens
     * 
     * Add capas to the cart.
     */
    public function addItems(AddToCartRequest $request): JsonResponse
    {
        $capaIds = $request->validated('capa_ids');
        $results = $this->carrinhoService->addToCart($capaIds);

        $addedCount = count($results['added']);
        $blockedCount = count($results['blocked']);

        $message = "{$addedCount} item(ns) adicionado(s)";
        if ($blockedCount > 0) {
            $message .= ", {$blockedCount} bloqueado(s)";
        }

        $statusCode = $blockedCount > 0 && $addedCount === 0 ? 409 : 200;

        return response()->json([
            'message' => $message,
            'data' => [
                'added' => $results['added'],
                'blocked' => $results['blocked'],
                'added_count' => $addedCount,
                'blocked_count' => $blockedCount,
            ],
        ], $statusCode);
    }

    /**
     * DELETE /api/v1/producao/carrinho/itens/{item}
     * 
     * Remove an item from the cart.
     */
    public function removeItem(int $itemId): JsonResponse
    {
        $this->carrinhoService->removeFromCart($itemId);

        return response()->json([
            'message' => 'Item removido do carrinho.',
        ]);
    }

    /**
     * POST /api/v1/producao/carrinho/fechar
     * 
     * Close the cart and create the production order.
     */
    public function close(CloseCartRequest $request): JsonResponse
    {
        $observation = $request->validated('observation');
        $pedido = $this->carrinhoService->closeCart($observation);

        return response()->json([
            'message' => 'Pedido de produção criado com sucesso.',
            'data' => new ProducaoPedidoResource($pedido),
        ], 201);
    }

    /**
     * DELETE /api/v1/producao/carrinho
     * 
     * Cancel the current open cart.
     */
    public function cancel(): JsonResponse
    {
        $cancelled = $this->carrinhoService->cancelCart();

        if (!$cancelled) {
            return response()->json([
                'message' => 'Nenhum carrinho aberto encontrado.',
            ], 404);
        }

        return response()->json([
            'message' => 'Carrinho cancelado.',
        ]);
    }
}
