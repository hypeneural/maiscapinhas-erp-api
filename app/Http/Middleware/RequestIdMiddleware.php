<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para gerenciar Request ID e contexto de auditoria.
 * 
 * Funcionalidades:
 * - Gera ou captura X-Request-Id do header
 * - Armazena contexto (ip, user_agent, user_id) no AuditContext
 * - Integra com Log::withContext() para logs estruturados
 * - Adiciona X-Request-Id na response
 */
class RequestIdMiddleware
{
    public function __construct(
        private AuditContext $auditContext
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Gerar ou capturar Request ID
        $requestId = $request->header('X-Request-Id');
        if (!$requestId) {
            $requestId = (string) Str::uuid();
        }

        // 2. Capturar dados da requisição
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $route = $request->route()?->getName() ?? $request->path();
        $method = $request->method();

        // 3. Armazenar no AuditContext (singleton)
        $this->auditContext
            ->setRequestId($requestId)
            ->setIp($ip)
            ->setUserAgent($userAgent)
            ->setRoute($route)
            ->setMethod($method);

        // 4. Integrar com Log facade para logs estruturados
        Log::withContext([
            'request_id' => $requestId,
            'ip' => $ip,
            'route' => $route,
        ]);

        // 5. Processar requisição
        $response = $next($request);

        // 6. Atualizar user_id após autenticação (se disponível)
        if ($request->user()) {
            $this->auditContext->setUserId($request->user()->id);
        }

        // 7. Capturar store_id do request se existir
        $storeId = $request->input('store_id') ?? $request->route('store');
        if ($storeId && is_numeric($storeId)) {
            $this->auditContext->setStoreId((int) $storeId);
        }

        // 8. Adicionar Request ID na response
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
