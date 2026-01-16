<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pedidos\BulkStatusPedidoRequest;
use App\Http\Requests\Pedidos\StorePedidoRequest;
use App\Http\Requests\Pedidos\UpdatePedidoRequest;
use App\Http\Requests\Pedidos\UpdateStatusPedidoRequest;
use App\Http\Resources\PedidoResource;
use App\Models\Pedido;
use App\Models\Store;
use App\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Pedidos
 *
 * Gestão de pedidos de clientes nas lojas.
 * Pedidos representam solicitações de clientes que podem incluir capas personalizadas ou produtos do catálogo.
 *
 * **Escopo de acesso:**
 * - Super admins e admins globais: acesso a todos os pedidos
 * - Outros usuários: apenas pedidos que criaram
 *
 * **Status do pedido:** 1=Novo, 2=Em produção, 3=Pronto, 4=Entregue, 5=Cancelado
 *
 * **Permissões:** Todos os usuários autenticados.
 */
class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoService $pedidoService
    ) {
    }

    /**
     * Listar pedidos
     *
     * Retorna lista paginada de pedidos com filtros diversos.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam store_id integer Filtrar por loja (apenas admins). Example: 1
     * @queryParam user_id integer Filtrar por usuário (apenas admins). Example: 1
     * @queryParam status integer|array Status do pedido (aceita array para múltiplos). Example: 1
     * @queryParam customer_id integer Filtrar por cliente. Example: 1
     * @queryParam initial_date string Data inicial. Example: 2026-01-01
     * @queryParam final_date string Data final. Example: 2026-12-31
     * @queryParam brand_id integer Filtrar por marca do dispositivo. Example: 1
     * @queryParam model_id integer Filtrar por modelo do dispositivo. Example: 5
     * @queryParam keyword string Busca em texto. Example: João
     * @queryParam sort string Campo para ordenação. Example: created_at
     * @queryParam direction string Direção: `asc` ou `desc`. Example: desc
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de pedidos" {
     *   "data": [{
     *     "id": 1,
     *     "status": 1,
     *     "selected_product": "Capa Personalizada",
     *     "customer": {"id": 1, "name": "João"},
     *     "store": {"id": 1, "name": "Loja Centro"}
     *   }],
     *   "meta": {"current_page": 1, "total": 50}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Pedido::query()
            ->with(['store', 'user', 'customer', 'customerDevice.phoneModel.brand']);

        // Scoping for non-admin users
        if (!$this->isAdmin($request)) {
            $query->forUser($request->user()->id);
        }

        // Filters
        if ($request->filled('store_id') && $this->isAdmin($request)) {
            $query->forStore((int) $request->input('store_id'));
        }

        if ($request->filled('user_id') && $this->isAdmin($request)) {
            $query->forUser((int) $request->input('user_id'));
        }

        // Multi-status support: status=1 or status[]=1&status[]=2
        if ($request->filled('status')) {
            $statuses = $request->input('status');
            if (is_array($statuses)) {
                $query->whereIn('status', array_map('intval', $statuses));
            } else {
                $query->withStatus((int) $statuses);
            }
        }

        if ($request->filled('customer_id')) {
            $query->forCustomer((int) $request->input('customer_id'));
        }

        if ($request->filled('initial_date')) {
            $query->createdBetween($request->input('initial_date'), null);
        }

        if ($request->filled('final_date')) {
            $query->createdBetween(null, $request->input('final_date'));
        }

        if ($request->filled('brand_id')) {
            $query->byDeviceBrand((int) $request->input('brand_id'));
        }

        if ($request->filled('model_id')) {
            $query->byDeviceModel((int) $request->input('model_id'));
        }

        if ($request->filled('keyword')) {
            $query->search($request->input('keyword'));
        }

        // Sorting with whitelist validation
        $allowedSortFields = ['id', 'created_at', 'updated_at', 'status', 'selected_product', 'store_id', 'user_id'];
        $sortField = $request->input('sort', 'created_at');
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'created_at';
        $sortDirection = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);

        return PedidoResource::collection($query->paginate($perPage));
    }

    /**
     * Criar pedido
     *
     * Cria um novo pedido associado a um cliente.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @bodyParam customer_id integer required ID do cliente. Example: 1
     * @bodyParam customer_device_id integer ID do dispositivo do cliente. Example: 1
     * @bodyParam store_id integer ID da loja (usa loja do usuário se não informado). Example: 1
     * @bodyParam selected_product string Produto selecionado. Example: Capa Personalizada
     * @bodyParam observation string Observações. Example: Cliente pediu urgência
     * @bodyParam value number Valor do pedido. Example: 49.90
     *
     * @response 201 scenario="Pedido criado" {
     *   "message": "Pedido criado com sucesso.",
     *   "data": {"id": 1, "status": 1, "customer": {"name": "João"}}
     * }
     *
     * @response 422 scenario="Sem loja" {"message": "Usuário não está vinculado a nenhuma loja."}
     * @response 403 scenario="Sem acesso à loja" {"message": "Você não tem acesso a esta loja."}
     */
    public function store(StorePedidoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Set store_id if not provided (use user's first store)
        if (!isset($data['store_id'])) {
            $firstStore = $user->stores()->first();
            if (!$firstStore) {
                return response()->json([
                    'message' => 'Usuário não está vinculado a nenhuma loja.',
                ], 422);
            }
            $data['store_id'] = $firstStore->id;
        } else {
            // Verify user has access to store
            if (!$this->isAdmin($request) && !$user->hasAccessToStore($data['store_id'])) {
                return response()->json([
                    'message' => 'Você não tem acesso a esta loja.',
                ], 403);
            }
        }

        $pedido = $this->pedidoService->createPedido($data, $user);

        return response()->json([
            'message' => 'Pedido criado com sucesso.',
            'data' => new PedidoResource($pedido->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'statusHistory.changedBy'
            ])),
        ], 201);
    }

    /**
     * Detalhes do pedido
     *
     * Retorna detalhes completos do pedido.
     *
     * **Quem pode usar:** Usuário com acesso ao pedido.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     *
     * @response 200 scenario="Detalhes do pedido" {
     *   "data": {
     *     "id": 1,
     *     "status": 1,
     *     "customer": {"id": 1, "name": "João"},
     *     "status_history": [{"status": 1, "changed_by": "Admin", "created_at": "2026-01-13T15:00:00+00:00"}]
     *   }
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Você não tem permissão para acessar este pedido."}
     */
    public function show(Request $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        return response()->json([
            'data' => new PedidoResource($pedido->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'statusHistory.changedBy',
                'createdBy',
                'updatedBy'
            ])),
        ]);
    }

    /**
     * Atualizar pedido
     *
     * Atualiza dados do pedido. Se o status for alterado, registra no histórico.
     *
     * **Quem pode usar:** Usuário com acesso ao pedido.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam selected_product string Produto selecionado. Example: Capa Nova
     * @bodyParam observation string Observações. Example: Cliente alterou pedido
     * @bodyParam status integer Novo status do pedido. Example: 2
     *
     * @response 200 scenario="Pedido atualizado" {
     *   "message": "Pedido atualizado com sucesso.",
     *   "data": {"id": 1, "status": 2}
     * }
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        $data = $request->validated();
        $user = $request->user();
        $oldStatus = $pedido->status;

        // If status is being updated, use service
        if (isset($data['status']) && $data['status'] !== $oldStatus->value) {
            $result = $this->pedidoService->updateStatus($pedido, $data['status'], $user);
            $pedido = $result['pedido'];
            unset($data['status']);
        }

        if (!empty($data)) {
            $data['updated_by_id'] = $user->id;
            $pedido->update($data);
        }

        return response()->json([
            'message' => 'Pedido atualizado com sucesso.',
            'data' => new PedidoResource($pedido->fresh()->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand'
            ])),
        ]);
    }

    /**
     * Excluir pedido
     *
     * Remove um pedido (soft delete).
     *
     * **Quem pode usar:** Usuário com acesso ao pedido.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     *
     * @response 200 scenario="Pedido excluído" {"message": "Pedido excluído com sucesso."}
     */
    public function destroy(Request $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        $pedido->delete();

        return response()->json([
            'message' => 'Pedido excluído com sucesso.',
        ]);
    }

    /**
     * Atualizar status do pedido
     *
     * Atualiza apenas o status do pedido com registro no histórico.
     * Quando o status é alterado para "Disponível na Loja" (3), é possível
     * enviar uma notificação via WhatsApp para o cliente.
     *
     * **Quem pode usar:** Usuário com acesso ao pedido.
     *
     * @urlParam pedido integer required ID do pedido. Example: 1
     * @bodyParam status integer required Novo status. Example: 3
     * @bodyParam reason string Motivo da alteração. Example: Cliente confirmou recebimento
     * @bodyParam notify_whatsapp boolean Enviar notificação WhatsApp ao cliente (apenas para status 3). Example: true
     *
     * @response 200 scenario="Status atualizado" {
     *   "message": "Status atualizado com sucesso.",
     *   "data": {"id": 1, "status": 3}
     * }
     *
     * @response 200 scenario="Status atualizado com notificação" {
     *   "message": "Status atualizado com sucesso.",
     *   "data": {"id": 1, "status": 3},
     *   "whatsapp_notification": {"sent": true, "phone": "****9999"}
     * }
     *
     * @response 200 scenario="Notificação falhou" {
     *   "message": "Status atualizado com sucesso.",
     *   "data": {"id": 1, "status": 3},
     *   "whatsapp_notification": {"sent": false, "phone": null, "error": "Cliente não possui telefone cadastrado."}
     * }
     */
    public function updateStatus(UpdateStatusPedidoRequest $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        $user = $request->user();
        $oldStatus = $pedido->status->value;
        $newStatus = $request->validated()['status'];

        // Validate transition is allowed for user's roles
        $module = \App\Modules\ModuleRegistry::getInstance()->get('pedidos-simples');
        if ($module && !$user->isSuperAdmin()) {
            $userRoles = $user->getRoleNames()->toArray();

            if (!$module->canUserTransition($oldStatus, $newStatus, $userRoles)) {
                $matrix = $module->getTransitionRoleMatrix();
                $allowedRoles = $matrix[$oldStatus][$newStatus] ?? [];

                return response()->json([
                    'message' => 'Você não tem permissão para esta transição de status.',
                    'current_status' => $oldStatus,
                    'target_status' => $newStatus,
                    'your_roles' => $userRoles,
                    'allowed_roles' => $allowedRoles,
                ], 403);
            }
        }

        $result = $this->pedidoService->updateStatus(
            $pedido,
            $newStatus,
            $user,
            $request->input('reason'),
            'api',
            $request->boolean('notify_whatsapp', false)
        );

        $response = [
            'message' => 'Status atualizado com sucesso.',
            'data' => new PedidoResource($result['pedido']->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'statusHistory.changedBy'
            ])),
        ];

        // Include WhatsApp notification result if requested
        if ($result['whatsapp_notification'] !== null) {
            $response['whatsapp_notification'] = $result['whatsapp_notification'];
        }

        return response()->json($response);
    }

    /**
     * Atualização em lote de status
     *
     * Atualiza o status de múltiplos pedidos de uma só vez.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam ids array required Lista de IDs dos pedidos. Example: [1, 2, 3]
     * @bodyParam ids.* integer required ID do pedido. Example: 1
     * @bodyParam status integer required Novo status. Example: 4
     *
     * @response 200 scenario="Atualização concluída" {
     *   "message": "Atualização em lote concluída. 3 atualizados, 0 ignorados.",
     *   "data": {"updated": 3, "skipped": 0, "updated_ids": [1, 2, 3], "skipped_ids": []}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem alterar status em lote."}
     */
    public function bulkStatus(BulkStatusPedidoRequest $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'message' => 'Apenas administradores podem alterar status em lote.',
            ], 403);
        }

        $result = $this->pedidoService->bulkUpdateStatus(
            $request->validated()['ids'],
            $request->validated()['status'],
            $request->user()
        );

        return response()->json([
            'message' => "Atualização em lote concluída. {$result['updated']} atualizados, {$result['skipped']} ignorados.",
            'data' => $result,
        ]);
    }

    // ========================================
    // Authorization Helpers
    // ========================================

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        return $user->isSuperAdmin() || $user->isGlobalAdmin();
    }

    private function authorizeAccess(Request $request, Pedido $pedido): void
    {
        if ($this->isAdmin($request)) {
            return;
        }

        if ($pedido->user_id !== $request->user()->id) {
            abort(403, 'Você não tem permissão para acessar este pedido.');
        }
    }
}

