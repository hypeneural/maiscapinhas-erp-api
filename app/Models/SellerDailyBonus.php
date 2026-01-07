<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BonusStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SellerDailyBonus extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'seller_daily_bonus';

    protected $fillable = [
        'store_id',
        'user_id',
        'date',
        'sales_total',
        'divergence_total',
        'eligible',
        'bonus_amount',
        'status',
        'rule_version',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sales_total' => 'decimal:2',
            'divergence_total' => 'decimal:2',
            'eligible' => 'boolean',
            'bonus_amount' => 'decimal:2',
            'status' => BonusStatus::class,
            'rule_version' => 'integer',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeEligible($query)
    {
        return $query->where('eligible', true);
    }

    public function scopeStatus($query, BonusStatus $status)
    {
        return $query->where('status', $status);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isConfirmed(): bool
    {
        return $this->status === BonusStatus::CONFIRMED;
    }

    public function isZeroed(): bool
    {
        return $this->status === BonusStatus::ZEROED;
    }

    public function isProvisional(): bool
    {
        return $this->status === BonusStatus::PROVISIONAL;
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sales_total', 'divergence_total', 'eligible', 'bonus_amount', 'status', 'rule_version'])
            ->logOnlyDirty()
            ->useLogName('finance');
    }
}
