<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if user has required permission(s).
 *
 * Usage in routes:
 * - Single permission: ->middleware('permission:pedidos.create')
 * - Multiple (OR): ->middleware('permission:pedidos.create,pedidos.update')
 * - With store context: Permission is checked against current store
 */
class CheckPermission
{
    public function __construct(
        private PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string ...$permissions Comma-separated permission names (OR logic)
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Não autenticado.',
            ], 401);
        }

        // Super admin bypasses all permission checks
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Get store context from request (if any)
        $storeId = $this->getStoreIdFromRequest($request);

        // Check if user has ANY of the required permissions (OR logic)
        // Flatten in case permissions are passed as comma-separated string
        $flatPermissions = [];
        foreach ($permissions as $perm) {
            $flatPermissions = array_merge($flatPermissions, explode(',', $perm));
        }

        foreach ($flatPermissions as $permission) {
            $permission = trim($permission);
            if ($this->permissionResolver->can($user, $permission, $storeId)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Você não tem permissão para executar esta ação.',
            'required_permissions' => $flatPermissions,
        ], 403);
    }

    /**
     * Try to extract store ID from various request sources.
     */
    private function getStoreIdFromRequest(Request $request): ?int
    {
        // Check route parameter
        if ($request->route('store')) {
            $store = $request->route('store');
            return is_object($store) ? $store->id : (int) $store;
        }

        // Check query parameter
        if ($request->has('store_id')) {
            return (int) $request->store_id;
        }

        // Check request body
        if ($request->has('store_id')) {
            return (int) $request->input('store_id');
        }

        // Check header
        if ($request->hasHeader('X-Store-Id')) {
            return (int) $request->header('X-Store-Id');
        }

        return null;
    }
}
