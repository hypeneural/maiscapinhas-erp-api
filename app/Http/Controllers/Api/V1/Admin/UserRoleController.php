<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin API for managing user role assignments.
 * Super Admin only - Assign/remove roles to users (global or per-store).
 *
 * @group Admin - User Role Assignments
 */
class UserRoleController extends Controller
{
    public function __construct(
        private PermissionResolver $permissionResolver
    ) {
    }

    /**
     * List all role assignments for a user.
     */
    public function index(User $user): JsonResponse
    {
        $assignments = UserStoreRole::where('user_id', $user->id)
            ->with(['role', 'store'])
            ->orderBy('store_id')
            ->get()
            ->map(fn($a) => $this->formatAssignment($a));

        // Group by global vs store-specific
        $global = $assignments->filter(fn($a) => $a['is_global']);
        $storeSpecific = $assignments->filter(fn($a) => !$a['is_global'])->groupBy('store.id');

        return response()->json([
            'data' => $assignments,
            'summary' => [
                'global_roles' => $global->pluck('role.name')->toArray(),
                'store_count' => $storeSpecific->count(),
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Assign a role to a user (global or store-specific).
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'store_id' => ['nullable', 'exists:stores,id'],
        ]);

        // Check if assignment already exists
        $exists = UserStoreRole::where('user_id', $user->id)
            ->where('role_id', $validated['role_id'])
            ->where('store_id', $validated['store_id'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Este role já está atribuído ao usuário.',
            ], 409);
        }

        $assignment = UserStoreRole::create([
            'user_id' => $user->id,
            'role_id' => $validated['role_id'],
            'store_id' => $validated['store_id'] ?? null,
        ]);

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        $role = Role::find($validated['role_id']);
        $scope = isset($validated['store_id'])
            ? "na loja " . Store::find($validated['store_id'])->name
            : "globalmente";

        return response()->json([
            'message' => "Role '{$role->display_name}' atribuído {$scope}.",
            'data' => $this->formatAssignment($assignment->fresh(['role', 'store'])),
        ], 201);
    }

    /**
     * Remove a role assignment from a user.
     */
    public function destroy(User $user, UserStoreRole $assignment): JsonResponse
    {
        abort_if($assignment->user_id !== $user->id, 404);

        $roleName = $assignment->role->display_name;
        $assignment->delete();

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => "Role '{$roleName}' removido do usuário.",
        ]);
    }

    /**
     * Sync roles for a user (replaces all current assignments).
     */
    public function sync(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'assignments' => ['required', 'array'],
            'assignments.*.role_id' => ['required', 'exists:roles,id'],
            'assignments.*.store_id' => ['nullable', 'exists:stores,id'],
        ]);

        // Remove all current assignments
        UserStoreRole::where('user_id', $user->id)->delete();

        // Create new assignments
        $created = 0;
        foreach ($validated['assignments'] as $data) {
            UserStoreRole::create([
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'store_id' => $data['store_id'] ?? null,
            ]);
            $created++;
        }

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => "{$created} role(s) atribuído(s) ao usuário.",
            'count' => $created,
        ]);
    }

    /**
     * List available roles for assignment.
     */
    public function availableRoles(): JsonResponse
    {
        $roles = Role::byLevel('desc')->get()->map(fn($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'display_name' => $r->display_name,
            'description' => $r->description,
            'level' => $r->level,
            'is_system' => $r->is_system,
        ]);

        return response()->json(['data' => $roles]);
    }

    private function formatAssignment(UserStoreRole $assignment): array
    {
        return [
            'id' => $assignment->id,
            'role' => [
                'id' => $assignment->role->id,
                'name' => $assignment->role->name,
                'display_name' => $assignment->role->display_name,
                'level' => $assignment->role->level,
            ],
            'store' => $assignment->store ? [
                'id' => $assignment->store->id,
                'name' => $assignment->store->name,
            ] : null,
            'is_global' => $assignment->store_id === null,
            'created_at' => $assignment->created_at?->toIso8601String(),
        ];
    }
}
