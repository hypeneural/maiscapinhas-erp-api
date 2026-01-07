<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CommissionRule extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'store_id',
        'effective_from',
        'config_json',
        'version',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'config_json' => 'array',
        'version' => 'integer',
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

    public function scopeForStore($query, ?int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('store_id');
    }

    public function scopeEffectiveOn($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('version');
    }

    // ========================================
    // Helpers
    // ========================================

    public function isGlobal(): bool
    {
        return $this->store_id === null;
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'effective_from', 'config_json', 'version'])
            ->logOnlyDirty();
    }
}
