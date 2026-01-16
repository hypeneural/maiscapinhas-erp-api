<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permission Graph Visualization API.
 * Returns data in React Flow format (nodes/edges).
 * Super Admin only.
 *
 * @group Admin - Graph Visualization
 */
class GraphController extends Controller
{
    protected array $nodeTypes = [
        'role' => ['color' => '#3B82F6', 'icon' => 'Shield'],
        'module' => ['color' => '#F97316', 'icon' => 'Package'],
        'permission' => ['color' => '#6B7280', 'icon' => 'Key'],
        'screen' => ['color' => '#8B5CF6', 'icon' => 'Monitor'],
        'user' => ['color' => '#EAB308', 'icon' => 'User'],
        'store' => ['color' => '#22C55E', 'icon' => 'Store'],
    ];

    /**
     * Get system overview graph.
     * Shows role hierarchy, modules, and high-level stats.
     *
     * @queryParam depth int Max depth to traverse. Default: 3
     * @queryParam include_users bool Include user nodes. Default: false
     */
    public function overview(Request $request): JsonResponse
    {
        $depth = $request->input('depth', 3);
        $includeUsers = $request->boolean('include_users', false);

        $nodes = [];
        $edges = [];
        $edgeId = 1;

        // 1. Add role nodes with hierarchy
        $roles = Role::with('permissions')->orderByDesc('level')->get();
        $roleHierarchy = $this->buildRoleHierarchy($roles);

        foreach ($roles as $role) {
            $nodes[] = $this->createNode(
                "role-{$role->name}",
                'role',
                $role->display_name ?? ucfirst($role->name),
                [
                    'level' => $role->level,
                    'is_system' => $role->is_system,
                    'permissions_count' => $role->permissions->count(),
                    'users_count' => $role->users()->count(),
                ]
            );

            // Add hierarchy edges (parent → child)
            $parent = $roleHierarchy[$role->name] ?? null;
            if ($parent) {
                $edges[] = $this->createEdge(
                    "e{$edgeId}",
                    "role-{$parent}",
                    "role-{$role->name}",
                    'hierarchy'
                );
                $edgeId++;
            }
        }

        // 2. Add module nodes
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        foreach ($registry->all() as $module) {
            $dbModule = Module::find($module->getId());

            $nodes[] = $this->createNode(
                "module-{$module->getId()}",
                'module',
                $module->getName(),
                [
                    'icon' => $module->getIcon(),
                    'is_active' => $dbModule?->is_active ?? false,
                    'status_count' => count($module->getStatuses()),
                    'permission_count' => count($module->getPermissions()),
                ]
            );

            // Connect roles that have access to this module
            foreach ($roles as $role) {
                $modulePerms = collect($module->getPermissions())->pluck('name');
                $rolePerms = $role->permissions->pluck('name');

                if ($modulePerms->intersect($rolePerms)->isNotEmpty()) {
                    $edges[] = $this->createEdge(
                        "e{$edgeId}",
                        "role-{$role->name}",
                        "module-{$module->getId()}",
                        'has_access'
                    );
                    $edgeId++;
                }
            }
        }

        // 3. Add stores if depth allows
        if ($depth >= 2) {
            $stores = Store::withCount('users')->get();
            foreach ($stores as $store) {
                $nodes[] = $this->createNode(
                    "store-{$store->id}",
                    'store',
                    $store->name,
                    [
                        'city' => $store->city,
                        'users_count' => $store->users_count,
                    ]
                );
            }
        }

        // 4. Add users if requested
        if ($includeUsers) {
            $users = User::with('roles')->where('active', true)->limit(50)->get();
            foreach ($users as $user) {
                $nodes[] = $this->createNode(
                    "user-{$user->id}",
                    'user',
                    $user->name,
                    [
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->toArray(),
                    ]
                );

                // Connect to primary role
                $primaryRole = $user->roles->sortByDesc('level')->first();
                if ($primaryRole) {
                    $edges[] = $this->createEdge(
                        "e{$edgeId}",
                        "role-{$primaryRole->name}",
                        "user-{$user->id}",
                        'has_user'
                    );
                    $edgeId++;
                }
            }
        }

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
            'summary' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'by_type' => $this->countByType($nodes),
                'depth' => $depth,
            ],
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'filters' => [
                    'depth' => $depth,
                    'include_users' => $includeUsers,
                ],
            ],
        ]);
    }

    /**
     * Get graph for a specific role.
     * Shows modules, permissions, and optionally users.
     *
     * @queryParam include_users bool Include users with this role. Default: false
     * @queryParam include_permissions bool Include individual permissions. Default: true
     */
    public function role(Request $request, string $roleName): JsonResponse
    {
        $role = Role::with('permissions')->where('name', $roleName)->first();

        if (!$role) {
            return response()->json(['message' => 'Role não encontrada.'], 404);
        }

        $includeUsers = $request->boolean('include_users', false);
        $includePermissions = $request->boolean('include_permissions', true);

        // Count users with this role using User model
        $usersCount = User::role($roleName)->count();

        $nodes = [];
        $edges = [];
        $edgeId = 1;

        // 1. Root node: the role
        $nodes[] = $this->createNode(
            "role-{$role->name}",
            'role',
            $role->display_name ?? ucfirst($role->name),
            [
                'level' => $role->level,
                'is_system' => $role->is_system,
                'permissions_count' => $role->permissions->count(),
                'users_count' => $usersCount,
                'description' => $role->description,
            ]
        );

        // 2. Group permissions by module
        $permissionsByModule = $role->permissions->groupBy('module');

        foreach ($permissionsByModule as $moduleName => $permissions) {
            $moduleId = $moduleName ?: 'global';

            $nodes[] = $this->createNode(
                "module-{$moduleId}",
                'module',
                $this->getModuleDisplayName($moduleId),
                [
                    'permissions_count' => $permissions->count(),
                ]
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "role-{$role->name}",
                "module-{$moduleId}",
                'has_access'
            );
            $edgeId++;

            // 3. Add individual permissions if requested
            if ($includePermissions) {
                foreach ($permissions as $perm) {
                    $nodes[] = $this->createNode(
                        "perm-{$perm->name}",
                        $perm->type === 'screen' ? 'screen' : 'permission',
                        $perm->display_name ?? $perm->name,
                        [
                            'type' => $perm->type,
                            'granted' => true,
                        ]
                    );

                    $edges[] = $this->createEdge(
                        "e{$edgeId}",
                        "module-{$moduleId}",
                        "perm-{$perm->name}",
                        'contains'
                    );
                    $edgeId++;
                }
            }
        }

        // 4. Add users if requested
        if ($includeUsers) {
            $users = User::role($roleName)->where('active', true)->limit(20)->get();

            foreach ($users as $user) {
                $nodes[] = $this->createNode(
                    "user-{$user->id}",
                    'user',
                    $user->name,
                    [
                        'email' => $user->email,
                        'has_overrides' => $user->permissionOverrides()->active()->exists(),
                    ]
                );

                $edges[] = $this->createEdge(
                    "e{$edgeId}",
                    "role-{$role->name}",
                    "user-{$user->id}",
                    'has_user'
                );
                $edgeId++;
            }
        }

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
            'root' => "role-{$role->name}",
            'summary' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'by_type' => $this->countByType($nodes),
                'modules' => $permissionsByModule->keys()->count(),
                'permissions' => $role->permissions->count(),
                'users' => $usersCount,
            ],
        ]);
    }

    /**
     * Get graph for a specific user.
     * Shows all permissions from roles and overrides.
     *
     * @queryParam include_inherited bool Show where each permission comes from. Default: true
     */
    public function user(Request $request, int $userId): JsonResponse
    {
        $user = User::with(['roles.permissions', 'permissionOverrides', 'stores'])->find($userId);

        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $includeInherited = $request->boolean('include_inherited', true);

        $nodes = [];
        $edges = [];
        $edgeId = 1;

        // 1. Root node: the user
        $nodes[] = $this->createNode(
            "user-{$user->id}",
            'user',
            $user->name,
            [
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
                'roles' => $user->getRoleNames()->toArray(),
                'stores_count' => $user->stores->count(),
            ]
        );

        // 2. Add role nodes
        foreach ($user->roles as $role) {
            $nodes[] = $this->createNode(
                "role-{$role->name}",
                'role',
                $role->display_name ?? ucfirst($role->name),
                [
                    'level' => $role->level,
                    'permissions_count' => $role->permissions->count(),
                ]
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "user-{$user->id}",
                "role-{$role->name}",
                'has_role'
            );
            $edgeId++;

            // 3. Add permissions from this role (grouped by module)
            if ($includeInherited) {
                $permsByModule = $role->permissions->groupBy('module');

                foreach ($permsByModule as $moduleName => $perms) {
                    $moduleId = $moduleName ?: 'global';
                    $moduleNodeId = "module-{$moduleId}-{$role->name}";

                    // Check if module node already exists
                    if (!collect($nodes)->contains('id', $moduleNodeId)) {
                        $nodes[] = $this->createNode(
                            $moduleNodeId,
                            'module',
                            $this->getModuleDisplayName($moduleId),
                            ['from_role' => $role->name]
                        );

                        $edges[] = $this->createEdge(
                            "e{$edgeId}",
                            "role-{$role->name}",
                            $moduleNodeId,
                            'has_access'
                        );
                        $edgeId++;
                    }
                }
            }
        }

        // 4. Add stores
        foreach ($user->stores as $store) {
            $pivotRole = $store->pivot->role ?? 'vendedor';

            $nodes[] = $this->createNode(
                "store-{$store->id}",
                'store',
                $store->name,
                [
                    'city' => $store->city,
                    'role_in_store' => $pivotRole,
                ]
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "user-{$user->id}",
                "store-{$store->id}",
                'works_at',
                $pivotRole
            );
            $edgeId++;
        }

        // 5. Add overrides
        $overrides = $user->permissionOverrides()
            ->with('permission')
            ->active()
            ->get();

        foreach ($overrides as $override) {
            $nodeId = "override-{$override->id}";

            $nodes[] = $this->createNode(
                $nodeId,
                'permission',
                $override->permission?->name ?? 'Unknown',
                [
                    'type' => $override->type,
                    'is_override' => true,
                    'is_temporary' => $override->isTemporary(),
                    'expires_at' => $override->expires_at?->toIso8601String(),
                    'reason' => $override->reason,
                    'granted_by' => $override->grantedBy?->name,
                ]
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "user-{$user->id}",
                $nodeId,
                $override->type === 'grant' ? 'override_grant' : 'override_deny',
                $override->type
            );
            $edgeId++;
        }

        // Calculate effective permissions
        $effectivePerms = $this->calculateEffectivePermissions($user);

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
            'root' => "user-{$user->id}",
            'summary' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'by_type' => $this->countByType($nodes),
                'roles' => $user->roles->count(),
                'stores' => $user->stores->count(),
                'overrides' => $overrides->count(),
                'effective_permissions' => count($effectivePerms),
            ],
            'effective_permissions' => $effectivePerms,
        ]);
    }

    /**
     * Get graph for a specific store.
     *
     * @queryParam include_users bool Include users in this store. Default: true
     */
    public function store(Request $request, int $storeId): JsonResponse
    {
        $store = Store::with(['users.roles', 'modules'])->find($storeId);

        if (!$store) {
            return response()->json(['message' => 'Loja não encontrada.'], 404);
        }

        $includeUsers = $request->boolean('include_users', true);

        $nodes = [];
        $edges = [];
        $edgeId = 1;

        // 1. Root: store
        $nodes[] = $this->createNode(
            "store-{$store->id}",
            'store',
            $store->name,
            [
                'city' => $store->city,
                'users_count' => $store->users->count(),
                'modules_count' => $store->modules->count(),
            ]
        );

        // 2. Active modules in this store
        foreach ($store->modules as $module) {
            if ($module->pivot->is_active ?? false) {
                $nodes[] = $this->createNode(
                    "module-{$module->id}",
                    'module',
                    $module->name,
                    [
                        'icon' => $module->icon,
                    ]
                );

                $edges[] = $this->createEdge(
                    "e{$edgeId}",
                    "store-{$store->id}",
                    "module-{$module->id}",
                    'has_module'
                );
                $edgeId++;
            }
        }

        // 3. Users in this store
        if ($includeUsers) {
            foreach ($store->users as $user) {
                $storeRole = $user->pivot->role ?? 'vendedor';

                $nodes[] = $this->createNode(
                    "user-{$user->id}",
                    'user',
                    $user->name,
                    [
                        'email' => $user->email,
                        'role_in_store' => $storeRole,
                    ]
                );

                $edges[] = $this->createEdge(
                    "e{$edgeId}",
                    "store-{$store->id}",
                    "user-{$user->id}",
                    'has_user',
                    $storeRole
                );
                $edgeId++;
            }
        }

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
            'root' => "store-{$store->id}",
            'summary' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'by_type' => $this->countByType($nodes),
                'users' => $store->users->count(),
                'modules' => $store->modules->count(),
            ],
        ]);
    }

    /**
     * Get graph for a specific module.
     * Shows all roles and users with access.
     */
    public function module(Request $request, string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $dbModule = Module::find($moduleId);

        $nodes = [];
        $edges = [];
        $edgeId = 1;

        // 1. Root: module
        $nodes[] = $this->createNode(
            "module-{$moduleId}",
            'module',
            $module->getName(),
            [
                'icon' => $module->getIcon(),
                'is_active' => $dbModule?->is_active ?? false,
                'version' => $module->getVersion(),
            ]
        );

        // 2. Permissions in this module
        foreach ($module->getPermissions() as $perm) {
            $permName = $perm['name'];

            $nodes[] = $this->createNode(
                "perm-{$permName}",
                'permission',
                $perm['display_name'] ?? $permName,
                [
                    'type' => 'ability',
                ]
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "module-{$moduleId}",
                "perm-{$permName}",
                'contains'
            );
            $edgeId++;
        }

        // 3. Screens in this module
        foreach ($module->getScreens() as $screen) {
            $screenName = $screen['name'];

            $nodes[] = $this->createNode(
                "screen-{$screenName}",
                'screen',
                $screen['display_name'] ?? $screenName,
                []
            );

            $edges[] = $this->createEdge(
                "e{$edgeId}",
                "module-{$moduleId}",
                "screen-{$screenName}",
                'contains'
            );
            $edgeId++;
        }

        // 4. Roles with access
        $modulePermNames = collect($module->getPermissions())->pluck('name');
        $roles = Role::with('permissions')->get();

        foreach ($roles as $role) {
            $rolePerms = $role->permissions->pluck('name');
            $intersection = $modulePermNames->intersect($rolePerms);

            if ($intersection->isNotEmpty()) {
                $nodes[] = $this->createNode(
                    "role-{$role->name}",
                    'role',
                    $role->display_name ?? ucfirst($role->name),
                    [
                        'permissions_in_module' => $intersection->count(),
                        'total_permissions' => $modulePermNames->count(),
                    ]
                );

                $edges[] = $this->createEdge(
                    "e{$edgeId}",
                    "role-{$role->name}",
                    "module-{$moduleId}",
                    'has_access',
                    "{$intersection->count()}/{$modulePermNames->count()}"
                );
                $edgeId++;
            }
        }

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
            'root' => "module-{$moduleId}",
            'summary' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'by_type' => $this->countByType($nodes),
                'permissions' => count($module->getPermissions()),
                'screens' => count($module->getScreens()),
            ],
        ]);
    }

    // ========================================
    // Helper Methods
    // ========================================

    protected function createNode(string $id, string $type, string $label, array $metadata = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'data' => array_merge([
                'label' => $label,
                'icon' => $this->nodeTypes[$type]['icon'] ?? 'Circle',
            ], $metadata),
            'position' => ['x' => 0, 'y' => 0], // Frontend calculates with dagre/elkjs
        ];
    }

    protected function createEdge(string $id, string $source, string $target, string $type, ?string $label = null): array
    {
        $edge = [
            'id' => $id,
            'source' => $source,
            'target' => $target,
            'type' => $type,
        ];

        if ($label) {
            $edge['label'] = $label;
        }

        // Animate temporary/override edges
        if (in_array($type, ['override_grant', 'override_deny', 'temporary'])) {
            $edge['animated'] = true;
        }

        return $edge;
    }

    protected function countByType(array $nodes): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $type = $node['type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }

    protected function buildRoleHierarchy(iterable $roles): array
    {
        // Simple hierarchy based on level
        $hierarchy = [
            'vendedor' => 'gerente',
            'conferente' => 'gerente',
            'gerente' => 'admin',
            'fabrica' => 'admin',
            'admin' => 'super-admin',
        ];
        return $hierarchy;
    }

    protected function getModuleDisplayName(string $module): string
    {
        $map = [
            'global' => 'Global',
            'pedidos' => 'Pedidos',
            'capas' => 'Capas Personalizadas',
            'users' => 'Usuários',
            'stores' => 'Lojas',
            'admin' => 'Administração',
            'dashboard' => 'Dashboard',
            'producao' => 'Produção',
            'fabrica' => 'Fábrica',
        ];
        return $map[$module] ?? ucfirst($module);
    }

    protected function calculateEffectivePermissions(User $user): array
    {
        $permissions = [];

        // From roles
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $perm) {
                $permissions[$perm->name] = [
                    'name' => $perm->name,
                    'source' => 'role',
                    'source_name' => $role->name,
                ];
            }
        }

        // Apply overrides (load with permission relationship)
        $overrides = $user->permissionOverrides()->with('permission')->get();
        foreach ($overrides as $override) {
            if ($override->isExpired()) {
                continue;
            }

            $permName = $override->permission?->name;
            if (!$permName) {
                continue;
            }

            if ($override->granted) {
                $permissions[$permName] = [
                    'name' => $permName,
                    'source' => 'override',
                    'expires_at' => $override->expires_at?->toIso8601String(),
                ];
            } else {
                unset($permissions[$permName]);
            }
        }

        return array_values($permissions);
    }
}
