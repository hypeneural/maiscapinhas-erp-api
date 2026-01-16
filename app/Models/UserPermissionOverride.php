<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User Permission Override Model.
 * Allows granting or denying specific permissions to users,
 * overriding what they get from roles.
 * 
 * Table columns: id, user_id, permission_id, store_id, granted, expires_at, granted_by, reason
 */
class UserPermissionOverride extends Model
{
    use HasFactory;

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
        'granted' => 'boolean',
        'expires_at' => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    // ========================================
    // Accessors for compatibility
    // ========================================

    /**
     * Get type attribute for compatibility (grant/deny).
     */
    public function getTypeAttribute(): string
    {
        return $this->granted ? 'grant' : 'deny';
    }

    /**
     * Get is_active attribute for compatibility.
     */
    public function getIsActiveAttribute(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Get permission name from relationship.
     */
    public function getPermissionNameAttribute(): ?string
    {
        return $this->permission?->name;
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeGrants($query)
    {
        return $query->where('granted', true);
    }

    public function scopeDenies($query)
    {
        return $query->where('granted', false);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at < now();
    }

    public function isTemporary(): bool
    {
        return $this->expires_at !== null;
    }
}
