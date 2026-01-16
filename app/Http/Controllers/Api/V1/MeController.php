<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Perfil do Usuário
 *
 * Endpoints para obter e atualizar informações do usuário autenticado.
 */
class MeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogger $auditLogger
    ) {
    }

    /**
     * Obter perfil do usuário atual
     *
     * Retorna os dados do usuário autenticado, incluindo as lojas
     * às quais ele tem acesso e seu papel (role) em cada uma.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Informações retornadas:**
     * - Dados básicos do usuário (id, nome, email, status ativo)
     * - Dados de perfil (whatsapp, avatar_url, instagram)
     * - Datas para celebração (birth_date, hire_date)
     * - Lista de lojas com o papel do usuário em cada uma
     *
     * **Papéis possíveis:**
     * - `admin` - Administrador global (acesso total)
     * - `gerente` - Gerente da loja (gerencia vendedores e metas)
     * - `conferente` - Confere fechamentos de caixa
     * - `vendedor` - Vendedor (registra vendas e turnos)
     *
     * @response 200 scenario="Perfil com múltiplas lojas" {
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "name": "Admin Sistema",
     *       "email": "admin@maiscapinhas.com.br",
     *       "active": true,
     *       "whatsapp": "47999999999",
     *       "avatar_url": "https://example.com/avatar.jpg",
     *       "instagram": "@maiscapinhas",
     *       "birth_date": "1990-05-15",
     *       "hire_date": "2022-01-09",
     *       "created_at": "2026-01-01T00:00:00+00:00"
     *     },
     *     "stores": [
     *       { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" },
     *       { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "admin" }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get permissions, temporary overrides, and expiring soon
        $permissionData = $this->resolveUserPermissions($user);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'is_super_admin' => $user->is_super_admin ?? false,
                'is_global_admin' => $user->isGlobalAdmin(),
                'has_fabrica_access' => $user->hasRole('fabrica'),
                'roles' => $user->getRoleNames()->toArray(),
                'whatsapp' => $user->whatsapp,
                'avatar_url' => $user->avatar_url,
                'instagram' => $user->instagram,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'hire_date' => $user->hire_date?->format('Y-m-d'),
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'stores' => $user->getStoresWithRoles(),
            'permissions' => $permissionData['permissions'],
            'temporary_permissions' => $permissionData['temporary_permissions'],
            'expiring_soon' => $permissionData['expiring_soon'],
            'has_temporary_permissions' => count($permissionData['temporary_permissions']) > 0,
            'temporary_count' => count($permissionData['temporary_permissions']),
            'expiring_count' => count($permissionData['expiring_soon']),
            'dashboard_layout' => $user->dashboard_layout ?? [
                'widgets' => ['stats', 'recent_orders', 'notifications'],
            ],
        ]);
    }

    /**
     * Resolve user permissions including temporary and expiring
     */
    protected function resolveUserPermissions($user): array
    {
        // Get all permissions from roles and overrides
        $allPermissions = [];
        $temporaryPermissions = [];
        $expiringSoon = [];

        // 1. Get role permissions
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $allPermissions[] = $permission->name;
            }
        }

        // 2. Get screen permissions
        $allPermissions = array_merge($allPermissions, $this->getScreenPermissions($user));

        // 3. Get user overrides (grants)
        $userOverrides = \App\Models\UserPermissionOverride::where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($userOverrides as $override) {
            if ($override->type === 'grant') {
                $allPermissions[] = $override->permission;

                // Track temporary permissions
                if ($override->expires_at) {
                    $temporaryPermissions[] = [
                        'permission' => $override->permission,
                        'expires_at' => $override->expires_at->toIso8601String(),
                        'granted_by' => $override->grantedBy?->name ?? 'Sistema',
                        'reason' => $override->reason,
                    ];

                    // Check if expiring soon (within 72h)
                    $hoursUntilExpiry = now()->diffInHours($override->expires_at, false);
                    if ($hoursUntilExpiry > 0 && $hoursUntilExpiry <= 72) {
                        $expiringSoon[] = [
                            'permission' => $override->permission,
                            'expires_in_hours' => (int) $hoursUntilExpiry,
                            'expires_at' => $override->expires_at->toIso8601String(),
                        ];
                    }
                }
            }
        }

        return [
            'permissions' => array_unique($allPermissions),
            'temporary_permissions' => $temporaryPermissions,
            'expiring_soon' => $expiringSoon,
        ];
    }

    /**
     * Get screen permissions based on roles
     */
    protected function getScreenPermissions($user): array
    {
        $screens = [];

        // Map roles to screens
        $roleScreens = [
            'admin' => ['screen.pedidos', 'screen.capas', 'screen.dashboard', 'screen.reports', 'screen.users'],
            'gerente' => ['screen.pedidos', 'screen.capas', 'screen.dashboard', 'screen.reports'],
            'vendedor' => ['screen.pedidos', 'screen.capas', 'screen.dashboard'],
            'fabrica' => ['screen.producao', 'screen.dashboard'],
        ];

        foreach ($user->getRoleNames() as $roleName) {
            if (isset($roleScreens[$roleName])) {
                $screens = array_merge($screens, $roleScreens[$roleName]);
            }
        }

        return array_unique($screens);
    }

    /**
     * Atualizar perfil do usuário atual
     *
     * Permite ao usuário autenticado atualizar seus próprios dados de perfil.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Campos editáveis:**
     * - `email` - Deve ser único e válido
     * - `whatsapp` - Formato brasileiro (opcional)
     *
     * @bodyParam email string Email do usuário. Example: usuario@email.com
     * @bodyParam whatsapp string Número de WhatsApp. Example: 47999999999
     *
     * @response 200 scenario="Perfil atualizado" {
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "name": "Admin Sistema",
     *       "email": "novoemail@maiscapinhas.com.br",
     *       "active": true,
     *       "whatsapp": "47988887777",
     *       "avatar_url": "https://example.com/avatar.jpg",
     *       "instagram": "@maiscapinhas",
     *       "birth_date": "1990-05-15",
     *       "hire_date": "2022-01-09",
     *       "created_at": "2026-01-01T00:00:00+00:00"
     *     },
     *     "stores": [
     *       { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-10T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Validação falhou" {
     *   "message": "The email has already been taken.",
     *   "errors": { "email": ["The email has already been taken."] }
     * }
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        // Track what changed for audit
        $changes = [];
        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            $changes['email'] = ['from' => $user->email, 'to' => $validated['email']];
        }
        if (array_key_exists('whatsapp', $validated) && $validated['whatsapp'] !== $user->whatsapp) {
            $changes['whatsapp'] = ['from' => $user->whatsapp, 'to' => $validated['whatsapp']];
        }

        // Update user
        $user->update($validated);

        // Log audit if there were changes
        if (!empty($changes)) {
            $this->auditLogger->log('user.profile_updated', $user, [
                'changes' => $changes,
                'updated_by' => 'self',
            ]);
        }

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'is_super_admin' => $user->is_super_admin ?? false,
                'is_global_admin' => $user->isGlobalAdmin(),
                'has_fabrica_access' => $user->hasRole('fabrica'),
                'roles' => $user->getRoleNames()->toArray(),
                'whatsapp' => $user->whatsapp,
                'avatar_url' => $user->avatar_url,
                'instagram' => $user->instagram,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'hire_date' => $user->hire_date?->format('Y-m-d'),
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'stores' => $user->getStoresWithRoles(),
        ]);
    }
}
