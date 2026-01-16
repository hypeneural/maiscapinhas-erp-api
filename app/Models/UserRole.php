<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStoreRole extends Model
{
    use HasFactory;

    protected $table = 'user_store_roles';

    protected $fillable = [
        'user_id',
        'role_id',
        'store_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'role_id' => 'integer',
        'store_id' => 'integer',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
}
