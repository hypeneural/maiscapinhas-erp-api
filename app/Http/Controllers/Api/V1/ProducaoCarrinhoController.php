<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Producao\AddToCartRequest;
use App\Http\Requests\Producao\CloseCartRequest;
use App\Http\Resources\ProducaoPedidoResource;
use App\Services\ProducaoCarrinhoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Produção - Carrinho
 *
 * Gerenciamento do carrinho de produção para agrupar capas personalizadas antes de enviar à fábrica.
 *
 * **Fluxo do carrinho:**
 * 1. Obter/criar carrinho aberto (um por usuário por vez)
 * 2. Validar capas antes de adicionar (dry-run opcional)
 * 3. Adicionar capas ao carrinho
 * 4. Fechar carrinho → cria pedido de produção
 *
 * **Regras de negócio:**
 * - Cada usuário pode ter apenas um carrinho aberto por vez
 * - Capas devem estar com status "Encomenda Solicitada" para serem adicionadas
 * - Capas já em outro carrinho/pedido são bloqueadas
 *
 * **Permissões:** Administradores e gerentes.
 */
class ProducaoCarrinhoController extends Controller
{
    public function __construct(
        private readonly ProducaoCarrinhoService $carrinhoService
    ) {
    }

    /**
     * Obter carrinho atual
     *
     * Retorna o carrinho aberto do usuário atual. Se não existir, cria um novo.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @response 200 scenario="Carrinho atual" {
     *   "data": {
     *     "id": 1,
     *     "status": "cart",
     *     "items_count": 5,
     *     "items": [{"id": 1, "capa_id": 10, "customer_name": "João"}],
     *     "created_by": {"id": 1, "name": "Admin"}
     *   }
     * }
     */
    public function index(): JsonResponse
    {
        $carrinho = $this->carrinhoService->getOrCreateOpenCart();

        return response()->json([
            'data' => new ProducaoPedidoResource($carrinho->load(['itens.capaPersonalizada.customer', 'createdBy'])),
        ]);
    }

    /**
     * Validar capas (dry-run)
     *
     * Valida quais capas podem ser adicionadas ao carrinho antes de adicionar.
     * Útil para feedback ao usuário antes da ação real.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @bodyParam capa_ids array required Lista de IDs das capas para validar. Example: [1, 2, 3]
     * @bodyParam capa_ids.* integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Validação" {
     *   "data": {
     *     "eligible": [{"id": 1, "customer": "João"}, {"id": 2, "customer": "Maria"}],
     *     "blocked": [{"id": 3, "reason": "Já está em outro carrinho"}],
     *     "eligible_count": 2,
     *     "blocked_count": 1
     *   }
     * }
     */
    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'capa_ids' => ['required', 'array', 'min:1', 'max:100'],
            'capa_ids.*' => ['required', 'integer'],
        ]);

        $results = $this->carrinhoService->validateCapas($request->input('capa_ids'));

        return response()->json([
            'data' => [
                'eligible' => $results['eligible'],
                'blocked' => $results['blocked'],
                'eligible_count' => count($results['eligible']),
                'blocked_count' => count($results['blocked']),
            ],
        ]);
    }

    /**
     * Adicionar itens ao carrinho
     *
     * Adiciona capas personalizadas ao carrinho de produção.
     * Capas bloqueadas são informadas na resposta.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * **Regras de negócio:**
     * - Capas devem ter status "Encomenda Solicitada"
     * - Capas já em outro carrinho/pedido são bloqueadas
     * - Se nenhuma capa for adicionada, retorna 409
     *
     * @bodyParam capa_ids array required Lista de IDs das capas. Example: [1, 2, 3]
     * @bodyParam capa_ids.* integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Itens adicionados" {
     *   "message": "3 item(ns) adicionado(s)",
     *   "data": {
     *     "added": [1, 2, 3],
     *     "blocked": [],
     *     "added_count": 3,
     *     "blocked_count": 0
     *   }
     * }
     *
     * @response 409 scenario="Todos bloqueados" {
     *   "message": "0 item(ns) adicionado(s), 3 bloqueado(s)",
     *   "data": {"added": [], "blocked": [1, 2, 3], "added_count": 0, "blocked_count": 3}
     * }
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
     * Remover item do carrinho
     *
     * Remove um item específico do carrinho.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @urlParam item integer required ID do item no carrinho. Example: 1
     *
     * @response 200 scenario="Item removido" {"message": "Item removido do carrinho."}
     * @response 404 scenario="Item não encontrado" {"message": "Not found."}
     */
    public function removeItem(int $itemId): JsonResponse
    {
        $this->carrinhoService->removeFromCart($itemId);

        return response()->json([
            'message' => 'Item removido do carrinho.',
        ]);
    }

    /**
     * Remover múltiplos itens (bulk)
     *
     * Remove vários itens do carrinho de uma só vez.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @bodyParam item_ids array required Lista de IDs dos itens. Example: [1, 2, 3]
     * @bodyParam item_ids.* integer required ID do item. Example: 1
     *
     * @response 200 scenario="Itens removidos" {
     *   "message": "3 item(ns) removido(s)",
     *   "data": {"removed": [1, 2, 3], "errors": [], "removed_count": 3, "error_count": 0}
     * }
     */
    public function bulkRemoveItems(Request $request): JsonResponse
    {
        $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer'],
        ]);

        $results = $this->carrinhoService->bulkRemoveFromCart($request->input('item_ids'));

        $removedCount = count($results['removed']);
        $errorCount = count($results['errors']);

        return response()->json([
            'message' => "{$removedCount} item(ns) removido(s)",
            'data' => [
                'removed' => $results['removed'],
                'errors' => $results['errors'],
                'removed_count' => $removedCount,
                'error_count' => $errorCount,
            ],
        ]);
    }

    /**
     * Fechar carrinho
     *
     * Fecha o carrinho atual e cria um pedido de produção.
     * O carrinho deve ter pelo menos um item.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @bodyParam observation string Observação para a fábrica. Example: Urgente - prazo curto
     *
     * @response 201 scenario="Pedido criado" {
     *   "message": "Pedido de produção criado com sucesso.",
     *   "data": {"id": 1, "status": "pending", "items_count": 5}
     * }
     *
     * @response 422 scenario="Carrinho vazio" {"message": "O carrinho está vazio."}
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
     * Cancelar carrinho
     *
     * Cancela o carrinho aberto atual, liberando todos os itens.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @response 200 scenario="Carrinho cancelado" {"message": "Carrinho cancelado."}
     * @response 404 scenario="Sem carrinho aberto" {"message": "Nenhum carrinho aberto encontrado."}
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

