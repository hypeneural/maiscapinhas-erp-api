<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserPermissionOverride;
use App\Services\MenuBuilder;
use App\Services\PermissionResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated user endpoints.
 */
class UserController extends Controller
{
    private const EXPIRING_SOON_DAYS = 7;

    public function __construct(
        private PermissionResolver $permissionResolver,
        private MenuBuilder $menuBuilder
    ) {
    }

    /**
     * Return current authenticated user with permissions, screens, features, and menu.
     *
     * Includes dashboard_layout, temporary permissions, and expiring_soon alerts
     * as requested by the frontend team.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['storeUsers.store']);

        // Get current store context from header or first store
        $currentStoreId = $request->header('X-Store-Id')
            ? (int) $request->header('X-Store-Id')
            : $user->storeUsers->first()?->store_id;

        // Build stores array with role info
        $stores = $user->storeUsers->map(function ($storeUser) {
            return [
                'id' => $storeUser->store->id,
                'name' => $storeUser->store->name,
                'city' => $storeUser->store->city ?? null,
                'state' => $storeUser->store->state ?? null,
                'role' => $storeUser->role,
            ];
        });

        // Resolve permissions
        $permissions = $this->permissionResolver->resolvePermissions($user);
        $screens = $this->permissionResolver->resolveScreens($user);
        $features = $this->permissionResolver->resolveFeatures($user);

        // Get temporary permissions (with expiration)
        $temporaryPermissions = $this->getTemporaryPermissions($user);

        // Get permissions expiring soon
        $expiringSoon = $this->getExpiringSoon($user, self::EXPIRING_SOON_DAYS);

        // Build effective permissions for current store (global + store merged)
        $effectivePermissions = $this->buildEffectivePermissions($permissions, $currentStoreId);
        $effectiveScreens = $this->buildEffectiveScreens($screens, $currentStoreId);

        // Build filtered menu
        $menu = $this->menuBuilder->buildMenu($user);

        // Determine dashboard layout based on highest role
        $dashboardLayout = $this->determineDashboardLayout($user, $stores);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url ?? null,
                    'is_super_admin' => (bool) $user->is_super_admin,
                    'has_fabrica_access' => (bool) $user->has_fabrica_access,
                    'whatsapp' => $user->whatsapp ?? null,
                    'birth_date' => $user->birth_date?->format('Y-m-d'),
                    'hire_date' => $user->hire_date?->format('Y-m-d'),
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
                'stores' => $stores,
                'current_store_id' => $currentStoreId,
                'dashboard_layout' => $dashboardLayout,
                'permissions' => $permissions,
                'screens' => $screens,
                'features' => $features,
                'effective_permissions' => $effectivePermissions,
                'effective_screens' => $effectiveScreens,
                'temporary' => $temporaryPermissions,
                'expiring_soon' => $expiringSoon,
                'menu' => $menu,
                'meta' => [
                    'permissions_version' => 2,
                    'permissions_loaded_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Determine which dashboard layout the user should see.
     */
    private function determineDashboardLayout($user, $stores): string
    {
        if ($user->is_super_admin) {
            return 'admin';
        }

        // Get highest role level from stores
        $roles = $stores->pluck('role')->unique()->toArray();

        // Priority order: admin > gerente > conferente > vendedor
        if (in_array('admin', $roles)) {
            return 'admin';
        }
        if (in_array('gerente', $roles)) {
            return 'gerente';
        }
        if (in_array('conferente', $roles)) {
            return 'conferente';
        }

        return 'vendedor';
    }

    /**
     * Get temporary permissions (those with expiration dates).
     */
    private function getTemporaryPermissions($user): array
    {
        return UserPermissionOverride::where('user_id', $user->id)
            ->where('granted', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->with('permission')
            ->get()
            ->map(fn($override) => [
                'permission' => $override->permission->name,
                'display_name' => $override->permission->display_name,
                'expires_at' => $override->expires_at->toIso8601String(),
                'days_remaining' => now()->diffInDays($override->expires_at),
                'reason' => $override->reason,
                'store_id' => $override->store_id,
            ])
            ->toArray();
    }

    /**
     * Get permissions that are expiring within X days.
     */
    private function getExpiringSoon($user, int $days): array
    {
        $expiringDate = now()->addDays($days);

        return UserPermissionOverride::where('user_id', $user->id)
            ->where('granted', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $expiringDate)
            ->with('permission')
            ->get()
            ->map(fn($override) => [
                'permission' => $override->permission->name,
                'display_name' => $override->permission->display_name,
                'expires_at' => $override->expires_at->toIso8601String(),
                'days_remaining' => now()->diffInDays($override->expires_at),
            ])
            ->toArray();
    }

    /**
     * Build effective permissions merging global + current store.
     */
    private function buildEffectivePermissions(array $permissions, ?int $storeId): array
    {
        $effective = $permissions['global']['granted'] ?? [];

        if ($storeId && isset($permissions['by_store'][$storeId]['granted'])) {
            $effective = array_unique(array_merge(
                $effective,
                $permissions['by_store'][$storeId]['granted']
            ));
        }

        // Remove denied permissions
        $denied = $permissions['global']['denied'] ?? [];
        if ($storeId && isset($permissions['by_store'][$storeId]['denied'])) {
            $denied = array_merge($denied, $permissions['by_store'][$storeId]['denied']);
        }

        return array_values(array_diff($effective, $denied));
    }

    /**
     * Build effective screens merging global + current store.
     */
    private function buildEffectiveScreens(array $screens, ?int $storeId): array
    {
        $effective = $screens['global'] ?? [];

        if ($storeId && isset($screens['by_store'][$storeId])) {
            $effective = array_unique(array_merge(
                $effective,
                $screens['by_store'][$storeId]
            ));
        }

        return array_values($effective);
    }
}
