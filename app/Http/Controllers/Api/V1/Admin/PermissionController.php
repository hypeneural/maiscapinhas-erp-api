<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for Permissions.
 * Super Admin only - Full management of permissions.
 *
 * @group Admin - Permissions
 */
class PermissionController extends Controller
{
    /**
     * List all permissions with filtering and grouping options.
     *
     * @queryParam type string Filter by type: ability, screen, feature
     * @queryParam module string Filter by module name
     * @queryParam search string Search in name or display_name
     * @queryParam group_by string Group results by: module, type
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Permission::query();

        // Filter by type
        if ($request->has('type') && in_array($request->type, Permission::TYPES)) {
            $query->where('type', $request->type);
        }

        // Filter by module
        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $permissions = $query->ordered()->get();

        // Group by module or type if requested
        if ($request->group_by === 'module') {
            $grouped = $permissions->groupBy('module')->map(function ($perms, $module) {
                return [
                    'module' => $module,
                    'module_display' => $this->getModuleDisplayName($module),
                    'count' => $perms->count(),
                    'permissions' => $perms->map(fn($p) => $this->formatPermission($p)),
                ];
            })->values();
            return response()->json(['data' => $grouped, 'grouped_by' => 'module']);
        }

        if ($request->group_by === 'type') {
            $grouped = $permissions->groupBy('type')->map(function ($perms, $type) {
                return [
                    'type' => $type,
                    'type_display' => $this->getTypeDisplayName($type),
                    'count' => $perms->count(),
                    'permissions' => $perms->map(fn($p) => $this->formatPermission($p)),
                ];
            })->values();
            return response()->json(['data' => $grouped, 'grouped_by' => 'type']);
        }

        return response()->json([
            'data' => $permissions->map(fn($p) => $this->formatPermission($p)),
            'meta' => [
                'total' => $permissions->count(),
                'types' => Permission::TYPES,
                'modules' => Permission::distinct()->whereNotNull('module')->orderBy('module')->pluck('module'),
            ],
        ]);
    }

    /**
     * Get permissions grouped by module (optimized for frontend).
     *
     * @return JsonResponse
     */
    public function grouped(): JsonResponse
    {
        $permissions = Permission::ordered()->get();

        $grouped = $permissions->groupBy('module')->map(function ($perms, $module) {
            return [
                'module' => $module,
                'module_display' => $this->getModuleDisplayName($module),
                'abilities' => $perms->where('type', 'ability')->map(fn($p) => $this->formatPermission($p))->values(),
                'screens' => $perms->where('type', 'screen')->map(fn($p) => $this->formatPermission($p))->values(),
                'features' => $perms->where('type', 'feature')->map(fn($p) => $this->formatPermission($p))->values(),
            ];
        })->values();

        return response()->json(['data' => $grouped]);
    }

    /**
     * Get permissions organized by type (abilities, screens, features).
     *
     * @return JsonResponse
     */
    public function byType(): JsonResponse
    {
        $permissions = Permission::ordered()->get();

        $byType = [
            'abilities' => [
                'type' => 'ability',
                'display' => 'Ações',
                'description' => 'Permissões para executar ações específicas (criar, editar, excluir)',
                'permissions' => $permissions->where('type', 'ability')->map(fn($p) => $this->formatPermission($p))->values(),
            ],
            'screens' => [
                'type' => 'screen',
                'display' => 'Telas',
                'description' => 'Permissões para acessar telas/menus do sistema',
                'permissions' => $permissions->where('type', 'screen')->map(fn($p) => $this->formatPermission($p))->values(),
            ],
            'features' => [
                'type' => 'feature',
                'display' => 'Funcionalidades',
                'description' => 'Funcionalidades especiais do sistema',
                'permissions' => $permissions->where('type', 'feature')->map(fn($p) => $this->formatPermission($p))->values(),
            ],
        ];

        return response()->json(['data' => $byType]);
    }

    /**
     * Get list of available modules.
     *
     * @return JsonResponse
     */
    public function modules(): JsonResponse
    {
        $modules = Permission::selectRaw('module, COUNT(*) as count')
            ->whereNotNull('module')
            ->groupBy('module')
            ->orderBy('module')
            ->get()
            ->map(fn($m) => [
                'name' => $m->module,
                'display' => $this->getModuleDisplayName($m->module),
                'count' => $m->count,
            ]);

        return response()->json(['data' => $modules]);
    }

