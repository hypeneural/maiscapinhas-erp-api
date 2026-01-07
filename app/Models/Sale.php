<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'seller_id',
        'sold_at',
        'amount',
        'source',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public const SOURCE_PDV = 'pdv';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_PDV,
        self::SOURCE_MANUAL,
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

    public function scopeForSeller($query, int $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereBetween('sold_at', [$from, $to]);
    }

    public function scopeOnDate($query, $date)
    {
        return $query->whereDate('sold_at', $date);
    }
}
