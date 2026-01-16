<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for User Permission Overrides.
 * Super Admin only - Manage user-specific permission grants/denials.
 *
 * @group Admin - User Permission Overrides
 */
class UserPermissionOverrideController extends Controller
{
    public function __construct(
        private PermissionResolver $permissionResolver
    ) {
    }

    /**
     * List all overrides for a user.
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $overrides = UserPermissionOverride::where('user_id', $user->id)
            ->with(['permission', 'store', 'grantedByUser'])
            ->orderBy('store_id')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($o) => $this->formatOverride($o));

        return response()->json([
            'data' => $overrides,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }

    /**
     * Grant or deny a permission to a user.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'permission_id' => ['required', 'exists:permissions,id'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'granted' => ['required', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $override = UserPermissionOverride::updateOrCreate(
            [
                'user_id' => $user->id,
                'permission_id' => $validated['permission_id'],
                'store_id' => $validated['store_id'] ?? null,
            ],
            [
                'granted' => $validated['granted'],
                'expires_at' => $validated['expires_at'] ?? null,
                'granted_by' => $request->user()->id,
                'reason' => $validated['reason'] ?? null,
            ]
        );

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        $permission = Permission::find($validated['permission_id']);
        $action = $validated['granted'] ? 'liberada' : 'negada';

        return response()->json([
            'message' => "Permissão '{$permission->display_name}' {$action} para {$user->name}.",
            'data' => $this->formatOverride($override->fresh(['permission', 'store', 'grantedByUser'])),
        ], 201);
    }

    /**
     * Update an existing override.
     */
    public function update(Request $request, User $user, UserPermissionOverride $override): JsonResponse
    {
        abort_if($override->user_id !== $user->id, 404);

        $validated = $request->validate([
            'granted' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $override->update([
            'granted' => $validated['granted'] ?? $override->granted,
            'expires_at' => array_key_exists('expires_at', $validated) ? $validated['expires_at'] : $override->expires_at,
            'reason' => $validated['reason'] ?? $override->reason,
            'granted_by' => $request->user()->id,
        ]);

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => 'Override atualizado.',
            'data' => $this->formatOverride($override->fresh(['permission', 'store', 'grantedByUser'])),
        ]);
    }

    /**
     * Remove an override (reverts to role-based permission).
     */
    public function destroy(User $user, UserPermissionOverride $override): JsonResponse
    {
        abort_if($override->user_id !== $user->id, 404);

        $permissionName = $override->permission->display_name;
        $override->delete();

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => "Override para '{$permissionName}' removido. Permissão volta ao padrão do role.",
        ]);
    }

    /**
     * Bulk grant/deny permissions.
     */
    public function bulkStore(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'overrides' => ['required', 'array', 'min:1'],
            'overrides.*.permission_id' => ['required', 'exists:permissions,id'],
            'overrides.*.store_id' => ['nullable', 'exists:stores,id'],
            'overrides.*.granted' => ['required', 'boolean'],
            'overrides.*.expires_at' => ['nullable', 'date', 'after:now'],
            'overrides.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $created = 0;
        $updated = 0;

        foreach ($validated['overrides'] as $overrideData) {
            $result = UserPermissionOverride::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'permission_id' => $overrideData['permission_id'],
                    'store_id' => $overrideData['store_id'] ?? null,
                ],
                [
                    'granted' => $overrideData['granted'],
                    'expires_at' => $overrideData['expires_at'] ?? null,
                    'granted_by' => $request->user()->id,
                    'reason' => $overrideData['reason'] ?? null,
                ]
            );
            $result->wasRecentlyCreated ? $created++ : $updated++;
        }

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => "Processado: {$created} criados, {$updated} atualizados.",
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * Remove all overrides for a user (optionally filtered by store).
     */
    public function clear(Request $request, User $user): JsonResponse
    {
        $query = UserPermissionOverride::where('user_id', $user->id);

        if ($request->has('store_id')) {
            if ($request->store_id === 'null' || $request->store_id === '') {
                $query->whereNull('store_id');
            } else {
                $query->where('store_id', $request->store_id);
            }
        }

        $count = $query->count();
        $query->delete();

        // Clear user's permission cache
        $this->permissionResolver->clearCache($user);

        return response()->json([
            'message' => "{$count} override(s) removido(s).",
            'deleted' => $count,
        ]);
    }

    private function formatOverride(UserPermissionOverride $override): array
    {
        return [
            'id' => $override->id,
            'permission' => [
                'id' => $override->permission->id,
                'name' => $override->permission->name,
                'display_name' => $override->permission->display_name,
                'type' => $override->permission->type,
                'module' => $override->permission->module,
            ],
            'store' => $override->store ? [
                'id' => $override->store->id,
                'name' => $override->store->name,
            ] : null,
            'granted' => $override->granted,
            'is_global' => $override->store_id === null,
            'expires_at' => $override->expires_at?->toIso8601String(),
            'is_temporary' => $override->expires_at !== null,
            'is_expired' => $override->isExpired(),
            'granted_by' => $override->grantedByUser ? [
                'id' => $override->grantedByUser->id,
                'name' => $override->grantedByUser->name,
            ] : null,
            'reason' => $override->reason,
            'created_at' => $override->created_at?->toIso8601String(),
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get effective permissions for a user with source information.
     *
     * This endpoint shows the final resolved state of all permissions
     * for a user, including where each permission comes from (role, store override, user override).
     */
    public function effective(User $user, ?int $storeId = null): JsonResponse
    {
        // Get all permissions
        $allPermissions = \App\Models\Permission::ordered()->get();

        // Resolve for this user
        $resolved = [];
        foreach ($allPermissions as $permission) {
            $source = $this->permissionResolver->getPermissionSource($user, $permission->name, $storeId);
            $granted = $this->permissionResolver->can($user, $permission->name, $storeId);

            $resolved[] = [
                'permission' => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'type' => $permission->type,
                    'module' => $permission->module,
                ],
                'granted' => $granted,
                'source' => $source['source'] ?? 'none',
                'source_name' => $source['source_name'] ?? null,
                'inherited_from_role' => $source['source'] === 'role',
                'is_overridden' => in_array($source['source'] ?? '', ['user_override', 'store_override']),
                'temporary' => $source['temporary'] ?? null,
            ];
        }

        // Group by module
        $byModule = collect($resolved)->groupBy('permission.module');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'store_id' => $storeId,
            'data' => $resolved,
            'by_module' => $byModule,
            'summary' => [
                'total' => count($resolved),
                'granted' => collect($resolved)->where('granted', true)->count(),
                'denied' => collect($resolved)->where('granted', false)->count(),
                'overridden' => collect($resolved)->where('is_overridden', true)->count(),
            ],
        ]);
    }
}
