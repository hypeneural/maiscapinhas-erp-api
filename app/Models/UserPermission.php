<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    use HasFactory;

    protected $table = 'user_permission_overrides';

    protected $fillable = [
        'user_id',
        'permission_id',
        'store_id',
        'granted',
        'expires_at',
        'granted_by',
        'reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'permission_id' => 'integer',
        'store_id' => 'integer',
        'granted' => 'boolean',
        'expires_at' => 'datetime',
        'granted_by' => 'integer',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeGlobal($query)
    {
        return $query->whereNull('store_id');
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeGranted($query)
    {
        return $query->where('granted', true);
    }

    public function scopeDenied($query)
    {
        return $query->where('granted', false);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    // ========================================
    // Helpers
    // ========================================

    public function isGlobal(): bool
    {
        return $this->store_id === null;
    }

    public function isStoreSpecific(): bool
    {
        return $this->store_id !== null;
    }

    public function isGranted(): bool
    {
        return $this->granted === true;
    }

    public function isDenied(): bool
    {
        return $this->granted === false;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    public function isTemporary(): bool
    {
        return $this->expires_at !== null;
    }
}
