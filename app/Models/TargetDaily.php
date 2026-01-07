<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetDaily extends Model
{
    use HasFactory;

    protected $table = 'targets_daily';

    protected $fillable = [
        'store_id',
        'date',
        'target_amount',
        'seller_id',
    ];

    protected $casts = [
        'date' => 'date',
        'target_amount' => 'decimal:2',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeStoreTarget($query)
    {
        return $query->whereNull('seller_id');
    }
}
