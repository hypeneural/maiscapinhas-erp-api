<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * Ensures the authenticated user is a super admin.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Apenas super admin pode acessar este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