    /**
     * Get a specific permission.
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function show(Permission $permission): JsonResponse
    {
        $permission->loadCount('roles');

        return response()->json([
            'data' => $this->formatPermission($permission, true),
        ]);
    }

    /**
     * Create a new permission.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('permissions', 'name'), 'regex:/^[a-z0-9\-\.]+$/'],
            'display_name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Permission::TYPES)],
            'module' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.regex' => 'O nome deve conter apenas letras minúsculas, números, hífens e pontos.',
        ]);

        $permission = Permission::create([
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'type' => $validated['type'],
            'module' => $validated['module'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'guard_name' => 'web',
        ]);

        return response()->json([
            'message' => 'Permissão criada com sucesso.',
            'data' => $this->formatPermission($permission),
        ], 201);
    }

    /**
     * Update a permission.
     *
     * @param Request $request
     * @param Permission $permission
     * @return JsonResponse
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('permissions', 'name')->ignore($permission->id), 'regex:/^[a-z0-9\-\.]+$/'],
            'display_name' => ['sometimes', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(Permission::TYPES)],
            'module' => ['sometimes', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.regex' => 'O nome deve conter apenas letras minúsculas, números, hífens e pontos.',
        ]);

        $permission->update($validated);

        // Clear permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Permissão atualizada com sucesso.',
            'data' => $this->formatPermission($permission->fresh()),
        ]);
    }

    /**
     * Delete a permission.
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function destroy(Permission $permission): JsonResponse
    {
        // Check if permission is in use
        $rolesCount = $permission->roles()->count();
        if ($rolesCount > 0) {
            return response()->json([
                'message' => "Esta permissão está atribuída a {$rolesCount} role(s). Remova as atribuições antes de excluir.",
            ], 409);
        }

        $permission->delete();

        // Clear permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Permissão excluída com sucesso.',
        ]);
    }

    /**
     * Bulk create permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*.name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-\.]+$/'],
            'permissions.*.display_name' => ['required', 'string', 'max:150'],
            'permissions.*.type' => ['required', Rule::in(Permission::TYPES)],
            'permissions.*.module' => ['required', 'string', 'max:50'],
            'permissions.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($validated['permissions'] as $index => $permData) {
            try {
                $result = Permission::updateOrCreate(
                    ['name' => $permData['name']],
                    [
                        'display_name' => $permData['display_name'],
                        'type' => $permData['type'],
                        'module' => $permData['module'],
                        'description' => $permData['description'] ?? null,
                        'guard_name' => 'web',
                    ]
                );
                $result->wasRecentlyCreated ? $created++ : $updated++;
            } catch (\Exception $e) {
                $errors[] = "Linha {$index}: {$e->getMessage()}";
            }
        }

        // Clear permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => "Processado: {$created} criadas, {$updated} atualizadas.",
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], empty($errors) ? 200 : 207);
    }

    /**
     * Get permission naming conventions and examples.
     *
     * @return JsonResponse
     */
    public function conventions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => [
                    [
                        'type' => 'ability',
                        'prefix' => '[módulo].[ação]',
                        'description' => 'Ações que o usuário pode executar',
                        'examples' => ['pedidos.create', 'capas.delete', 'users.view-all'],
                    ],
                    [
                        'type' => 'screen',
                        'prefix' => 'screen.[área]',
                        'description' => 'Telas/menus que o usuário pode acessar',
                        'examples' => ['screen.dashboard', 'screen.pedidos.list', 'screen.admin.roles'],
                    ],
                    [
                        'type' => 'feature',
                        'prefix' => 'feature.[nome]',
                        'description' => 'Funcionalidades especiais do sistema',
                        'examples' => ['feature.whatsapp-notifications', 'feature.export-excel'],
                    ],
                ],
                'naming_rules' => [
                    'Use apenas letras minúsculas',
                    'Use pontos (.) para separar hierarquias',
                    'Use hífens (-) para palavras compostas',
                    'Evite underscores (_)',
                    'Seja consistente com o módulo',
                ],
            ],
        ]);
    }

    // ========================================
    // Private Helpers
    // ========================================

    private function formatPermission(Permission $permission, bool $detailed = false): array
    {
        $data = [
            'id' => $permission->id,
            'name' => $permission->name,
            'display_name' => $permission->display_name,
            'type' => $permission->type,
            'type_display' => $this->getTypeDisplayName($permission->type),
            'module' => $permission->module,
            'module_display' => $this->getModuleDisplayName($permission->module),
            'description' => $permission->description,
            'sort_order' => $permission->sort_order,
        ];

        if ($detailed) {
            $data['guard_name'] = $permission->guard_name;
            $data['roles_count'] = $permission->roles_count ?? $permission->roles()->count();
            $data['created_at'] = $permission->created_at?->toIso8601String();
            $data['updated_at'] = $permission->updated_at?->toIso8601String();
        }

        return $data;
    }

    private function getTypeDisplayName(?string $type): string
    {
        return match ($type) {
            'ability' => 'Ação',
            'screen' => 'Tela',
            'feature' => 'Funcionalidade',
            default => $type ?? '',
        };
    }

    private function getModuleDisplayName(?string $module): string
    {
        $map = [
            'dashboard' => 'Dashboard',
            'customers' => 'Clientes',
            'pedidos' => 'Pedidos',
            'capas' => 'Capas Personalizadas',
            'caixa' => 'Caixa',
            'faturamento' => 'Faturamento',
            'producao' => 'Produção',
            'fabrica' => 'Fábrica',
            'users' => 'Usuários',
            'stores' => 'Lojas',
            'announcements' => 'Comunicados',
            'admin' => 'Administração',
            'config' => 'Configurações',
            'gestao' => 'Gestão',
            'reports' => 'Relatórios',
            'features' => 'Funcionalidades',
            'payment-methods' => 'Formas de Pagamento',
            'sales' => 'Vendas',
            'goals' => 'Metas',
            'rules' => 'Regras',
            'analytics' => 'Analytics',
        ];

        return $map[$module] ?? ucfirst($module ?? '');
    }
}
