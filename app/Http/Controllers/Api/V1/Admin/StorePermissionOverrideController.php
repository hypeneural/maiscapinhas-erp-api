<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Store;
use App\Models\StorePermissionOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin CRUD for Store Permission Overrides.
 * Super Admin only - Manage store-specific permission grants/denials.
 *
 * @group Admin - Store Permission Overrides
 */
class StorePermissionOverrideController extends Controller
{
    /**
     * List all overrides for a store.
     */
    public function index(Store $store): JsonResponse
    {
        $overrides = StorePermissionOverride::where('store_id', $store->id)
            ->with('permission')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($o) => $this->formatOverride($o));

        return response()->json([
            'data' => $overrides,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
        ]);
    }

    /**
     * Grant or deny a permission for all users in a store.
     */
    public function store(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'permission_id' => ['required', 'exists:permissions,id'],
            'granted' => ['required', 'boolean'],
        ]);

        $override = StorePermissionOverride::updateOrCreate(
            [
                'store_id' => $store->id,
                'permission_id' => $validated['permission_id'],
            ],
            [
                'granted' => $validated['granted'],
            ]
        );

        $permission = Permission::find($validated['permission_id']);
        $action = $validated['granted'] ? 'liberada' : 'negada';

        return response()->json([
            'message' => "Permissão '{$permission->display_name}' {$action} para a loja {$store->name}.",
            'data' => $this->formatOverride($override->fresh('permission')),
        ], 201);
    }

    /**
     * Update an existing store override.
     */
    public function update(Request $request, Store $store, StorePermissionOverride $override): JsonResponse
    {
        abort_if($override->store_id !== $store->id, 404);

        $validated = $request->validate([
            'granted' => ['required', 'boolean'],
        ]);

        $override->update([
            'granted' => $validated['granted'],
        ]);

        return response()->json([
            'message' => 'Override atualizado.',
            'data' => $this->formatOverride($override->fresh('permission')),
        ]);
    }

    /**
     * Remove an override (reverts to role-based permission).
     */
    public function destroy(Store $store, StorePermissionOverride $override): JsonResponse
    {
        abort_if($override->store_id !== $store->id, 404);

        $permissionName = $override->permission->display_name;
        $override->delete();

        return response()->json([
            'message' => "Override para '{$permissionName}' removido.",
        ]);
    }

    /**
     * Bulk grant/deny permissions for a store.
     */
    public function bulkStore(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'overrides' => ['required', 'array', 'min:1'],
            'overrides.*.permission_id' => ['required', 'exists:permissions,id'],
            'overrides.*.granted' => ['required', 'boolean'],
        ]);

        $created = 0;
        $updated = 0;

        foreach ($validated['overrides'] as $overrideData) {
            $result = StorePermissionOverride::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'permission_id' => $overrideData['permission_id'],
                ],
                [
                    'granted' => $overrideData['granted'],
                ]
            );
            $result->wasRecentlyCreated ? $created++ : $updated++;
        }

        return response()->json([
            'message' => "Processado: {$created} criados, {$updated} atualizados.",
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * Remove all overrides for a store.
     */
    public function clear(Store $store): JsonResponse
    {
        $count = StorePermissionOverride::where('store_id', $store->id)->count();
        StorePermissionOverride::where('store_id', $store->id)->delete();

        return response()->json([
            'message' => "{$count} override(s) removido(s).",
            'deleted' => $count,
        ]);
    }

    private function formatOverride(StorePermissionOverride $override): array
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
            'granted' => $override->granted,
            'created_at' => $override->created_at?->toIso8601String(),
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }
}
