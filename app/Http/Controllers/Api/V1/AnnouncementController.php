<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\AckAnnouncementRequest;
use App\Http\Requests\Announcements\DismissAnnouncementRequest;
use App\Http\Requests\Announcements\ListAnnouncementsRequest;
use App\Http\Requests\Announcements\SeenAnnouncementRequest;
use App\Http\Requests\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Announcements\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementDetailResource;
use App\Http\Resources\AnnouncementSummaryResource;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\AnnouncementTarget;
use App\Services\AnnouncementEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @group Comunicação Interna - Avisos
 *
 * Endpoints para gerenciamento de avisos e comunicados internos.
 * O sistema permite criar, publicar e acompanhar avisos direcionados a:
 * - **Todos os usuários** (scope global)
 * - **Lojas específicas** (scope store)
 * - **Usuários específicos** (scope user)
 * - **Cargos específicos** (scope role)
 *
 * **Funcionalidades:**
 * - Criação e edição de avisos com suporte a imagens e call-to-action
 * - Publicação imediata ou agendada
 * - Controle de leitura (seen), confirmação (ack) e dispensa (dismiss)
 * - Estatísticas de engajamento em tempo real
 * - Duplicação e republicação de avisos arquivados
 *
 * **Níveis de severidade:** `info`, `warning`, `danger`
 *
 * **Permissões:** Apenas administradores e gerentes podem criar e gerenciar avisos.
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AnnouncementEligibilityService $eligibilityService
    ) {
    }

    // ========================================
    // User-facing endpoints
    // ========================================

    /**
     * Obter avisos ativos para o dashboard
     *
     * Retorna os avisos ativos que o usuário atual deve visualizar no dashboard.
     * Os avisos são separados em duas categorias:
     * - **critical**: Avisos de alta prioridade que exigem atenção imediata
     * - **banners**: Avisos informativos exibidos como banners
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Regras de negócio:**
     * - Apenas avisos com status `active` são retornados
     * - Avisos já dispensados pelo usuário não aparecem
     * - Se `store_id` for informado, filtra avisos direcionados àquela loja
     *
     * @queryParam store_id integer Filtrar avisos por loja específica. Example: 1
     *
     * @response 200 scenario="Avisos ativos" {
     *   "data": {
     *     "critical": [
     *       {
     *         "id": 1,
     *         "title": "Manutenção Programada",
     *         "excerpt": "Sistema ficará indisponível das 22h às 23h",
     *         "severity": "warning",
     *         "require_ack": true
     *       }
     *     ],
     *     "banners": [
     *       {
     *         "id": 2,
     *         "title": "Nova funcionalidade disponível",
     *         "excerpt": "Confira as novidades do sistema",
     *         "severity": "info",
     *         "require_ack": false
     *       }
     *     ]
     *   }
     * }
     *
     * @response 403 scenario="Sem acesso à loja" {
     *   "message": "Você não tem acesso a esta loja."
     * }
     */
    public function activeForCurrentUser(Request $request): JsonResponse
    {
        $user = $request->user();
        $storeId = $request->integer('store_id') ?: null;

        if ($storeId && !$user->hasAccessToStore($storeId)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        $result = $this->eligibilityService->getActiveForUser($user, $storeId);

        return $this->success([
            'critical' => AnnouncementSummaryResource::collection($result['critical']),
            'banners' => AnnouncementSummaryResource::collection($result['banners']),
        ]);
    }

    /**
     * Histórico de avisos do usuário
     *
     * Retorna o histórico de avisos que o usuário recebeu, com paginação e filtros.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * @queryParam store_id integer Filtrar por loja. Example: 1
     * @queryParam status string Filtrar por status: `draft`, `active`, `archived`, `expired`. Example: active
     * @queryParam severity string Filtrar por severidade: `info`, `warning`, `danger`. Example: info
     * @queryParam sort string Ordenação: `created_at_desc`, `created_at_asc`, `starts_at_desc`, `priority_desc`. Example: created_at_desc
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de avisos" {
     *   "data": [{"id": 1, "title": "Aviso importante", "severity": "warning"}],
     *   "meta": {"current_page": 1, "total": 10}
     * }
     */
    public function userHistory(ListAnnouncementsRequest $request): JsonResponse
    {
        $user = $request->user();
        $storeId = $request->integer('store_id') ?: null;

        // Get announcements the user has receipts for
        $query = Announcement::query()
            ->with(['targets', 'receipts' => fn($q) => $q->where('user_id', $user->id)])
            ->whereHas('receipts', fn($q) => $q->where('user_id', $user->id));

        // Apply filters
        $this->applyFilters($query, $request);

        // Apply sorting
        $this->applySorting($query, $request->input('sort', 'created_at_desc'));

        $perPage = $request->integer('per_page', 15);
        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator, AnnouncementSummaryResource::class);
    }

    /**
     * Detalhes do aviso
     *
     * Retorna os detalhes completos de um aviso, incluindo targets e status de leitura do usuário atual.
     *
     * **Quem pode usar:** Usuário autenticado com permissão de visualização.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 200 scenario="Detalhes do aviso" {
     *   "data": {
     *     "id": 1,
     *     "title": "Manutenção Programada",
     *     "message": "O sistema ficará indisponível...",
     *     "severity": "warning",
     *     "status": "active",
     *     "require_ack": true,
     *     "created_by": {"id": 1, "name": "Admin"}
     *   }
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     * @response 404 scenario="Não encontrado" {"message": "Not found."}
     */
    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        $user = $request->user();
        $announcement->load([
            'targets',
            'createdBy',
            'publishedBy',
            'archivedBy',
            'receipts' => fn($q) => $q->where('user_id', $user->id),
        ]);

        return $this->success(new AnnouncementDetailResource($announcement));
    }

    /**
     * Marcar aviso como visto
     *
     * Registra que o usuário visualizou o aviso. A data/hora é registrada automaticamente.
     *
     * **Quem pode usar:** Usuário autenticado que recebeu o aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     * @bodyParam store_id integer ID da loja (contexto). Example: 1
     *
     * @response 200 scenario="Marcado como visto" {
     *   "data": {"message": "Marcado como visto.", "seen_at": "2026-01-13T15:00:00+00:00"}
     * }
     */
    public function seen(SeenAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        $storeId = $request->integer('store_id') ?: null;

        $receipt = AnnouncementReceipt::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ],
            [
                'store_id' => $storeId,
                'delivered_at' => now(),
            ]
        );

        $receipt->markSeen();

        return $this->success([
            'message' => 'Marcado como visto.',
            'seen_at' => $receipt->seen_at->toIso8601String(),
        ]);
    }

    /**
     * Confirmar leitura do aviso
     *
     * Registra que o usuário confirmou a leitura do aviso (acknowledge).
     * Usado para avisos que exigem confirmação explícita (`require_ack = true`).
     *
     * **Quem pode usar:** Usuário autenticado que recebeu o aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     * @bodyParam store_id integer ID da loja (contexto). Example: 1
     *
     * @response 200 scenario="Confirmação registrada" {
     *   "data": {"message": "Confirmação registrada.", "acknowledged_at": "2026-01-13T15:00:00+00:00"}
     * }
     */
    public function ack(AckAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        $storeId = $request->integer('store_id') ?: null;

        $receipt = AnnouncementReceipt::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ],
            [
                'store_id' => $storeId,
                'delivered_at' => now(),
            ]
        );

        $receipt->markAcknowledged();

        return $this->success([
            'message' => 'Confirmação registrada.',
            'acknowledged_at' => $receipt->acknowledged_at->toIso8601String(),
        ]);
    }

    /**
     * Dispensar aviso
     *
     * Registra que o usuário dispensou o aviso (não quer mais ver).
     * O aviso não aparecerá mais no dashboard do usuário.
     *
     * **Quem pode usar:** Usuário autenticado que recebeu o aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     * @bodyParam store_id integer ID da loja (contexto). Example: 1
     *
     * @response 200 scenario="Dispensado" {
     *   "data": {"message": "Dispensado com sucesso.", "dismissed_at": "2026-01-13T15:00:00+00:00"}
     * }
     */
    public function dismiss(DismissAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        $storeId = $request->integer('store_id') ?: null;

        $receipt = AnnouncementReceipt::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ],
            [
                'store_id' => $storeId,
                'delivered_at' => now(),
            ]
        );

        $receipt->markDismissed();

        return $this->success([
            'message' => 'Dispensado com sucesso.',
            'dismissed_at' => $receipt->dismissed_at->toIso8601String(),
        ]);
    }

    // ========================================
    // Admin CRUD endpoints
    // ========================================

    /**
     * Listar avisos (Admin)
     *
     * Retorna lista de avisos com filtros e paginação para gerenciamento.
     * Super admins veem todos os avisos; outros admins veem apenas avisos das lojas que gerenciam.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * @queryParam status string Filtrar por status: `draft`, `scheduled`, `active`, `archived`, `expired`, `all`. Example: active
     * @queryParam severity string Filtrar por severidade: `info`, `warning`, `danger`. Example: warning
     * @queryParam type string Filtrar por tipo. Example: announcement
     * @queryParam scope string Filtrar por escopo: `global`, `store`, `user`, `role`. Example: store
     * @queryParam created_by integer Filtrar por criador. Example: 1
     * @queryParam date_from string Filtrar por data inicial (ISO 8601). Example: 2026-01-01
     * @queryParam date_to string Filtrar por data final (ISO 8601). Example: 2026-12-31
     * @queryParam sort string Ordenação. Example: created_at_desc
     * @queryParam per_page integer Itens por página. Example: 15
     *
     * @response 200 scenario="Lista de avisos" {
     *   "data": [{"id": 1, "title": "Aviso", "status": "active", "severity": "info"}],
     *   "meta": {"current_page": 1, "total": 50}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function adminIndex(ListAnnouncementsRequest $request): JsonResponse
    {
        Gate::authorize('adminIndex', Announcement::class);

        $user = $request->user();

        $query = Announcement::query()
            ->with(['targets', 'createdBy', 'receipts' => fn($q) => $q->where('user_id', $user->id)]);

        // Non-super-admin can only see announcements they have access to
        if (!$user->isSuperAdmin()) {
            $this->scopeToUserAccess($query, $user);
        }

        // Apply filters
        $this->applyFilters($query, $request);

        // Apply sorting
        $this->applySorting($query, $request->input('sort', 'created_at_desc'));

        $perPage = $request->integer('per_page', 15);
        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator, AnnouncementDetailResource::class);
    }

    /**
     * Criar aviso
     *
     * Cria um novo aviso em status `draft`. Após criar, use o endpoint `/publish` para publicar.
     *
     * **Quem pode usar:** Administradores e gerentes.
     *
     * **Regras de negócio:**
     * - Avisos são criados como rascunho (draft)
     * - É necessário definir targets para avisos com scope `store`, `user` ou `role`
     * - `require_ack` define se o usuário precisa confirmar leitura
     *
     * @bodyParam title string required Título do aviso (máx 255 caracteres). Example: Manutenção Programada
     * @bodyParam message string required Conteúdo do aviso (suporta markdown). Example: O sistema ficará indisponível...
     * @bodyParam excerpt string Resumo curto (máx 500 caracteres). Example: Sistema indisponível das 22h às 23h
     * @bodyParam type string Tipo do aviso. Example: announcement
     * @bodyParam severity string required Severidade: `info`, `warning`, `danger`. Example: warning
     * @bodyParam display_mode string Modo de exibição: `modal`, `banner`, `toast`. Example: modal
     * @bodyParam scope string required Escopo: `global`, `store`, `user`, `role`. Example: store
     * @bodyParam require_ack boolean Se requer confirmação de leitura. Example: true
     * @bodyParam priority integer Prioridade (quanto maior, mais importante). Example: 10
     * @bodyParam starts_at string Data de início (ISO 8601). Example: 2026-01-15T10:00:00Z
     * @bodyParam expires_at string Data de expiração (ISO 8601). Example: 2026-01-20T23:59:59Z
     * @bodyParam targets array Lista de targets (obrigatório para scope != global).
     * @bodyParam targets[].target_type string required Tipo: `store`, `user`, `role`. Example: store
     * @bodyParam targets[].target_id integer required ID do target. Example: 1
     *
     * @response 201 scenario="Aviso criado" {
     *   "data": {"id": 1, "title": "Manutenção Programada", "status": "draft"}
     * }
     *
     * @response 422 scenario="Validação falhou" {"message": "The title field is required."}
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $announcement = DB::transaction(function () use ($data, $user) {
            $targets = $data['targets'] ?? [];
            unset($data['targets']);

            $data['created_by_user_id'] = $user->id;
            $data['status'] = AnnouncementStatus::DRAFT->value;

            $announcement = Announcement::create($data);

            // Create targets
            foreach ($targets as $target) {
                AnnouncementTarget::create([
                    'announcement_id' => $announcement->id,
                    'target_type' => $target['target_type'],
                    'target_id' => $target['target_id'],
                    'created_at' => now(),
                ]);
            }

            return $announcement;
        });

        $announcement->load(['targets', 'createdBy']);

        return $this->created(new AnnouncementDetailResource($announcement));
    }

    /**
     * Atualizar aviso
     *
     * Atualiza os dados de um aviso existente. Apenas rascunhos podem ter seus targets atualizados.
     *
     * **Quem pode usar:** Administradores e gerentes (criador ou com acesso ao scope).
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     * @bodyParam title string Título do aviso. Example: Título atualizado
     * @bodyParam message string Conteúdo do aviso. Example: Nova mensagem
     * @bodyParam severity string Severidade: `info`, `warning`, `danger`. Example: info
     * @bodyParam targets array Lista de targets (substitui os existentes).
     *
     * @response 200 scenario="Aviso atualizado" {
     *   "data": {"id": 1, "title": "Título atualizado", "status": "draft"}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use (&$announcement, $data) {
            $targets = $data['targets'] ?? null;
            unset($data['targets']);

            $announcement->update($data);

            // Update targets if provided
            if ($targets !== null) {
                $announcement->targets()->delete();
                foreach ($targets as $target) {
                    AnnouncementTarget::create([
                        'announcement_id' => $announcement->id,
                        'target_type' => $target['target_type'],
                        'target_id' => $target['target_id'],
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $announcement->load(['targets', 'createdBy', 'publishedBy', 'archivedBy']);

        return $this->success(new AnnouncementDetailResource($announcement));
    }

    /**
     * Excluir aviso
     *
     * Exclui permanentemente um aviso. Apenas rascunhos podem ser excluídos.
     *
     * **Quem pode usar:** Criador do aviso ou super admin.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 204 scenario="Aviso excluído"
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('delete', $announcement);

        $announcement->delete();

        return $this->noContent();
    }

    /**
     * Publicar aviso
     *
     * Publica um aviso que está em rascunho. Se `starts_at` for no futuro, o status será `scheduled`;
     * caso contrário, será `active` imediatamente.
     *
     * **Quem pode usar:** Administradores e gerentes com permissão.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 200 scenario="Publicado" {
     *   "data": {"message": "Publicado com sucesso.", "status": "active", "published_at": "2026-01-13T15:00:00+00:00"}
     * }
     *
     * @response 200 scenario="Agendado" {
     *   "data": {"message": "Agendado com sucesso.", "status": "scheduled", "published_at": "2026-01-13T15:00:00+00:00"}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('publish', $announcement);

        $now = now();

        // Determine status based on starts_at
        $status = ($announcement->starts_at === null || $announcement->starts_at <= $now)
            ? AnnouncementStatus::ACTIVE
            : AnnouncementStatus::SCHEDULED;

        $announcement->update([
            'status' => $status->value,
            'published_at' => $now,
            'published_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => $status === AnnouncementStatus::ACTIVE ? 'Publicado com sucesso.' : 'Agendado com sucesso.',
            'status' => $status->value,
            'published_at' => $announcement->published_at->toIso8601String(),
        ]);
    }

    /**
     * Arquivar aviso
     *
     * Arquiva um aviso ativo ou agendado. O aviso não aparecerá mais para os usuários.
     *
     * **Quem pode usar:** Administradores e gerentes com permissão.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 200 scenario="Arquivado" {
     *   "data": {"message": "Arquivado com sucesso.", "archived_at": "2026-01-13T15:00:00+00:00"}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function archive(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('archive', $announcement);

        $announcement->update([
            'status' => AnnouncementStatus::ARCHIVED->value,
            'archived_at' => now(),
            'archived_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => 'Arquivado com sucesso.',
            'archived_at' => $announcement->archived_at->toIso8601String(),
        ]);
    }

    /**
     * Estatísticas do aviso
     *
     * Retorna estatísticas de engajamento do aviso: total de destinatários,
     * visualizações, confirmações e dispensas.
     *
     * **Quem pode usar:** Administradores e gerentes com acesso ao aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 200 scenario="Estatísticas" {
     *   "data": {
     *     "total_recipients": 50,
     *     "delivered_count": 45,
     *     "seen_count": 40,
     *     "acknowledged_count": 35,
     *     "dismissed_count": 5,
     *     "pending_count": 15,
     *     "seen_percentage": 80.0,
     *     "ack_percentage": 70.0,
     *     "require_ack": true
     *   }
     * }
     */
    public function stats(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        // Calculate expected recipients (approximation based on scope)
        $totalRecipients = $this->estimateTotalRecipients($announcement);

        // Get actual receipt stats
        $receipts = $announcement->receipts();
        $deliveredCount = $receipts->count();
        $seenCount = $receipts->clone()->whereNotNull('seen_at')->count();
        $acknowledgedCount = $receipts->clone()->whereNotNull('acknowledged_at')->count();
        $dismissedCount = $receipts->clone()->whereNotNull('dismissed_at')->count();
        $pendingCount = $totalRecipients - $acknowledgedCount;

        return $this->success([
            'total_recipients' => $totalRecipients,
            'delivered_count' => $deliveredCount,
            'seen_count' => $seenCount,
            'acknowledged_count' => $acknowledgedCount,
            'dismissed_count' => $dismissedCount,
            'pending_count' => max(0, $pendingCount),
            'seen_percentage' => $totalRecipients > 0 ? round(($seenCount / $totalRecipients) * 100, 1) : 0,
            'ack_percentage' => $totalRecipients > 0 ? round(($acknowledgedCount / $totalRecipients) * 100, 1) : 0,
            'require_ack' => $announcement->require_ack,
        ]);
    }

    /**
     * Lista de recebimentos do aviso
     *
     * Retorna a lista paginada de usuários que receberam o aviso, com status de leitura e confirmação.
     *
     * **Quem pode usar:** Administradores e gerentes com acesso ao aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     * @queryParam status string Filtrar por status: `seen`, `unseen`, `acknowledged`, `pending`, `dismissed`. Example: pending
     * @queryParam store_id integer Filtrar por loja. Example: 1
     * @queryParam per_page integer Itens por página. Example: 25
     *
     * @response 200 scenario="Lista de recebimentos" {
     *   "data": [{
     *     "user": {"id": 1, "name": "João Silva", "email": "joao@email.com"},
     *     "store": {"id": 1, "name": "Loja Centro"},
     *     "seen_at": "2026-01-13T15:00:00+00:00",
     *     "acknowledged_at": null
     *   }],
     *   "meta": {"current_page": 1, "total": 45}
     * }
     */
    public function receipts(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        $query = $announcement->receipts()
            ->with(['user:id,name,email,avatar_url', 'store:id,name'])
            ->orderByDesc('delivered_at');

        // Filters
        if ($request->filled('status')) {
            match ($request->input('status')) {
                'seen' => $query->whereNotNull('seen_at'),
                'unseen' => $query->whereNull('seen_at'),
                'acknowledged' => $query->whereNotNull('acknowledged_at'),
                'pending' => $query->whereNull('acknowledged_at'),
                'dismissed' => $query->whereNotNull('dismissed_at'),
                default => null,
            };
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        $perPage = $request->integer('per_page', 25);
        $receipts = $query->paginate($perPage);

        return $this->paginated($receipts, function ($receipt) {
            return [
                'user' => [
                    'id' => $receipt->user->id,
                    'name' => $receipt->user->name,
                    'email' => $receipt->user->email,
                    'avatar_url' => $receipt->user->avatar_url,
                ],
                'store' => $receipt->store ? [
                    'id' => $receipt->store->id,
                    'name' => $receipt->store->name,
                ] : null,
                'delivered_at' => $receipt->delivered_at?->toIso8601String(),
                'seen_at' => $receipt->seen_at?->toIso8601String(),
                'acknowledged_at' => $receipt->acknowledged_at?->toIso8601String(),
                'dismissed_at' => $receipt->dismissed_at?->toIso8601String(),
                'last_shown_at' => $receipt->last_shown_at?->toIso8601String(),
                'show_count' => $receipt->show_count,
            ];
        });
    }

    /**
     * Duplicar aviso como rascunho
     *
     * Cria uma cópia do aviso como rascunho, incluindo todos os targets.
     * O título da cópia será prefixado com "[Cópia]".
     *
     * **Quem pode usar:** Administradores e gerentes com acesso ao aviso.
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 201 scenario="Aviso duplicado" {
     *   "data": {"id": 2, "title": "[Cópia] Manutenção Programada", "status": "draft"}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function duplicate(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        $newAnnouncement = DB::transaction(function () use ($announcement, $request) {
            $data = $announcement->only([
                'title',
                'message',
                'excerpt',
                'type',
                'severity',
                'display_mode',
                'icon',
                'image_url',
                'image_alt',
                'cta_label',
                'cta_url',
                'scope',
                'require_ack',
                'repeat_every_minutes',
                'priority',
                'meta_json',
            ]);

            $data['title'] = "[Cópia] " . $data['title'];
            $data['status'] = AnnouncementStatus::DRAFT->value;
            $data['created_by_user_id'] = $request->user()->id;
            $data['starts_at'] = null;
            $data['expires_at'] = null;

            $newAnnouncement = Announcement::create($data);

            // Copy targets
            foreach ($announcement->targets as $target) {
                AnnouncementTarget::create([
                    'announcement_id' => $newAnnouncement->id,
                    'target_type' => $target->target_type->value,
                    'target_id' => $target->target_id,
                    'created_at' => now(),
                ]);
            }

            return $newAnnouncement;
        });

        $newAnnouncement->load(['targets', 'createdBy']);

        return $this->created(new AnnouncementDetailResource($newAnnouncement));
    }

    /**
     * Republicar aviso arquivado/expirado
     *
     * Republica um aviso que foi arquivado ou expirou, tornando-o ativo novamente.
     * As datas de início e expiração são redefinidas.
     *
     * **Quem pode usar:** Administradores e gerentes com permissão de publicação.
     *
     * **Regras de negócio:**
     * - Apenas avisos com status `archived` ou `expired` podem ser republicados
     * - A data de início é definida como agora
     * - A data de expiração é removida
     *
     * @urlParam announcement integer required ID do aviso. Example: 1
     *
     * @response 200 scenario="Republicado" {
     *   "data": {"message": "Republicado com sucesso.", "status": "active", "published_at": "2026-01-13T15:00:00+00:00"}
     * }
     *
     * @response 422 scenario="Status inválido" {"message": "Apenas comunicados arquivados ou expirados podem ser republicados."}
     * @response 403 scenario="Sem permissão" {"message": "This action is unauthorized."}
     */
    public function republish(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('publish', $announcement);

        if (!in_array($announcement->status, [AnnouncementStatus::ARCHIVED, AnnouncementStatus::EXPIRED])) {
            return $this->error('Apenas comunicados arquivados ou expirados podem ser republicados.', 422);
        }

        $now = now();

        $announcement->update([
            'status' => AnnouncementStatus::ACTIVE->value,
            'starts_at' => $now,
            'expires_at' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'published_at' => $now,
            'published_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => 'Republicado com sucesso.',
            'status' => 'active',
            'published_at' => $announcement->published_at->toIso8601String(),
        ]);
    }

    /**
     * Estimate total recipients for an announcement.
     */
    private function estimateTotalRecipients(Announcement $announcement): int
    {
        return match ($announcement->scope) {
            AnnouncementScope::GLOBAL => \App\Models\User::where('active', true)->count(),
            AnnouncementScope::STORE => $this->countStoreUsers($announcement),
            AnnouncementScope::USER => $announcement->targets()
                ->where('target_type', 'user')
                ->count(),
            AnnouncementScope::ROLE => $this->countRoleUsers($announcement),
        };
    }

    private function countStoreUsers(Announcement $announcement): int
    {
        $storeIds = $announcement->targets()
            ->where('target_type', 'store')
            ->pluck('target_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($storeIds)) {
            return 0;
        }

        return \App\Models\StoreUser::whereIn('store_id', $storeIds)
            ->distinct('user_id')
            ->count('user_id');
    }

    private function countRoleUsers(Announcement $announcement): int
    {
        $roles = $announcement->targets()
            ->where('target_type', 'role')
            ->pluck('target_id')
            ->all();

        if (empty($roles)) {
            return 0;
        }

        return \App\Models\StoreUser::whereIn('role', $roles)
            ->distinct('user_id')
            ->count('user_id');
    }

    // ========================================
    // Private helpers
    // ========================================

    private function applyFilters($query, ListAnnouncementsRequest $request): void
    {
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('scope')) {
            $query->where('scope', $request->input('scope'));
        }

        if ($request->filled('created_by')) {
            $query->where('created_by_user_id', $request->input('created_by'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        if ($request->boolean('only_unacknowledged')) {
            $query->whereHas(
                'receipts',
                fn($q) => $q
                    ->where('user_id', $request->user()->id)
                    ->whereNull('acknowledged_at')
            );
        }

        if ($request->boolean('only_unseen')) {
            $query->whereHas(
                'receipts',
                fn($q) => $q
                    ->where('user_id', $request->user()->id)
                    ->whereNull('seen_at')
            );
        }
    }

    private function applySorting($query, string $sort): void
    {
        match ($sort) {
            'starts_at_desc' => $query->orderByDesc('starts_at'),
            'starts_at_asc' => $query->orderBy('starts_at'),
            'created_at_asc' => $query->orderBy('created_at'),
            'severity_desc' => $query->orderByRaw("FIELD(severity, 'danger', 'warning', 'info')"),
            'priority_desc' => $query->orderByDesc('priority'),
            default => $query->orderByDesc('created_at'),
        };
    }

    private function scopeToUserAccess($query, $user): void
    {
        // Get stores where user is admin or gerente
        $managedStoreIds = $user->storeUsers()
            ->whereIn('role', ['admin', 'gerente'])
            ->pluck('store_id')
            ->all();

        $isGlobalAdmin = $user->storeUsers()->where('role', 'admin')->exists();

        $query->where(function ($q) use ($managedStoreIds, $isGlobalAdmin, $user) {
            // Own announcements
            $q->where('created_by_user_id', $user->id);

            // Global admin can see global
            if ($isGlobalAdmin) {
                $q->orWhere('scope', AnnouncementScope::GLOBAL ->value);
            }

            // Store-scoped announcements for managed stores
            if (!empty($managedStoreIds)) {
                $q->orWhere(function ($sq) use ($managedStoreIds) {
                    $sq->where('scope', AnnouncementScope::STORE->value)
                        ->whereHas(
                            'targets',
                            fn($tq) => $tq
                                ->where('target_type', 'store')
                                ->whereIn('target_id', array_map('strval', $managedStoreIds))
                        );
                });
            }
        });
    }
}
