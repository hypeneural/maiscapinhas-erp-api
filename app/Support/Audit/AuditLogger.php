<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Logger central de auditoria.
 * 
 * Centraliza todos os registros de auditoria para:
 * - Incluir automaticamente contexto da requisição
 * - Sanitizar dados sensíveis
 * - Padronizar formato dos eventos
 * - Resolver store_id quando não informado
 */
class AuditLogger
{
    /**
     * Campos que NUNCA devem ser logados.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'credit_card',
        'cvv',
        'authorization',
    ];

    public function __construct(
        private AuditContext $context
    ) {
    }

    /**
     * Registra um evento de auditoria.
     *
     * @param string $event Nome do evento (ex: auth.login, cash_closing.submit)
     * @param Model|null $subject Entidade afetada
     * @param array $properties Dados adicionais
     * @param User|null $causer Usuário que causou a ação (default: auth()->user())
     */
    public function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?User $causer = null
    ): void {
        $causer = $causer ?? Auth::user();

        // Resolver store_id do subject se não informado
        $storeId = $properties['store_id'] ?? $this->resolveStoreId($subject);

        // Montar properties completo
        $fullProperties = array_merge(
            $this->sanitize($properties),
            [
                'request_id' => $this->context->getRequestId(),
                'ip' => $this->context->getIp(),
                'user_agent' => $this->context->getUserAgent(),
                'route' => $this->context->getRoute(),
            ]
        );

        // Extrair log_name do event (primeira parte)
        $logName = explode('.', $event)[0] ?? 'default';

        // Criar registro no AuditLog
        AuditLog::create([
            'actor_id' => $causer?->id,
            'action' => $this->extractAction($event),
            'event' => $event,
            'log_name' => $logName,
            'entity_type' => $subject ? class_basename($subject) : null,
            'entity_id' => $subject?->getKey(),
            'store_id' => $storeId,
            'request_id' => $this->context->getRequestId(),
            'ip' => $this->context->getIp(),
            'user_agent' => $this->context->getUserAgent(),
            'before_json' => $fullProperties['before'] ?? null,
            'after_json' => $fullProperties['after'] ?? $this->sanitize(
                array_diff_key($fullProperties, ['before' => 1, 'after' => 1])
            ),
            'created_at' => now(),
        ]);
    }

    /**
     * Log de autenticação.
     */
    public function logAuth(string $action, ?User $user = null, array $properties = []): void
    {
        $this->log(
            "auth.{$action}",
            $user,
            $properties,
            $user
        );
    }

    /**
     * Log de ação em entidade (CRUD).
     */
    public function logEntity(
        string $domain,
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        array $extra = []
    ): void {
        $properties = array_merge($extra, [
            'before' => $before ? $this->sanitize($before) : null,
            'after' => $after ? $this->sanitize($after) : null,
        ]);

        $this->log("{$domain}.{$action}", $entity, $properties);
    }

    /**
     * Resolve store_id a partir do subject.
     */
    private function resolveStoreId(?Model $subject): ?int
    {
        if (!$subject) {
            return $this->context->getStoreId();
        }

        // Tentar resolver de várias formas
        if (property_exists($subject, 'store_id') && $subject->store_id) {
            return (int) $subject->store_id;
        }

        // CashClosing -> cashShift -> store_id
        if (method_exists($subject, 'cashShift') && $subject->cashShift) {
            return (int) $subject->cashShift->store_id;
        }

        // Fallback para contexto
        return $this->context->getStoreId();
    }

    /**
     * Extrai action do event (última parte).
     */
    private function extractAction(string $event): string
    {
        $parts = explode('.', $event);
        return end($parts) ?: $event;
    }

    /**
     * Remove campos sensíveis de um array.
     */
    private function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Verificar se é campo sensível
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Recursão para arrays aninhados
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
                continue;
            }

            // Truncar strings muito longas
            if (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000) . '...[truncated]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Verifica se uma chave é sensível.
     */
    private function isSensitiveKey(string $key): bool
    {
        $lowercaseKey = strtolower($key);

        foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
            if (str_contains($lowercaseKey, $sensitiveField)) {
                return true;
            }
        }

        return false;
    }
}
