<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePermissionOverride extends Model
{
    use HasFactory;

    protected $table = 'store_permission_overrides';

    protected $fillable = [
        'store_id',
        'permission_id',
        'granted',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'permission_id' => 'integer',
        'granted' => 'boolean',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeGranted($query)
    {
        return $query->where('granted', true);
    }

    public function scopeDenied($query)
    {
        return $query->where('granted', false);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isGranted(): bool
    {
        return $this->granted === true;
    }

    public function isDenied(): bool
    {
        return $this->granted === false;
    }
}
