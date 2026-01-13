<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

/**
 * @group Autenticação
 *
 * Endpoints para recuperação e alteração de senha.
 */
class PasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogger $auditLogger,
        private WhatsAppNotificationService $whatsappService,
    ) {
    }

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
     * Solicitar recuperação de senha via WhatsApp
     *
     * Envia um código de 6 dígitos via WhatsApp para redefinir a senha.
     * O código expira em 15 minutos.
     *
     * **Quem pode usar:** Qualquer usuário com email ou WhatsApp cadastrado.
     *
     * **Rate Limit:** 3 tentativas por minuto por IP.
     *
     * @unauthenticated
     *
     * @bodyParam email string Email cadastrado (obrigatório se whatsapp não informado). Example: joao@maiscapinhas.com.br
     * @bodyParam whatsapp string WhatsApp cadastrado (obrigatório se email não informado). Example: 48999999999
     *
     * @response 200 scenario="Código enviado" {
     *   "data": { 
     *     "message": "Código enviado via WhatsApp.",
     *     "phone_masked": "****9999",
     *     "expires_in_minutes": 15
     *   },
     *   "meta": { "timestamp": "2026-01-13T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Usuário não encontrado" {
     *   "message": "Usuário não encontrado."
     * }
     *
     * @response 422 scenario="Usuário sem WhatsApp" {
     *   "message": "Usuário não possui WhatsApp cadastrado."
     * }
     *
     * @response 502 scenario="Falha ao enviar" {
     *   "message": "Falha ao enviar código via WhatsApp. Tente novamente."
     * }
     */
    public function forgotPasswordWhatsApp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required_without:whatsapp', 'nullable', 'email'],
            'whatsapp' => ['required_without:email', 'nullable', 'string'],
        ]);

        // Find user by email or whatsapp
        $user = $this->findUserByEmailOrWhatsApp(
            $request->input('email'),
            $request->input('whatsapp')
        );

        if (!$user) {
            return $this->error('Usuário não encontrado.', 422);
        }

        if (empty($user->whatsapp)) {
            return $this->error('Usuário não possui WhatsApp cadastrado.', 422);
        }

        // Generate 6-digit code
        $code = $this->generateCode();

        // Store code in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ]
        );

        // Send code via WhatsApp
        try {
            $sent = $this->whatsappService->sendPasswordResetCode($user, $code);

            if (!$sent) {
                return $this->error('Falha ao enviar código via WhatsApp. Tente novamente.', 502);
            }
        } catch (RuntimeException $e) {
            $this->auditLogger->log('auth.password_reset_whatsapp_failed', $user, [
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 422);
        }

        // Log da tentativa
        $this->auditLogger->log('auth.password_reset_whatsapp_sent', $user, [
            'phone_masked' => $this->whatsappService->maskPhoneNumber($user->whatsapp),
        ]);

        return $this->success([
            'message' => 'Código enviado via WhatsApp.',
            'phone_masked' => $this->whatsappService->maskPhoneNumber($user->whatsapp),
            'expires_in_minutes' => 15,
        ]);
    }

    /**
     * Redefinir senha com código WhatsApp
     *
     * Redefine a senha usando o código de 6 dígitos recebido via WhatsApp.
     *
     * **Quem pode usar:** Qualquer usuário com código válido.
     *
     * @unauthenticated
     *
     * @bodyParam code string required Código de 6 dígitos recebido via WhatsApp. Example: 123456
     * @bodyParam email string required Email do usuário. Example: joao@maiscapinhas.com.br
     * @bodyParam password string required Nova senha (mínimo 8 caracteres). Example: novaSenha123
     * @bodyParam password_confirmation string required Confirmação da nova senha. Example: novaSenha123
     *
     * @response 200 scenario="Senha alterada" {
     *   "data": { "message": "Senha alterada com sucesso." },
     *   "meta": { "timestamp": "2026-01-13T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Código inválido" {
     *   "message": "Código inválido ou expirado."
     * }
     */
    public function resetPasswordWithCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->error('Usuário não encontrado.', 422);
        }

        // Verify code
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record) {
            return $this->error('Código inválido ou expirado.', 422);
        }

        // Check if code matches
        if (!Hash::check($request->code, $record->token)) {
            return $this->error('Código inválido ou expirado.', 422);
        }

        // Check if code expired (15 minutes)
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->diffInMinutes(now()) > 15) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return $this->error('Código expirado. Solicite um novo.', 422);
        }

        // Reset password
        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete reset token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        event(new PasswordReset($user));

        // Log
        $this->auditLogger->log('auth.password_reset_completed', $user, [
            'method' => 'whatsapp_code',
            'tokens_revoked' => true,
        ]);

        return $this->success([
            'message' => 'Senha alterada com sucesso.',
        ]);
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

    /**
     * Find user by email or whatsapp.
     */
    private function findUserByEmailOrWhatsApp(?string $email, ?string $whatsapp): ?User
    {
        if ($email) {
            return User::where('email', $email)->first();
        }

        if ($whatsapp) {
            $normalized = $this->whatsappService->normalizePhoneNumber($whatsapp);

            // Search with different formats
            return User::where('whatsapp', $whatsapp)
                ->orWhere('whatsapp', $normalized)
                ->orWhere('whatsapp', 'LIKE', '%' . substr($normalized, -9))
                ->first();
        }

        return null;
    }

    /**
     * Generate 6-digit code.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

