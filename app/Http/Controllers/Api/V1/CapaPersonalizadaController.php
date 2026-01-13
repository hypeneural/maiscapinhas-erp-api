<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CapasPersonalizadas\BulkStatusCapaRequest;
use App\Http\Requests\CapasPersonalizadas\PaymentCapaRequest;
use App\Http\Requests\CapasPersonalizadas\SendToProductionRequest;
use App\Http\Requests\CapasPersonalizadas\StoreCapaPersonalizadaRequest;
use App\Http\Requests\CapasPersonalizadas\UpdateCapaPersonalizadaRequest;
use App\Http\Requests\CapasPersonalizadas\UpdateStatusCapaRequest;
use App\Http\Requests\CapasPersonalizadas\UploadPublicoRequest;
use App\Http\Resources\CapaPersonalizadaResource;
use App\Models\CapaPersonalizada;
use App\Services\CapaPersonalizadaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CapaPersonalizadaController extends Controller
{
    public function __construct(
        private readonly CapaPersonalizadaService $capaService
    ) {
    }

    /**
     * List capas personalizadas with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CapaPersonalizada::query()
            ->with(['store', 'user', 'customer', 'customerDevice.phoneModel.brand', 'receivedBy']);

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

        if ($request->filled('payed')) {
            $query->payed($request->boolean('payed'));
        }

        if ($request->filled('payday')) {
            $query->payday($request->input('payday'));
        }

        if ($request->filled('received_by_id')) {
            $query->receivedBy((int) $request->input('received_by_id'));
        }

        // Sorting with whitelist validation
        $allowedSortFields = ['id', 'created_at', 'updated_at', 'status', 'selected_product', 'price', 'qty', 'payday', 'sended_to_production_at', 'store_id', 'user_id'];
        $sortField = $request->input('sort', 'created_at');
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'created_at';
        $sortDirection = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);

        return CapaPersonalizadaResource::collection($query->paginate($perPage));
    }

    /**
     * Create a new capa personalizada.
     */
    public function store(StoreCapaPersonalizadaRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Set store_id if not provided
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

        $capa = $this->capaService->createCapa($data, $user);

        return response()->json([
            'message' => 'Capa personalizada criada com sucesso.',
            'data' => new CapaPersonalizadaResource($capa->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy'
            ])),
        ], 201);
    }

    /**
     * Show capa details.
     */
    public function show(Request $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        return response()->json([
            'data' => new CapaPersonalizadaResource($capasPersonalizada->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy',
                'createdBy',
                'updatedBy'
            ])),
        ]);
    }

    /**
     * Update capa.
     */
    public function update(UpdateCapaPersonalizadaRequest $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $data = $request->validated();
        $data['updated_by_id'] = $request->user()->id;

        $capasPersonalizada->update($data);

        return response()->json([
            'message' => 'Capa personalizada atualizada com sucesso.',
            'data' => new CapaPersonalizadaResource($capasPersonalizada->fresh()->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy'
            ])),
        ]);
    }

    /**
     * Delete capa (soft delete).
     */
    public function destroy(Request $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $capasPersonalizada->delete();

        return response()->json([
            'message' => 'Capa personalizada excluída com sucesso.',
        ]);
    }

    /**
     * Update capa status.
     */
    public function updateStatus(UpdateStatusCapaRequest $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $capa = $this->capaService->updateStatus(
            $capasPersonalizada,
            $request->validated()['status'],
            $request->user()
        );

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data' => new CapaPersonalizadaResource($capa->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy'
            ])),
        ]);
    }

    /**
     * Bulk update status (admin only).
     */
    public function bulkStatus(BulkStatusCapaRequest $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'message' => 'Apenas administradores podem alterar status em lote.',
            ], 403);
        }

        $result = $this->capaService->bulkUpdateStatus(
            $request->validated()['ids'],
            $request->validated()['status'],
            $request->user()
        );

        return response()->json([
            'message' => "Atualização em lote concluída. {$result['updated']} atualizadas, {$result['skipped']} ignoradas.",
            'data' => $result,
        ]);
    }

    /**
     * Send capas to production (admin only).
     */
    public function sendToProduction(SendToProductionRequest $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'message' => 'Apenas administradores podem enviar para produção.',
            ], 403);
        }

        $result = $this->capaService->sendToProduction(
            $request->validated()['ids'],
            $request->validated()['sended_to_production_at'],
            $request->user()
        );

        return response()->json([
            'message' => "Envio para produção concluído. {$result['updated']} capas atualizadas.",
            'data' => $result,
        ]);
    }

    /**
     * Register payment.
     */
    public function payment(PaymentCapaRequest $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $capa = $this->capaService->registerPayment(
            $capasPersonalizada,
            $request->boolean('payed'),
            $request->input('payday'),
            $request->input('received_by_id'),
            $request->user()
        );

        return response()->json([
            'message' => 'Pagamento registrado com sucesso.',
            'data' => new CapaPersonalizadaResource($capa->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy'
            ])),
        ]);
    }

    /**
     * Upload photo.
     */
    public function uploadPhoto(Request $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,gif', 'max:10240'], // 10MB
        ]);

        $result = $this->capaService->uploadPhoto($capasPersonalizada, $request->file('file'));

        return response()->json([
            'message' => 'Foto enviada com sucesso.',
            'data' => $result,
        ]);
    }

    /**
     * Delete photo.
     */
    public function deletePhoto(Request $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        if (!$this->capaService->deletePhoto($capasPersonalizada)) {
            return response()->json([
                'message' => 'Capa não possui foto.',
            ], 404);
        }

        return response()->json([
            'message' => 'Foto removida com sucesso.',
        ]);
    }

    // ========================================
    // Public Upload Endpoints
    // ========================================

    /**
     * Generate temporary upload token.
     * Requires authentication.
     */
    public function gerarTokenUpload(Request $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $result = $this->capaService->generateUploadToken($capasPersonalizada);

        return response()->json([
            'message' => 'Token gerado com sucesso.',
            'data' => $result,
        ]);
    }

    /**
     * Public photo upload with token validation.
     * No authentication required.
     */
    public function uploadPublico(UploadPublicoRequest $request, int $id): JsonResponse
    {
        $capa = CapaPersonalizada::find($id);

        if (!$capa) {
            return response()->json([
                'message' => 'Capa personalizada não encontrada.',
            ], 404);
        }

        try {
            $result = $this->capaService->uploadPhotoPublic(
                $capa,
                $request->file('photo'),
                $request->input('token')
            );

            return response()->json([
                'message' => 'Foto enviada com sucesso.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    // ========================================
    // Authorization Helpers
    // ========================================

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        return $user->isSuperAdmin() || $user->isGlobalAdmin();
    }

    private function authorizeAccess(Request $request, CapaPersonalizada $capa): void
    {
        if ($this->isAdmin($request)) {
            return;
        }

        if ($capa->user_id !== $request->user()->id) {
            abort(403, 'Você não tem permissão para acessar esta capa personalizada.');
        }
    }
}

