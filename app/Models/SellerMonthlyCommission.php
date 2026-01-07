<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SellerMonthlyCommission extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'store_id',
        'user_id',
        'month',
        'sales_total',
        'goal_amount',
        'attainment_percent',
        'rate_percent',
        'commission_amount',
        'status',
        'rule_version',
    ];

    protected function casts(): array
    {
        return [
            'sales_total' => 'decimal:2',
            'goal_amount' => 'decimal:2',
            'attainment_percent' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'status' => CommissionStatus::class,
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

    public function scopeForMonth($query, string $month)
    {
        return $query->where('month', $month);
    }

    public function scopeStatus($query, CommissionStatus $status)
    {
        return $query->where('status', $status);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isConfirmed(): bool
    {
        return $this->status === CommissionStatus::CONFIRMED;
    }

    public function isProvisional(): bool
    {
        return $this->status === CommissionStatus::PROVISIONAL;
    }

    public function hasMetGoal(): bool
    {
        return $this->attainment_percent >= 100;
    }

    public function getAttainmentTier(): string
    {
        if ($this->attainment_percent >= 120) {
            return 'exceeding';
        }
        if ($this->attainment_percent >= 100) {
            return 'achieved';
        }
        if ($this->attainment_percent >= 80) {
            return 'approaching';
        }
        return 'below';
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sales_total', 'goal_amount', 'attainment_percent', 'rate_percent', 'commission_amount', 'status', 'rule_version'])
            ->logOnlyDirty()
            ->useLogName('finance');
    }
}
