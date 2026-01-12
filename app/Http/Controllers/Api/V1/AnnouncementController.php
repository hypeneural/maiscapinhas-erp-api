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
     * GET /me/announcements/active
     * Get active announcements for current user (dashboard).
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
     * GET /me/announcements
     * Get announcement history for current user.
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
     * GET /announcements/{announcement}
     * Get announcement details.
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
     * POST /announcements/{announcement}/seen
     * Mark announcement as seen.
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
     * POST /announcements/{announcement}/ack
     * Acknowledge announcement.
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
     * POST /announcements/{announcement}/dismiss
     * Dismiss announcement.
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
     * GET /announcements
     * List announcements (admin).
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
     * POST /announcements
     * Create announcement.
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
     * PUT /announcements/{announcement}
     * Update announcement.
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
     * DELETE /announcements/{announcement}
     * Delete announcement.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('delete', $announcement);

        $announcement->delete();

        return $this->noContent();
    }

    /**
     * POST /announcements/{announcement}/publish
     * Publish announcement.
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
     * POST /announcements/{announcement}/archive
     * Archive announcement.
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
