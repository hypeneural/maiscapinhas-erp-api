<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * @group Autenticação
 *
 * Endpoints para recuperação e alteração de senha.
 */
class PasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogger $auditLogger
    ) {}

    /**
     * Solicitar recuperação de senha
     *
     * Envia um email com link para redefinir a senha.
     * O link expira em 60 minutos.
     *
     * **Quem pode usar:** Qualquer usuário com email cadastrado.
     *
     * **Rate Limit:** 3 tentativas por minuto por IP.
     *
     * @unauthenticated
     *
     * @bodyParam email string required Email cadastrado. Example: joao@maiscapinhas.com.br
     *
     * @response 200 scenario="Email enviado" {
     *   "data": { "message": "Email de recuperação enviado." },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Email não encontrado" {
     *   "message": "Não encontramos um usuário com esse email."
     * }
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Log da tentativa
        $this->auditLogger->log('auth.password_reset_requested', null, [
            'email' => $request->email,
            'status' => $status,
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success([
                'message' => 'Email de recuperação enviado.',
            ]);
        }

        return $this->error(
            __($status),
            422
        );
    }

    /**
     * Redefinir senha
     *
     * Redefine a senha usando o token recebido por email.
     *
     * **Quem pode usar:** Qualquer usuário com token válido.
     *
     * @unauthenticated
     *
     * @bodyParam token string required Token recebido por email. Example: abc123...
     * @bodyParam email string required Email do usuário. Example: joao@maiscapinhas.com.br
     * @bodyParam password string required Nova senha (mínimo 8 caracteres). Example: novaSenha123
     * @bodyParam password_confirmation string required Confirmação da nova senha. Example: novaSenha123
     *
     * @response 200 scenario="Senha alterada" {
     *   "data": { "message": "Senha alterada com sucesso." },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Token inválido" {
     *   "message": "Token de recuperação inválido ou expirado."
     * }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revogar todos os tokens existentes
                $user->tokens()->delete();

                event(new PasswordReset($user));

                // Log da alteração
                $this->auditLogger->log('auth.password_reset_completed', $user, [
                    'tokens_revoked' => true,
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success([
                'message' => 'Senha alterada com sucesso.',
            ]);
        }

        return $this->error(
            __($status),
            422
        );
    }

    /**
     * Alterar própria senha
     *
     * Permite ao usuário logado alterar sua própria senha.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * @bodyParam current_password string required Senha atual. Example: senhaAtual123
     * @bodyParam password string required Nova senha (mínimo 8 caracteres). Example: novaSenha123
     * @bodyParam password_confirmation string required Confirmação da nova senha. Example: novaSenha123
     *
     * @response 200 scenario="Senha alterada" {
     *   "data": { "message": "Senha alterada com sucesso." },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Senha atual incorreta" {
     *   "message": "A senha atual está incorreta."
     * }
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Log da alteração
        $this->auditLogger->logAuth('password_changed', $user);

        return $this->success([
            'message' => 'Senha alterada com sucesso.',
        ]);
    }
}
