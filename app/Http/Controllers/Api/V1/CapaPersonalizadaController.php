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

/**
 * @group Capas Personalizadas
 *
 * Gestão de capas de celular personalizadas.
 * Capas são produtos criados sob demanda com fotos enviadas pelos clientes.
 *
 * **Fluxo de status:**
 * 1. Aguardando Foto (1) → Cliente envia foto
 * 2. Encomenda Solicitada (2) → Aguardando produção
 * 3. Capa Paga (3) → Pagamento registrado
 * 4. Pronta para entrega (4) → Produção concluída
 * 5. Entregue (5) → Cliente recebeu
 * 6. Cancelada (6)
 *
 * **Escopo de acesso:**
 * - Super admins e admins globais: acesso a todas as capas
 * - Outros usuários: apenas capas que criaram
 *
 * **Permissões:** Todos os usuários autenticados (exceto endpoints públicos).
 */
class CapaPersonalizadaController extends Controller
{
    public function __construct(
        private readonly CapaPersonalizadaService $capaService
    ) {
    }

    /**
     * Listar capas personalizadas
     *
     * Retorna lista paginada de capas com filtros diversos.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam store_id integer Filtrar por loja (apenas admins). Example: 1
     * @queryParam user_id integer Filtrar por usuário (apenas admins). Example: 1
     * @queryParam status integer|array Status da capa (aceita array para múltiplos). Example: 2
     * @queryParam customer_id integer Filtrar por cliente. Example: 1
     * @queryParam initial_date string Data inicial. Example: 2026-01-01
     * @queryParam final_date string Data final. Example: 2026-12-31
     * @queryParam brand_id integer Filtrar por marca do dispositivo. Example: 1
     * @queryParam model_id integer Filtrar por modelo do dispositivo. Example: 5
     * @queryParam keyword string Busca em texto. Example: João
     * @queryParam payed boolean Filtrar por status de pagamento. Example: true
     * @queryParam payday string Filtrar por data de pagamento. Example: 2026-01-13
     * @queryParam received_by_id integer Filtrar por usuário que registrou recebimento. Example: 1
     * @queryParam sort string Campo para ordenação. Example: created_at
     * @queryParam direction string Direção: `asc` ou `desc`. Example: desc
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de capas" {
     *   "data": [{
     *     "id": 1,
     *     "status": 2,
     *     "photo_url": "https://storage.example.com/capas/123.jpg",
     *     "customer": {"id": 1, "name": "João"},
     *     "price": 49.90
     *   }],
     *   "meta": {"current_page": 1, "total": 50}
     * }
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
     * Criar capa personalizada
     *
     * Cria uma nova capa personalizada para um cliente.
     * A capa iniciará no status "Aguardando Foto" até o upload.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @bodyParam customer_id integer required ID do cliente. Example: 1
     * @bodyParam customer_device_id integer ID do dispositivo do cliente. Example: 1
     * @bodyParam store_id integer ID da loja (usa loja do usuário se não informado). Example: 1
     * @bodyParam selected_product string Produto selecionado. Example: Capa Premium
     * @bodyParam price number Preço unitário. Example: 49.90
     * @bodyParam qty integer Quantidade. Example: 1
     * @bodyParam observation string Observações. Example: Cliente quer borda preta
     *
     * @response 201 scenario="Capa criada" {
     *   "message": "Capa personalizada criada com sucesso.",
     *   "data": {"id": 1, "status": 1, "customer": {"name": "João"}}
     * }
     *
     * @response 422 scenario="Sem loja" {"message": "Usuário não está vinculado a nenhuma loja."}
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
     * Detalhes da capa
     *
     * Retorna detalhes completos da capa personalizada.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Detalhes da capa" {
     *   "data": {
     *     "id": 1,
     *     "status": 2,
     *     "photo_url": "https://storage.example.com/capas/123.jpg",
     *     "customer": {"name": "João"},
     *     "price": 49.90
     *   }
     * }
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
     * Atualizar capa
     *
     * Atualiza dados de uma capa personalizada.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     * @bodyParam selected_product string Produto selecionado. Example: Capa Ultra Premium
     * @bodyParam price number Preço unitário. Example: 59.90
     * @bodyParam observation string Observações. Example: Cliente alterou modelo
     *
     * @response 200 scenario="Capa atualizada" {
     *   "message": "Capa personalizada atualizada com sucesso.",
     *   "data": {"id": 1, "price": 59.90}
     * }
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
     * Excluir capa
     *
     * Remove uma capa personalizada (soft delete).
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Capa excluída" {"message": "Capa personalizada excluída com sucesso."}
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
     * Atualizar status da capa
     *
     * Atualiza o status de uma capa personalizada.
     * Quando o status é alterado para "Disponível na Loja" (3), é possível
     * enviar uma notificação via WhatsApp para o cliente.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     * @bodyParam status integer required Novo status (1-7). Example: 3
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
    public function updateStatus(UpdateStatusCapaRequest $request, CapaPersonalizada $capasPersonalizada): JsonResponse
    {
        $this->authorizeAccess($request, $capasPersonalizada);

        $user = $request->user();
        $oldStatus = $capasPersonalizada->status->value;
        $newStatus = $request->validated()['status'];

        // Validate transition is allowed for user's roles
        $module = \App\Modules\ModuleRegistry::getInstance()->get('capas-personalizadas');
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

        $result = $this->capaService->updateStatus(
            $capasPersonalizada,
            $newStatus,
            $user,
            $request->boolean('notify_whatsapp', false)
        );

        $response = [
            'message' => 'Status atualizado com sucesso.',
            'data' => new CapaPersonalizadaResource($result['capa']->load([
                'store',
                'user',
                'customer',
                'customerDevice.phoneModel.brand',
                'receivedBy'
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
     * Atualiza o status de múltiplas capas de uma só vez.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam ids array required Lista de IDs das capas. Example: [1, 2, 3]
     * @bodyParam ids.* integer required ID da capa. Example: 1
     * @bodyParam status integer required Novo status. Example: 4
     *
     * @response 200 scenario="Atualização concluída" {
     *   "message": "Atualização em lote concluída. 3 atualizadas, 0 ignoradas.",
     *   "data": {"updated": 3, "skipped": 0}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem alterar status em lote."}
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
     * Enviar para produção
     *
     * Marca capas selecionadas como enviadas para produção na fábrica.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam ids array required Lista de IDs das capas. Example: [1, 2, 3]
     * @bodyParam ids.* integer required ID da capa. Example: 1
     * @bodyParam sended_to_production_at string Data de envio (ISO 8601). Example: 2026-01-13
     *
     * @response 200 scenario="Envio concluído" {
     *   "message": "Envio para produção concluído. 3 capas atualizadas.",
     *   "data": {"updated": 3}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem enviar para produção."}
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
     * Registrar pagamento
     *
     * Registra o pagamento de uma capa personalizada.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     * @bodyParam payed boolean required Status de pagamento. Example: true
     * @bodyParam payday string Data do pagamento. Example: 2026-01-13
     * @bodyParam received_by_id integer ID do usuário que recebeu o pagamento. Example: 1
     *
     * @response 200 scenario="Pagamento registrado" {
     *   "message": "Pagamento registrado com sucesso.",
     *   "data": {"id": 1, "payed": true, "payday": "2026-01-13"}
     * }
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
     * Upload de foto (autenticado)
     *
     * Faz upload da foto da capa personalizada.
     * Requer autenticação.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * **Formato:** multipart/form-data
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     * @bodyParam file file required Arquivo de imagem (jpg, png, gif). Max: 10MB. Example: photo.jpg
     *
     * @response 200 scenario="Foto enviada" {
     *   "message": "Foto enviada com sucesso.",
     *   "data": {"photo_url": "https://storage.example.com/capas/123.jpg", "photo_path": "capas/123.jpg"}
     * }
     *
     * @response 422 scenario="Arquivo inválido" {"message": "The file must be an image."}
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
     * Remover foto
     *
     * Remove a foto de uma capa personalizada.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Foto removida" {"message": "Foto removida com sucesso."}
     * @response 404 scenario="Sem foto" {"message": "Capa não possui foto."}
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
     * Gerar token de upload
     *
     * Gera um token temporário para upload público de foto.
     * Use este token no endpoint de upload público para permitir que clientes
     * enviem fotos sem autenticação.
     *
     * **Quem pode usar:** Usuário com acesso à capa.
     *
     * @urlParam capasPersonalizada integer required ID da capa. Example: 1
     *
     * @response 200 scenario="Token gerado" {
     *   "message": "Token gerado com sucesso.",
     *   "data": {
     *     "token": "abc123xyz...",
     *     "expires_at": "2026-01-13T16:00:00+00:00",
     *     "upload_url": "/api/v1/capas-personalizadas/1/upload-publico"
     *   }
     * }
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
     * Upload público de foto
     *
     * Faz upload de foto usando um token temporário.
     * Não requer autenticação, mas exige token válido.
     *
     * **Quem pode usar:** Qualquer pessoa com token válido.
     *
     * **Formato:** multipart/form-data
     *
     * @unauthenticated
     *
     * @urlParam id integer required ID da capa. Example: 1
     * @bodyParam photo file required Arquivo de imagem (jpg, png, gif). Max: 10MB. Example: photo.jpg
     * @bodyParam token string required Token de upload gerado previamente. Example: abc123xyz
     *
     * @response 200 scenario="Foto enviada" {
     *   "message": "Foto enviada com sucesso.",
     *   "data": {"photo_url": "https://storage.example.com/capas/123.jpg"}
     * }
     *
     * @response 404 scenario="Capa não encontrada" {"message": "Capa personalizada não encontrada."}
     * @response 400 scenario="Token inválido" {"message": "Token inválido ou expirado."}
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


