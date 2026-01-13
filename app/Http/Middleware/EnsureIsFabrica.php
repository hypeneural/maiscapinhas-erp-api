<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsFabrica
{
    /**
     * Handle an incoming request.
     *
     * Ensures the authenticated user has the 'fabrica' role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasRole('fabrica')) {
            return response()->json([
                'message' => 'Acesso negado. Apenas fábrica.',
            ], 403);
        }

        return $next($request);
    }
}
