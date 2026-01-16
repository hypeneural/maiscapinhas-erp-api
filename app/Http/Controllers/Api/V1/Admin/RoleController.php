<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for Roles.
 *
 * @group Admin - Roles
 */
class RoleController extends Controller
{
    /**
     * List all roles.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')
            ->byLevel('desc')
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'level' => $role->level,
                    'is_system' => $role->is_system,
                    'permissions_count' => $role->permissions->count(),
                    'created_at' => $role->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['data' => $roles]);
    }

    /**
     * Get a specific role with permissions.
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'level' => $role->level,
                'is_system' => $role->is_system,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'display_name' => $p->display_name,
                    'type' => $p->type,
                    'module' => $p->module,
                ]),
                'created_at' => $role->created_at?->toIso8601String(),
                'updated_at' => $role->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a new role.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')],
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'level' => ['required', 'integer', 'min:1', 'max:99'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'level' => $validated['level'],
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        if (!empty($validated['permission_ids'])) {
            $role->permissions()->sync($validated['permission_ids']);
        }

        return response()->json([
            'message' => 'Role criado com sucesso.',
            'data' => ['id' => $role->id],
        ], 201);
    }

    /**
     * Update a role.
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        // System roles cannot be edited
        if ($role->is_system) {
            return response()->json([
                'message' => 'Roles do sistema não podem ser editados.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role->id)],
            'display_name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'level' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'display_name' => $validated['display_name'] ?? $role->display_name,
            'description' => $validated['description'] ?? $role->description,
            'level' => $validated['level'] ?? $role->level,
        ]);

        if (isset($validated['permission_ids'])) {
            $role->permissions()->sync($validated['permission_ids']);
        }

        return response()->json([
            'message' => 'Role atualizado com sucesso.',
        ]);
    }

    /**
     * Sync permissions for a role.
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->permissions()->sync($validated['permission_ids']);

        return response()->json([
            'message' => 'Permissões sincronizadas com sucesso.',
            'permissions_count' => $role->permissions()->count(),
        ]);
    }

    /**
     * Delete a role.
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function destroy(Role $role): JsonResponse
    {
        // System roles cannot be deleted
        if ($role->is_system) {
            return response()->json([
                'message' => 'Roles do sistema não podem ser excluídos.',
            ], 403);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role excluído com sucesso.',
        ]);
    }

    /**
     * Clone a role.
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function clone(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name', 'regex:/^[a-z-]+$/'],
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $newRole = Role::create([
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? "Clonado de {$role->display_name}",
            'level' => $role->level,
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        // Copy all permissions
        $newRole->permissions()->sync($role->permissions->pluck('id'));

        return response()->json([
            'message' => "Role clonada de '{$role->display_name}'.",
            'data' => [
                'id' => $newRole->id,
                'name' => $newRole->name,
                'display_name' => $newRole->display_name,
                'permissions_count' => $newRole->permissions()->count(),
                'cloned_from' => $role->name,
            ],
        ], 201);
    }

    /**
     * Update permissions with add/remove support.
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'add' => ['sometimes', 'array'],
            'add.*' => ['string', 'exists:permissions,name'],
            'remove' => ['sometimes', 'array'],
            'remove.*' => ['string', 'exists:permissions,name'],
        ]);

        if (!empty($validated['add'])) {
            $role->givePermissionTo($validated['add']);
        }
        if (!empty($validated['remove'])) {
            $role->revokePermissionTo($validated['remove']);
        }

        return response()->json([
            'message' => 'Permissões atualizadas.',
            'data' => [
                'role_id' => $role->id,
                'permissions' => $role->permissions()->pluck('name'),
                'permissions_count' => $role->permissions()->count(),
            ],
        ]);
    }
}

