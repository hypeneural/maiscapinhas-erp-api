<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
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
 */
class AuthController extends Controller
{
    use ApiResponse;

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
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
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

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => ['This account is deactivated.'],
            ]);
        }

        $deviceName = $request->input('device_name', 'api-client');
        $token = $user->createToken($deviceName)->plainTextToken;

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
     * **Casos de uso:**
     * - Logout manual do usuário
     * - Encerrar sessão em um dispositivo específico
     *
     * @response 200 scenario="Logout bem-sucedido" {
     *   "data": { "message": "Successfully logged out." },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
     * **Casos de uso:**
     * - Suspeita de comprometimento de segurança
     * - Forçar re-login em todos os dispositivos
     * - Alteração de senha
     *
     * @response 200 scenario="Todos tokens revogados" {
     *   "data": { "message": "All tokens revoked." },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->success([
            'message' => 'All tokens revoked.',
        ]);
    }
}
