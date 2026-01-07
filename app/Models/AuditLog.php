<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model de logs de auditoria.
 * 
 * Registra todas as ações críticas do sistema com contexto completo:
 * - Quem fez (actor_id)
 * - O que fez (event/action)
 * - Em qual entidade (entity_type/entity_id)
 * - Em qual loja (store_id)
 * - De onde (ip, user_agent, request_id)
 * - Quando (created_at)
 * - Antes/depois (before_json/after_json)
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'event',
        'log_name',
        'entity_type',
        'entity_id',
        'store_id',
        'request_id',
        'ip',
        'user_agent',
        'before_json',
        'after_json',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];

    // Log names (domains)
    public const LOG_AUTH = 'auth';
    public const LOG_CASH = 'cash';
    public const LOG_RULES = 'rules';
    public const LOG_GOALS = 'goals';
    public const LOG_SALES = 'sales';
    public const LOG_ANALYTICS = 'analytics';
    public const LOG_ADMIN = 'admin';

    // Common actions
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';

    // ========================================
    // Relationships
    // ========================================

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForActor($query, int $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeForEvent($query, string $event)
    {
        // Suporta prefix match: auth.* matches auth.login, auth.logout
        if (str_ends_with($event, '*')) {
            $prefix = rtrim($event, '*');
            return $query->where('event', 'like', $prefix . '%');
        }

        return $query->where('event', $event);
    }

    public function scopeForLogName($query, string $logName)
    {
        return $query->where('log_name', $logName);
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeInPeriod($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        return $query;
    }

    // ========================================
    // Legacy Factory Methods (mantidos para compatibilidade)
    // ========================================

    /**
     * @deprecated Use AuditLogger::log() instead
     */
    public static function log(
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null
    ): self {
        return self::create([
            'actor_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'event' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }

    /**
     * @deprecated Use AuditLogger::log() instead
     */
    public static function logSubmit(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_SUBMIT, $entity, null, $after);
    }

    /**
     * @deprecated Use AuditLogger::log() instead
     */
    public static function logApprove(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_APPROVE, $entity, null, $after);
    }

    /**
     * @deprecated Use AuditLogger::log() instead
     */
    public static function logReject(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_REJECT, $entity, null, $after);
    }
}
