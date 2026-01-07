<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetMonthly extends Model
{
    use HasFactory;

    protected $table = 'targets_monthly';

    protected $fillable = [
        'store_id',
        'month',
        'target_amount',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
    ];

    // ========================================
    // Relationships
    // ========================================

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

    public function scopeForMonth($query, string $month)
    {
        return $query->where('month', $month);
    }
}
