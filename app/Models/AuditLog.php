<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];

    // Common actions
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';

    // ========================================
    // Relationships
    // ========================================

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ========================================
    // Factory Methods
    // ========================================

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
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }

    public static function logSubmit(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_SUBMIT, $entity, null, $after);
    }

    public static function logApprove(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_APPROVE, $entity, null, $after);
    }

    public static function logReject(Model $entity, ?array $after = null): self
    {
        return self::log(self::ACTION_REJECT, $entity, null, $after);
    }
}
