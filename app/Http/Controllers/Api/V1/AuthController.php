<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Autenticação
 *
 * Endpoints para autenticação via Bearer Token (Sanctum).
 * A API suporta dois modos de autenticação:
 *
 * 1. **Bearer Token** (recomendado para desenvolvimento e integrações)
 * 2. **Cookie SPA** (para frontends React/Vue com `withCredentials`)
 *
 * **Auditoria:** Todas as ações de autenticação (login, logout) são
 * registradas no log de auditoria com IP e user-agent.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogger $auditLogger
    ) {
    }

    /**
     * Fazer login e obter token
     *
     * Autentica o usuário e retorna um Bearer Token para uso nas próximas requisições.
     *
     * **Quem pode usar:** Qualquer usuário ativo com email e senha válidos.
     *
     * **Regras de negócio:**
     * - Usuários inativos (`active = false`) não podem fazer login
     * - O token não expira automaticamente, mas pode ser revogado via logout
     * - Cada login gera um novo token (múltiplos dispositivos são permitidos)
     * - Todas as tentativas de login são registradas para auditoria
     *
     * **Erros possíveis:**
     * - `422` - Credenciais inválidas ou conta desativada
     *
     * @unauthenticated
     *
     * @bodyParam email string required Email do usuário. Example: admin@maiscapinhas.com.br
     * @bodyParam password string required Senha do usuário. Example: password
     * @bodyParam device_name string Nome do dispositivo (opcional, usado para identificar tokens). Example: postman-dev
     *
     * @response 200 scenario="Login bem-sucedido" {
     *   "data": {
     *     "token": "1|abc123xyz...",
     *     "token_type": "Bearer",
     *     "user": {
     *       "id": 1,
     *       "name": "Admin Sistema",
     *       "email": "admin@maiscapinhas.com.br"
     *     }
     *   },
     *   "meta": { "request_id": "abc-123", "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Credenciais inválidas" {
     *   "message": "The provided credentials are incorrect.",
     *   "errors": { "email": ["The provided credentials are incorrect."] }
     * }
     *
     * @response 422 scenario="Conta desativada" {
     *   "message": "This account is deactivated.",
     *   "errors": { "email": ["This account is deactivated."] }
     * }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        // Login falhou - registrar tentativa
        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->auditLogger->log('auth.login_failed', null, [
                'email' => $request->email,
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Conta desativada
        if (!$user->active) {
            $this->auditLogger->log('auth.login_failed', $user, [
                'email' => $request->email,
                'reason' => 'account_deactivated',
            ]);

            throw ValidationException::withMessages([
                'email' => ['This account is deactivated.'],
            ]);
        }

        $deviceName = $request->input('device_name', 'api-client');
        $token = $user->createToken($deviceName)->plainTextToken;

        // Registrar login bem sucedido
        $this->auditLogger->logAuth('login', $user, [
            'auth_mode' => 'bearer',
            'device_name' => $deviceName,
            'stores' => $user->storeUsers()->pluck('store_id')->toArray(),
        ]);

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Fazer logout (revogar token atual)
     *
     * Revoga o token que foi usado para autenticar esta requisição.
     * Os demais tokens do usuário (outros dispositivos) permanecem válidos.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Auditoria:** A ação de logout é registrada com IP e horário.
     *
     * @response 200 scenario="Logout bem-sucedido" {
     *   "data": { "message": "Successfully logged out." },
     *   "meta": { "request_id": "abc-123", "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Registrar logout
        $this->auditLogger->logAuth('logout', $user, [
            'auth_mode' => 'bearer',
            'token_id' => $user->currentAccessToken()->id,
        ]);

        $user->currentAccessToken()->delete();

        return $this->success([
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Revogar todos os tokens
     *
     * Revoga **todos** os tokens do usuário, desconectando-o de todos os dispositivos.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Auditoria:** A ação é registrada como logout_all.
     *
     * @response 200 scenario="Todos tokens revogados" {
     *   "data": { "message": "All tokens revoked." },
     *   "meta": { "request_id": "abc-123", "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokenCount = $user->tokens()->count();

        // Registrar logout_all
        $this->auditLogger->logAuth('logout_all', $user, [
            'auth_mode' => 'bearer',
            'tokens_revoked' => $tokenCount,
        ]);

        $user->tokens()->delete();

        return $this->success([
            'message' => 'All tokens revoked.',
        ]);
    }
}
