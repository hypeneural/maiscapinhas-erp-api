<?php

declare(strict_types=1);

namespace App\Modules\Traits;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Mail;

/**
 * Trait HasNotifications
 *
 * Provides notification capabilities for modules.
 * Supports WhatsApp and Email notifications.
 *
 * Usage:
 *   use HasNotifications;
 *   
 *   // In your service/controller:
 *   $this->module->sendStatusNotification($record, $newStatus);
 */
trait HasNotifications
{
    /**
     * Notification templates per status.
     * Override in your module.
     */
    protected array $notificationTemplates = [];

    /**
     * Send notification for status change.
     */
    public function sendStatusNotification($record, int $newStatus, string $channel = 'whatsapp'): bool
    {
        $template = $this->getNotificationTemplate($newStatus);
        if (!$template) {
            return false;
        }

        $message = $this->parseNotificationTemplate($template, $record);
        $recipient = $this->getNotificationRecipient($record);

        if (!$recipient) {
            return false;
        }

        return match ($channel) {
            'whatsapp' => $this->sendWhatsApp($recipient, $message),
            'email' => $this->sendEmail($recipient, $message, $this->getName()),
            'both' => $this->sendWhatsApp($recipient, $message) && $this->sendEmail($recipient, $message, $this->getName()),
            default => false,
        };
    }

    /**
     * Get notification template for status.
     */
    protected function getNotificationTemplate(int $status): ?string
    {
        // Check module-specific templates
        if (isset($this->notificationTemplates[$status])) {
            return $this->notificationTemplates[$status];
        }

        // Default templates
        $statusInfo = method_exists($this, 'getStatus') ? $this->getStatus($status) : null;
        if ($statusInfo) {
            return "Seu pedido foi atualizado para: {$statusInfo['label']}";
        }

        return null;
    }

    /**
     * Parse template with record data.
     */
    protected function parseNotificationTemplate(string $template, $record): string
    {
        $replacements = [
            '{id}' => $record->id ?? '',
            '{status}' => $record->status ?? '',
            '{created_at}' => $record->created_at?->format('d/m/Y H:i') ?? '',
            '{customer_name}' => $record->customer?->name ?? $record->name ?? '',
        ];

        // Add any custom fields from record
        if (method_exists($record, 'toArray')) {
            foreach ($record->toArray() as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $replacements["{{$key}}"] = $value;
                }
            }
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Get recipient phone/email from record.
     */
    protected function getNotificationRecipient($record): ?string
    {
        // Try common field names
        return $record->customer?->whatsapp
            ?? $record->customer?->phone
            ?? $record->whatsapp
            ?? $record->phone
            ?? $record->email
            ?? null;
    }

    /**
     * Send WhatsApp message.
     */
    protected function sendWhatsApp(string $phone, string $message): bool
    {
        try {
            $service = app(WhatsAppService::class);
            $result = $service->sendMessage($phone, $message);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Send email notification.
     */
    protected function sendEmail(string $email, string $message, string $subject): bool
    {
        try {
            Mail::raw($message, function ($mail) use ($email, $subject) {
                $mail->to($email)->subject($subject);
            });
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Check if notifications are enabled for this module.
     */
    public function notificationsEnabled(): bool
    {
        if (method_exists($this, 'getConfig')) {
            $config = $this->getConfig();
            return $config['notify_on_status_change'] ?? false;
        }
        return false;
    }

    /**
     * Get preferred notification channel.
     */
    public function getNotificationChannel(): string
    {
        if (method_exists($this, 'getConfig')) {
            $config = $this->getConfig();
            return $config['notification_channel'] ?? 'whatsapp';
        }
        return 'whatsapp';
    }
}
