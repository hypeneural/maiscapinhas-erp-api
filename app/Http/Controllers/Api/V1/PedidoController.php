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

class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoService $pedidoService
    ) {
    }

    /**
     * List pedidos with filters.
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
     * Create a new pedido.
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
     * Show pedido details.
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
     * Update pedido.
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        $data = $request->validated();
        $user = $request->user();
        $oldStatus = $pedido->status;

        // If status is being updated, use service
        if (isset($data['status']) && $data['status'] !== $oldStatus->value) {
            $this->pedidoService->updateStatus($pedido, $data['status'], $user);
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
     * Delete pedido (soft delete).
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
     * Update pedido status.
     */
    public function updateStatus(UpdateStatusPedidoRequest $request, Pedido $pedido): JsonResponse
    {
        $this->authorizeAccess($request, $pedido);

        $pedido = $this->pedidoService->updateStatus(
            $pedido,
            $request->validated()['status'],
            $request->user(),
            $request->input('reason')
        );

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data' => new PedidoResource($pedido->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'statusHistory.changedBy'
            ])),
        ]);
    }

    /**
     * Bulk update status (admin only).
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
