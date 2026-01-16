<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StorePermissionOverride;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\UserStoreRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionResolver
{
    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Check if user has a specific permission.
     *
     * Resolution order (first match wins):
     * 1. Super Admin → always true
     * 2. User override (global or store-specific)
     * 3. Store override (if store context provided)
     * 4. Role permissions
     */
    public function can(User $user, string $permission, ?int $storeId = null): bool
    {
        // 1. Super admin has all permissions
        if ($user->is_super_admin) {
            return true;
        }

        // 2. Check user-level override (highest priority)
        $userOverride = $this->getUserOverride($user, $permission, $storeId);
        if ($userOverride !== null) {
            return $userOverride;
        }

        // 3. Check store-level override
        if ($storeId) {
            $storeOverride = $this->getStoreOverride($storeId, $permission);
            if ($storeOverride !== null) {
                return $storeOverride;
            }
        }

        // 4. Check role permissions
        return $this->hasRolePermission($user, $permission, $storeId);
    }

    /**
     * Get all resolved permissions for a user.
     *
     * @return array{global: array{granted: string[], denied: string[]}, by_store: array<string, array{granted: string[], denied: string[]}>}
     */
    public function resolvePermissions(User $user): array
    {
        $cacheKey = "user:{$user->id}:permissions";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->buildPermissions($user);
        });
    }

    /**
     * Get all resolved screens for a user.
     *
     * @return array{global: string[], by_store: array<string, string[]>}
     */
    public function resolveScreens(User $user): array
    {
        $cacheKey = "user:{$user->id}:screens";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->buildScreens($user);
        });
    }

    /**
     * Get all features for a user.
     *
     * @return string[]
     */
    public function resolveFeatures(User $user): array
    {
        $cacheKey = "user:{$user->id}:features";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            $permissions = $this->buildPermissions($user);
            $allGranted = array_merge(
                $permissions['global']['granted'],
                ...array_map(fn($s) => $s['granted'], $permissions['by_store'])
            );

            return array_values(array_filter($allGranted, fn($p) => str_starts_with($p, 'feature.')));
        });
    }

    /**
     * Clear permission cache for a user.
     */
    public function clearCache(User $user): void
    {
        Cache::forget("user:{$user->id}:permissions");
        Cache::forget("user:{$user->id}:screens");
        Cache::forget("user:{$user->id}:features");
    }

    /**
     * Clear all permission caches.
     */
    public function clearAllCaches(): void
    {
        Cache::flush(); // In production, use tagged caches
    }

    /**
     * Get the source of a permission for a user.
     *
     * Returns where the permission comes from: role, user_override, store_override, or none.
     *
     * @return array{source: string, source_name: ?string, granted: bool, temporary: ?array}
     */
    public function getPermissionSource(User $user, string $permission, ?int $storeId = null): array
    {
        // Super admin
        if ($user->is_super_admin) {
            return [
                'source' => 'super_admin',
                'source_name' => 'Super Admin',
                'granted' => true,
                'temporary' => null,
            ];
        }

        // Check user-level override first
        $userOverride = $this->getUserOverrideRecord($user, $permission, $storeId);
        if ($userOverride) {
            return [
                'source' => 'user_override',
                'source_name' => 'Override de Usuário',
                'granted' => $userOverride->granted,
                'temporary' => $userOverride->expires_at ? [
                    'expires_at' => $userOverride->expires_at->toIso8601String(),
                    'reason' => $userOverride->reason,
                ] : null,
            ];
        }

        // Check store-level override
        if ($storeId) {
            $storeOverride = StorePermissionOverride::where('store_id', $storeId)
                ->whereHas('permission', fn($q) => $q->where('name', $permission))
                ->first();
            if ($storeOverride) {
                return [
                    'source' => 'store_override',
                    'source_name' => 'Override de Loja',
                    'granted' => $storeOverride->granted,
                    'temporary' => null,
                ];
            }
        }

        // Check role permissions
        $roles = $this->getUserRoles($user, $storeId);
        foreach ($roles as $role) {
            if ($role->permissions->contains('name', $permission)) {
                return [
                    'source' => 'role',
                    'source_name' => $role->display_name ?? $role->name,
                    'granted' => true,
                    'temporary' => null,
                ];
            }
        }

        return [
            'source' => 'none',
            'source_name' => null,
            'granted' => false,
            'temporary' => null,
        ];
    }

    /**
     * Get the user override record (not just the boolean).
     */
    private function getUserOverrideRecord(User $user, string $permission, ?int $storeId): ?UserPermissionOverride
    {
        $query = UserPermissionOverride::where('user_id', $user->id)
            ->whereHas('permission', fn($q) => $q->where('name', $permission))
            ->active();

        // Check store-specific first, then global
        if ($storeId) {
            $storeOverride = (clone $query)->where('store_id', $storeId)->first();
            if ($storeOverride) {
                return $storeOverride;
            }
        }

        // Check global override
        return $query->whereNull('store_id')->first();
    }

    // ========================================
    // Private Methods
    // ========================================

    private function getUserOverride(User $user, string $permission, ?int $storeId): ?bool
    {
        $query = UserPermissionOverride::where('user_id', $user->id)
            ->whereHas('permission', fn($q) => $q->where('name', $permission))
            ->active();

        // Check store-specific first, then global
        if ($storeId) {
            $storeOverride = (clone $query)->where('store_id', $storeId)->first();
            if ($storeOverride) {
                return $storeOverride->granted;
            }
        }

        // Check global override
        $globalOverride = $query->whereNull('store_id')->first();
        if ($globalOverride) {
            return $globalOverride->granted;
        }

        return null;
    }

    private function getStoreOverride(int $storeId, string $permission): ?bool
    {
        $override = StorePermissionOverride::where('store_id', $storeId)
            ->whereHas('permission', fn($q) => $q->where('name', $permission))
            ->first();

        return $override?->granted;
    }

    private function hasRolePermission(User $user, string $permission, ?int $storeId): bool
    {
        $roles = $this->getUserRoles($user, $storeId);

        foreach ($roles as $role) {
            if ($role->permissions->contains('name', $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user roles (global + store-specific).
     *
     * @return Collection<Role>
     */
    private function getUserRoles(User $user, ?int $storeId = null): Collection
    {
        $query = UserStoreRole::where('user_id', $user->id)
            ->with('role.permissions');

        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')
                    ->orWhere('store_id', $storeId);
            });
        } else {
            $query->whereNull('store_id');
        }

        return $query->get()->pluck('role');
    }

    private function buildPermissions(User $user): array
    {
        $global = ['granted' => [], 'denied' => []];
        $byStore = [];

        // Get all permissions
        $allPermissions = Permission::abilities()->pluck('name')->toArray();

        // Super admin gets everything
        if ($user->is_super_admin) {
            $global['granted'] = $allPermissions;
            return ['global' => $global, 'by_store' => $byStore];
        }

        // Get user's stores
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        // 1. Get global role permissions
        $globalRoles = UserStoreRole::where('user_id', $user->id)
            ->whereNull('store_id')
            ->with('role.permissions')
            ->get()
            ->pluck('role');

        foreach ($globalRoles as $role) {
            foreach ($role->permissions as $perm) {
                if ($perm->type === 'ability' && !in_array($perm->name, $global['granted'])) {
                    $global['granted'][] = $perm->name;
                }
            }
        }

        // 2. Apply global user overrides
        $globalOverrides = UserPermissionOverride::where('user_id', $user->id)
            ->whereNull('store_id')
            ->active()
            ->with('permission')
            ->get();

        foreach ($globalOverrides as $override) {
            if ($override->permission->type !== 'ability')
                continue;

            $permName = $override->permission->name;
            if ($override->granted) {
                if (!in_array($permName, $global['granted'])) {
                    $global['granted'][] = $permName;
                }
                $global['denied'] = array_filter($global['denied'], fn($p) => $p !== $permName);
            } else {
                $global['granted'] = array_filter($global['granted'], fn($p) => $p !== $permName);
                if (!in_array($permName, $global['denied'])) {
                    $global['denied'][] = $permName;
                }
            }
        }

        // 3. Build per-store permissions
        foreach ($storeIds as $storeId) {
            $storePerms = ['granted' => [], 'denied' => []];

            // Get store-specific role permissions
            $storeRoles = UserStoreRole::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->with('role.permissions')
                ->get()
                ->pluck('role');

            foreach ($storeRoles as $role) {
                foreach ($role->permissions as $perm) {
                    if ($perm->type === 'ability' && !in_array($perm->name, $storePerms['granted'])) {
                        $storePerms['granted'][] = $perm->name;
                    }
                }
            }

            // Apply store-level overrides
            $storeOverrides = StorePermissionOverride::where('store_id', $storeId)
                ->with('permission')
                ->get();

            foreach ($storeOverrides as $override) {
                if ($override->permission->type !== 'ability')
                    continue;

                $permName = $override->permission->name;
                if ($override->granted) {
                    if (!in_array($permName, $storePerms['granted'])) {
                        $storePerms['granted'][] = $permName;
                    }
                } else {
                    if (!in_array($permName, $storePerms['denied'])) {
                        $storePerms['denied'][] = $permName;
                    }
                }
            }

            // Apply user-level store-specific overrides
            $userStoreOverrides = UserPermissionOverride::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->active()
                ->with('permission')
                ->get();

            foreach ($userStoreOverrides as $override) {
                if ($override->permission->type !== 'ability')
                    continue;

                $permName = $override->permission->name;
                if ($override->granted) {
                    if (!in_array($permName, $storePerms['granted'])) {
                        $storePerms['granted'][] = $permName;
                    }
                    $storePerms['denied'] = array_filter($storePerms['denied'], fn($p) => $p !== $permName);
                } else {
                    $storePerms['granted'] = array_filter($storePerms['granted'], fn($p) => $p !== $permName);
                    if (!in_array($permName, $storePerms['denied'])) {
                        $storePerms['denied'][] = $permName;
                    }
                }
            }

            // Only include if there are extra permissions beyond global
            if (!empty($storePerms['granted']) || !empty($storePerms['denied'])) {
                $byStore[(string) $storeId] = [
                    'granted' => array_values($storePerms['granted']),
                    'denied' => array_values($storePerms['denied']),
                ];
            }
        }

        return [
            'global' => [
                'granted' => array_values($global['granted']),
                'denied' => array_values($global['denied']),
            ],
            'by_store' => $byStore,
        ];
    }

    private function buildScreens(User $user): array
    {
        $global = [];
        $byStore = [];

        // Super admin gets everything
        if ($user->is_super_admin) {
            $global = Permission::screens()->pluck('name')->toArray();
            return ['global' => $global, 'by_store' => $byStore];
        }

        // Get user's stores
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        // 1. Get global role screens
        $globalRoles = UserStoreRole::where('user_id', $user->id)
            ->whereNull('store_id')
            ->with('role.permissions')
            ->get()
            ->pluck('role');

        foreach ($globalRoles as $role) {
            foreach ($role->permissions as $perm) {
                if ($perm->type === 'screen' && !in_array($perm->name, $global)) {
                    $global[] = $perm->name;
                }
            }
        }

        // 2. Apply global user overrides for screens
        $globalOverrides = UserPermissionOverride::where('user_id', $user->id)
            ->whereNull('store_id')
            ->active()
            ->with('permission')
            ->get();

        foreach ($globalOverrides as $override) {
            if ($override->permission->type !== 'screen')
                continue;

            $permName = $override->permission->name;
            if ($override->granted && !in_array($permName, $global)) {
                $global[] = $permName;
            } elseif (!$override->granted) {
                $global = array_filter($global, fn($p) => $p !== $permName);
            }
        }

        // 3. Build per-store screens
        foreach ($storeIds as $storeId) {
            $storeScreens = [];

            // Get store-specific role screens
            $storeRoles = UserStoreRole::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->with('role.permissions')
                ->get()
                ->pluck('role');

            foreach ($storeRoles as $role) {
                foreach ($role->permissions as $perm) {
                    if ($perm->type === 'screen' && !in_array($perm->name, $storeScreens)) {
                        $storeScreens[] = $perm->name;
                    }
                }
            }

            // Apply store and user overrides...
            // (similar logic as abilities)

            if (!empty($storeScreens)) {
                $byStore[(string) $storeId] = array_values($storeScreens);
            }
        }

        return [
            'global' => array_values($global),
            'by_store' => $byStore,
        ];
    }
}
