<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashShift;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Turnos de Caixa
 *
 * Endpoints para gerenciar turnos de caixa.
 * Cada turno representa um período de trabalho de um vendedor em uma loja.
 */
class CashShiftController extends Controller
{
    use ApiResponse;

    /**
     * Listar turnos de caixa
     *
     * Retorna os turnos de caixa das lojas às quais o usuário tem acesso,
     * com filtros por loja, data e status.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Filtros disponíveis:**
     * - `store_id` - Filtrar por loja específica
     * - `date` - Filtrar por data específica (YYYY-MM-DD)
     * - `status` - Filtrar por status (open, closed, pending)
     *
     * **Códigos de turno:**
     * - `1` ou `M` - Manhã
     * - `2` ou `T` - Tarde
     * - `3` ou `N` - Noite
     *
     * @queryParam store_id integer ID da loja para filtrar. Example: 1
     * @queryParam date string Data específica (YYYY-MM-DD). Example: 2026-01-07
     * @queryParam status string Status do turno. Example: open
     * @queryParam per_page integer Itens por página (1-100). Example: 25
     *
     * @response 200 scenario="Lista de turnos" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "store_id": 1,
     *       "date": "2026-01-07",
     *       "shift_code": "M",
     *       "seller_id": 6,
     *       "status": "open",
     *       "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *       "seller": { "id": 6, "name": "João Vendedor" },
     *       "cash_closing": null
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 84 }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,closed,pending,approved,rejected,submitted'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
            'shift_code' => ['sometimes', 'string', 'regex:/^\d+$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        // Super admin sees all stores
        if ($user->isSuperAdmin()) {
            $userStoreIds = \App\Models\Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();
        }

        $query = CashShift::with(['store:id,name', 'seller:id,name', 'cashClosing:id,cash_shift_id,status'])
            ->whereIn('store_id', $userStoreIds);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!$user->isSuperAdmin() && !in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        if ($request->filled('date')) {
            $query->where('date', $request->input('date'));
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->input('to'));
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            // If checking for closing status, we might need to look at relationship
            if (in_array($status, ['approved', 'rejected', 'submitted', 'draft'])) {
                $query->whereHas('cashClosing', fn($q) => $q->where('status', $status));
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->input('seller_id'));
        }

        if ($request->filled('shift_code')) {
            $query->where('shift_code', $request->input('shift_code'));
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('date')->orderBy('shift_code')->paginate($perPage);

        return $this->paginated($paginator);
    }

    /**
     * Criar novo turno de caixa
     *
     * Cria um novo turno de caixa para uma loja/data/período.
     *
     * **Quem pode usar:** Usuários com acesso à loja.
     *
     * **Regras de negócio:**
     * - Não pode haver dois turnos com mesma loja + data + turno + vendedor
     * - O turno inicia com status `open`
     * - Se `seller_id` não for informado, assume o usuário atual
     *
     * **Códigos de turno:**
     * - `1` ou `M` - Manhã (08h-14h)
     * - `2` ou `T` - Tarde (14h-20h)
     * - `3` ou `N` - Noite (20h-00h)
     *
     * @bodyParam store_id integer required ID da loja. Example: 1
     * @bodyParam date string required Data do turno (YYYY-MM-DD). Example: 2026-01-07
     * @bodyParam shift_code string required Código do turno (1, 2, 3 ou M, T, N). Example: 1
     * @bodyParam seller_id integer ID do vendedor (padrão: usuário atual). Example: 6
     *
     * @response 201 scenario="Turno criado" {
     *   "data": {
     *     "id": 1,
     *     "store_id": 1,
     *     "date": "2026-01-07",
     *     "shift_code": "M",
     *     "seller_id": 6,
     *     "status": "open",
     *     "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *     "seller": { "id": 6, "name": "João Vendedor" }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem acesso" {
     *   "error": { "code": 403, "message": "You do not have access to this store." }
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'regex:/^\d+$/'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $sellerId = $request->input('seller_id', $user->id);

        $shift = CashShift::create([
            'store_id' => $storeId,
            'date' => $request->input('date'),
            'shift_code' => $request->input('shift_code'),
            'seller_id' => $sellerId,
            'status' => CashShift::STATUS_OPEN,
        ]);

        return $this->created($shift->load(['store:id,name', 'seller:id,name']));
    }

    /**
     * Obter detalhes de um turno
     *
     * Retorna os detalhes de um turno específico, incluindo o fechamento se existir.
     *
     * **Quem pode usar:** Usuários com acesso à loja.
     *
     * @urlParam shift integer required ID do turno. Example: 1
     *
     * @response 200 scenario="Turno encontrado" {
     *   "data": {
     *     "id": 1,
     *     "store_id": 1,
     *     "date": "2026-01-07",
     *     "shift_code": "M",
     *     "status": "closed",
     *     "cash_closing": {
     *       "id": 1,
     *       "status": "approved",
     *       "lines": []
     *     }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success(
            $shift->load(['store:id,name', 'seller:id,name', 'cashClosing.lines'])
        );
    }

    /**
     * Turnos pendentes de conferência
     *
     * Lista turnos que ainda não foram aprovados, ordenados por prioridade
     * (mais antigos primeiro).
     *
     * **Quem pode usar:** Conferentes, Gerentes e Admins.
     *
     * @queryParam store_id integer Filtrar por loja. Example: 1
     *
     * @response 200 scenario="Turnos pendentes" {
     *   "data": {
     *     "total_pending": 12,
     *     "shifts": [
     *       {
     *         "id": 5,
     *         "date": "2026-01-06",
     *         "shift_code": "T",
     *         "days_pending": 2,
     *         "priority": "high",
     *         "store_name": "Tijucas",
     *         "seller_name": "João Silva",
     *         "system_total": 4500.00
     *       }
     *     ]
     *   }
     * }
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        // Permission & Store Filter Logic
        if ($user->isSuperAdmin()) {
            $userStoreIds = \App\Models\Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()
                ->whereIn('role', ['admin', 'gerente', 'conferente'])
                ->pluck('store_id')
                ->toArray();

            if (empty($userStoreIds)) {
                return $this->forbidden('Você não tem permissão para ver turnos pendentes.');
            }
        }

        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
        ]);

        $storeId = $request->input('store_id');
        if ($storeId && !$user->isSuperAdmin() && !in_array($storeId, $userStoreIds)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        $targetStoreIds = $storeId ? [$storeId] : $userStoreIds;

        // Query PdvTurno (fechado=1) LEFT JOIN CashShift
        // This ensures we get ALL closed PDV shifts, even if they don't have a CashShift record yet.
        $shifts = \Illuminate\Support\Facades\DB::table('pdv_turnos as pt')
            ->select([
                'pt.id as pdv_turno_id',
                'pt.store_id',
                's.name as store_name',
                \Illuminate\Support\Facades\DB::raw('DATE(pt.data_hora_inicio) as date'),
                'pt.sequencial',
                'pt.responsavel_nome as pdv_seller_name',
                'cs.id as cash_shift_id',
                'cs.store_id as cs_store_id',
                'cs.seller_id as cs_seller_id',
                'u.name as cs_seller_name',
                'cs.status as cash_shift_status',
                'cc.status as closing_status'
            ])
            ->join('stores as s', 'pt.store_id', '=', 's.id')
            ->leftJoin('cash_shifts as cs', function ($join) {
                $join->on('pt.store_id', '=', 'cs.store_id')
                    ->on(\Illuminate\Support\Facades\DB::raw('DATE(pt.data_hora_inicio)'), '=', 'cs.date')
                    ->on(\Illuminate\Support\Facades\DB::raw('CAST(pt.sequencial AS CHAR)'), '=', 'cs.shift_code');
            })
            ->leftJoin('cash_closings as cc', 'cs.id', '=', 'cc.cash_shift_id')
            ->leftJoin('users as u', 'cs.seller_id', '=', 'u.id')
            ->where('pt.fechado', 1)
            ->whereIn('pt.store_id', $targetStoreIds)
            ->where(function ($q) {
                // Pending if:
                // 1. Never started (cash_shift_id IS NULL)
                // 2. OR Started but not fully closed/approved (cash_shift_status != closed)
                $q->whereNull('cs.id')
                    ->orWhere('cs.status', '!=', 'closed');
            })
            ->orderBy('date')
            ->orderBy('pt.sequencial')
            ->get();

        $today = now()->startOfDay();

        $result = $shifts->map(function ($row) use ($today) {
            $shiftDate = \Carbon\Carbon::parse($row->date);
            $daysPending = $shiftDate->diffInDays($today);
            $sellerName = $row->cs_seller_name ?? $row->pdv_seller_name ?? 'N/A';
            $status = $row->closing_status ?? 'not_started';

            // Interpret status for UI
            if ($status === 'draft')
                $status = 'in_progress';
            if (!$row->cash_shift_id)
                $status = 'not_started';

            return [
                'id' => $row->cash_shift_id, // Null if not started
                'pdv_turno_id' => $row->pdv_turno_id,
                'date' => $row->date,
                'shift_code' => (string) $row->sequencial,
                'days_pending' => $daysPending,
                'priority' => $daysPending > 2 ? 'high' : ($daysPending > 0 ? 'medium' : 'low'),
                'store_name' => $row->store_name,
                'seller_name' => $sellerName,
                'status' => $status,
                'action' => $row->cash_shift_id ? 'continue' : 'start',
            ];
        });

        return $this->success([
            'total_pending' => $result->count(),
            'shifts' => $result->values(),
        ]);
    }

    /**
     * Turnos com divergência
     *
     * Lista turnos que possuem divergência não justificada entre
     * valores do sistema e valores reais.
     *
     * **Quem pode usar:** Conferentes, Gerentes e Admins.
     *
     * @queryParam store_id integer Filtrar por loja. Example: 1
     *
     * @response 200 scenario="Turnos divergentes" {
     *   "data": {
     *     "total_divergent": 3,
     *     "total_divergence_value": -85.00,
     *     "shifts": [
     *       {
     *         "id": 8,
     *         "date": "2026-01-05",
     *         "shift_code": "N",
     *         "store_name": "Bombinhas",
     *         "seller_name": "Maria Santos",
     *         "divergence": -50.00,
     *         "has_justification": false,
     *         "days_pending": 3
     *       }
     *     ]
     *   }
     * }
     */
    public function divergent(Request $request): JsonResponse
    {
        $user = $request->user();

        // Super admin sees all stores
        if ($user->isSuperAdmin()) {
            $userStoreIds = \App\Models\Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()
                ->whereIn('role', ['admin', 'gerente', 'conferente'])
                ->pluck('store_id')
                ->toArray();

            if (empty($userStoreIds)) {
                return $this->forbidden('Você não tem permissão para ver divergências.');
            }
        }

        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
        ]);

        $storeId = $request->input('store_id');
        if ($storeId && !$user->isSuperAdmin() && !in_array($storeId, $userStoreIds)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        $shifts = CashShift::with(['store:id,name', 'seller:id,name', 'cashClosing.lines', 'cashClosing.closedByUser:id,name'])
            ->whereIn('store_id', $storeId ? [$storeId] : $userStoreIds)
            // Only list shifts that are waiting for approval (SUBMITTED)
            ->whereHas('cashClosing', function ($q) {
                $q->where('status', \App\Models\CashClosing::STATUS_SUBMITTED)
                    ->whereHas('lines', fn($l) => $l->where('diff_value', '!=', 0));
            })
            ->orderBy('date')
            ->get();

        $today = now()->startOfDay();
        $totalDivergence = 0;

        $result = $shifts->map(function ($shift) use ($today, &$totalDivergence) {
            $divergence = $shift->cashClosing?->lines->sum('diff_value') ?? 0;
            $totalDivergence += $divergence;
            $daysPending = \Carbon\Carbon::parse($shift->date)->diffInDays($today);

            $hasJustification = !empty($shift->cashClosing->justification_text) ||
                !empty($shift->cashClosing->observer_notes) ||
                $shift->cashClosing?->lines
                    ->where('diff_value', '!=', 0)
                    ->every(fn($line) => !empty($line->justification_text));

            // Get conferente name: prefer closedByUser, fallback to audit log
            $conferenteName = $shift->cashClosing?->closedByUser?->name;
            if (!$conferenteName && $shift->cashClosing) {
                $auditEntry = \App\Models\AuditLog::where('entity_type', 'cash_closing')
                    ->where('entity_id', $shift->cashClosing->id)
                    ->where('action', 'submitted')
                    ->latest()
                    ->first();
                if ($auditEntry) {
                    $submittedById = $auditEntry->after['submitted_by'] ?? null;
                    if ($submittedById) {
                        $conferenteName = \App\Models\User::where('id', $submittedById)->value('name');
                    }
                }
            }

            return [
                'id' => $shift->id,
                'date' => $shift->date,
                'shift_code' => $shift->shift_code,
                'store_name' => $shift->store->name,
                'seller_name' => $shift->seller->name,
                'divergence' => round($divergence, 2),
                'has_justification' => $hasJustification,
                'days_pending' => $daysPending,
                'conferente_name' => $conferenteName,
                'submitted_at' => $shift->cashClosing?->updated_at?->toIso8601String(),
                'justification_text' => $shift->cashClosing?->justification_text,
                'observer_notes' => $shift->cashClosing?->observer_notes,
            ];
        });

        return $this->success([
            'total_divergent' => $result->count(),
            'total_divergence_value' => round($totalDivergence, 2),
            'shifts' => $result->values(),
        ]);
    }

    /**
     * Smart filters for history page
     *
     * Returns only stores, sellers, shifts, statuses, and months that actually
     * exist in the data (cash_shifts with closings).
     *
     * @queryParam from string Start date YYYY-MM-DD (optional)
     * @queryParam to string End date YYYY-MM-DD (optional)
     * @queryParam store_id integer Filter by store (optional)
     */
    public function historyFilters(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $userStoreIds = \App\Models\Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();
        }

        // Base query: cash_shifts that have a closing with status
        $baseQuery = CashShift::query()
            ->whereIn('store_id', $userStoreIds)
            ->whereHas('cashClosing', function ($q) {
                $q->whereIn('status', ['submitted', 'approved', 'rejected']);
            });

        // Apply date range if provided
        if ($request->filled('from')) {
            $baseQuery->where('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $baseQuery->where('date', '<=', $request->input('to'));
        }

        // 1. Stores with closings in range
        $storeIds = (clone $baseQuery)->select('store_id')->distinct()->pluck('store_id');
        $stores = \App\Models\Store::whereIn('id', $storeIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // 2. Sellers (scoped by store if selected)
        $sellerQuery = clone $baseQuery;
        if ($request->filled('store_id')) {
            $sellerQuery->where('store_id', (int) $request->input('store_id'));
        }
        $sellerIds = $sellerQuery->select('seller_id')->distinct()->pluck('seller_id');
        $sellers = \App\Models\User::whereIn('id', $sellerIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // 3. Shift codes
        $shiftQuery = clone $baseQuery;
        if ($request->filled('store_id')) {
            $shiftQuery->where('store_id', (int) $request->input('store_id'));
        }
        $shifts = $shiftQuery->select('shift_code')
            ->distinct()
            ->pluck('shift_code')
            ->sort()
            ->values();

        // 4. Statuses
        $statusQuery = clone $baseQuery;
        if ($request->filled('store_id')) {
            $statusQuery->where('store_id', (int) $request->input('store_id'));
        }
        $statuses = $statusQuery
            ->join('cash_closings', 'cash_shifts.id', '=', 'cash_closings.cash_shift_id')
            ->select('cash_closings.status')
            ->distinct()
            ->pluck('status')
            ->values();

        // 5. Available months (unscoped - always show all months with data)
        $monthsQuery = CashShift::query()
            ->whereIn('store_id', $userStoreIds)
            ->whereHas('cashClosing', function ($q) {
                $q->whereIn('status', ['submitted', 'approved', 'rejected']);
            });
        $months = $monthsQuery
            ->selectRaw("DISTINCT DATE_FORMAT(date, '%Y-%m') as month_key")
            ->orderByDesc('month_key')
            ->pluck('month_key')
            ->values();

        return $this->success([
            'stores' => $stores,
            'sellers' => $sellers,
            'shifts' => $shifts,
            'statuses' => $statuses,
            'months' => $months,
        ]);
    }
}

