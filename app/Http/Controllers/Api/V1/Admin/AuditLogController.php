<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Administração - Logs de Auditoria
 *
 * Consulta de logs de auditoria do sistema.
 *
 * Este endpoint permite que administradores visualizem todas as
 * ações registradas no sistema para fins de auditoria e compliance.
 *
 * **Tipos de eventos registrados:**
 * | Domínio | Eventos |
 * |---------|---------|
 * | `auth` | login, logout, login_failed |
 * | `cash` | cash_closing.submit, approve, reject |
 * | `rules` | bonus/commission create, update, delete |
 * | `goals` | monthly.create, update, splits.set |
 * | `sales` | create, update, delete |
 * | `admin` | user/store create, update, delete |
 *
 * **Quem pode usar:** Apenas administradores.
 */
class AuditLogController extends Controller
{
    use ApiResponse;

    /**
     * Listar logs de auditoria
     *
     * Retorna lista paginada de logs com suporte a diversos filtros.
     *
     * **Filtros disponíveis:**
     * - `from`/`to` - Período de datas
     * - `causer_id` - ID do usuário que executou a ação
     * - `event` - Nome do evento (suporta wildcard: `auth.*`)
     * - `log_name` - Domínio: auth, cash, rules, goals
     * - `store_id` - Loja relacionada
     * - `subject_type` - Tipo de entidade (CashClosing, BonusRule, etc)
     * - `subject_id` - ID da entidade
     *
     * **Ordenação:** Por data de criação, mais recentes primeiro.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @queryParam from string Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to string Data final (YYYY-MM-DD). Example: 2026-01-31
     * @queryParam causer_id integer ID do usuário. Example: 1
     * @queryParam event string Evento (suporta wildcards: auth.*). Example: cash_closing.submit
     * @queryParam log_name string Domínio: auth, cash, rules, goals. Example: auth
     * @queryParam store_id integer ID da loja. Example: 1
     * @queryParam subject_type string Tipo de entidade. Example: CashClosing
     * @queryParam subject_id integer ID da entidade. Example: 15
     * @queryParam per_page integer Itens por página (1-100). Example: 25
     *
     * @response 200 scenario="Lista de logs" {
     *   "data": [
     *     {
     *       "id": 150,
     *       "event": "auth.login",
     *       "action": "login",
     *       "log_name": "auth",
     *       "created_at": "2026-01-07T18:00:00Z",
     *       "causer": { "id": 1, "name": "Admin", "email": "admin@test.com" },
     *       "subject": { "type": "User", "id": 1 },
     *       "store": null,
     *       "context": { "request_id": "abc-123", "ip": "192.168.1.1" },
     *       "properties": { "auth_mode": "bearer", "device_name": "postman" }
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 150 }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "message": "Apenas administradores podem acessar este recurso."
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'causer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'event' => ['sometimes', 'string', 'max:100'],
            'log_name' => ['sometimes', 'string', 'max:50'],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'subject_type' => ['sometimes', 'string', 'max:50'],
            'subject_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::with(['actor:id,name,email', 'store:id,name']);

        // Filtros
        if ($request->filled('from') || $request->filled('to')) {
            $query->inPeriod($request->input('from'), $request->input('to'));
        }

        if ($request->filled('causer_id')) {
            $query->forActor($request->input('causer_id'));
        }

        if ($request->filled('event')) {
            $query->forEvent($request->input('event'));
        }

        if ($request->filled('log_name')) {
            $query->forLogName($request->input('log_name'));
        }

        if ($request->filled('store_id')) {
            $query->forStore($request->input('store_id'));
        }

        if ($request->filled('subject_type') && $request->filled('subject_id')) {
            $query->forSubject(
                $request->input('subject_type'),
                $request->input('subject_id')
            );
        }

        $logs = $query
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($logs, AuditLogResource::class);
    }

    /**
     * Ver detalhes do log
     *
     * Retorna informações completas de um log específico,
     * incluindo todo o payload de properties.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam id integer required ID do log. Example: 150
     *
     * @response 200 scenario="Detalhes do log" {
     *   "data": {
     *     "id": 150,
     *     "event": "cash_closing.submit",
     *     "action": "submit",
     *     "log_name": "cash",
     *     "created_at": "2026-01-07T18:00:00Z",
     *     "causer": { "id": 6, "name": "João Vendedor", "email": "joao@test.com" },
     *     "subject": { "type": "CashClosing", "id": 45 },
     *     "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *     "context": {
     *       "request_id": "abc-123-def-456",
     *       "ip": "192.168.1.100",
     *       "user_agent": "Mozilla/5.0..."
     *     },
     *     "properties": {
     *       "status_from": "draft",
     *       "status_to": "submitted",
     *       "divergence_total": 0.00
     *     }
     *   }
     * }
     */
    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        $this->authorizeAdmin($request);

        $auditLog->load(['actor:id,name,email', 'store:id,name']);

        return $this->success(new AuditLogResource($auditLog));
    }

    /**
     * Estatísticas de logs
     *
     * Retorna estatísticas agregadas dos logs para overview.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @queryParam from string Data inicial. Example: 2026-01-01
     * @queryParam to string Data final. Example: 2026-01-31
     *
     * @response 200 scenario="Estatísticas" {
     *   "data": {
     *     "total_logs": 1500,
     *     "by_log_name": { "auth": 500, "cash": 800, "rules": 200 },
     *     "by_action": { "login": 300, "submit": 500, "approve": 300 },
     *     "unique_users": 15,
     *     "period": { "from": "2026-01-01", "to": "2026-01-31" }
     *   }
     * }
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = AuditLog::query();

        $from = $request->input('from');
        $to = $request->input('to');

        if ($from || $to) {
            $query->inPeriod($from, $to);
        }

        $totalLogs = $query->count();

        $byLogName = AuditLog::query()
            ->when($from || $to, fn($q) => $q->inPeriod($from, $to))
            ->selectRaw('log_name, COUNT(*) as count')
            ->groupBy('log_name')
            ->pluck('count', 'log_name');

        $byAction = AuditLog::query()
            ->when($from || $to, fn($q) => $q->inPeriod($from, $to))
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'action');

        $uniqueUsers = AuditLog::query()
            ->when($from || $to, fn($q) => $q->inPeriod($from, $to))
            ->distinct('actor_id')
            ->count('actor_id');

        return $this->success([
            'total_logs' => $totalLogs,
            'by_log_name' => $byLogName,
            'by_action' => $byAction,
            'unique_users' => $uniqueUsers,
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Verifica se usuário é admin.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        $isAdmin = $user->storeUsers()->where('role', 'admin')->exists();

        if (!$isAdmin) {
            abort(403, 'Apenas administradores podem acessar este recurso.');
        }
    }
}
