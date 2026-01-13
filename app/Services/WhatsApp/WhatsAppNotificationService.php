<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppInstance;
use RuntimeException;

class WhatsAppNotificationService
{
    public function __construct(
        private EvolutionClientFactory $clientFactory,
    ) {
    }

    /**
     * Send password reset code via WhatsApp.
     *
     * @throws RuntimeException if no active instance or user has no whatsapp
     */
    public function sendPasswordResetCode(User $user, string $code): bool
    {
        if (empty($user->whatsapp)) {
            throw new RuntimeException('Usuário não possui WhatsApp cadastrado.');
        }

        $instance = $this->getDefaultInstance();
        if (!$instance) {
            throw new RuntimeException('Nenhuma instância WhatsApp ativa disponível.');
        }

        $phone = $this->normalizePhoneNumber($user->whatsapp);

        $message = $this->buildPasswordResetMessage($code);

        $client = $this->clientFactory->make($instance);
        $result = $client->sendText($phone, $message);

        return $result['ok'] ?? false;
    }

    /**
     * Send custom message via WhatsApp.
     */
    public function sendMessage(string $phone, string $message, ?WhatsAppInstance $instance = null): array
    {
        $instance = $instance ?? $this->getDefaultInstance();
        if (!$instance) {
            return ['ok' => false, 'error' => 'Nenhuma instância WhatsApp ativa.'];
        }

        $phone = $this->normalizePhoneNumber($phone);

        $client = $this->clientFactory->make($instance);
        return $client->sendText($phone, $message);
    }

    /**
     * Normalize phone number to E164 format with Brazilian DDI (55).
     *
     * Examples:
     * - 48999999999 -> 5548999999999
     * - 5548999999999 -> 5548999999999 (already has DDI)
     * - +5548999999999 -> 5548999999999 (removes +)
     * - (48) 99999-9999 -> 5548999999999 (removes formatting)
     */
    public function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/\D/', '', $phone);

        // If starts with 55 and has 13 digits, it's already normalized
        if (str_starts_with($phone, '55') && strlen($phone) === 13) {
            return $phone;
        }

        // If has 11 digits (DDD + 9 digits), add DDI 55
        if (strlen($phone) === 11) {
            return '55' . $phone;
        }

        // If has 10 digits (DDD + 8 digits, old format), add DDI 55
        if (strlen($phone) === 10) {
            return '55' . $phone;
        }

        // If has 13 digits but doesn't start with 55, assume it's wrong
        // Just return as-is and let Evolution handle it
        return $phone;
    }

    /**
     * Get default active WhatsApp instance.
     */
    public function getDefaultInstance(): ?WhatsAppInstance
    {
        return WhatsAppInstance::query()
            ->active()
            ->default()
            ->global()
            ->first();
    }

    /**
     * Mask phone number for display (privacy).
     * Example: 5548999999999 -> ****9999
     */
    public function maskPhoneNumber(string $phone): string
    {
        $normalized = $this->normalizePhoneNumber($phone);
        $lastFour = substr($normalized, -4);
        return '****' . $lastFour;
    }

    /**
     * Build password reset message.
     */
    private function buildPasswordResetMessage(string $code): string
    {
        $appName = config('app.name', 'MaisCapinhas');

        return "🔐 *{$appName} - Recuperação de Senha*\n\n" .
            "Seu código de verificação é:\n\n" .
            "👉 *{$code}*\n\n" .
            "Este código expira em *15 minutos*.\n\n" .
            "Se você não solicitou este código, ignore esta mensagem.";
    }
}
