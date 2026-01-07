<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StoreMonthlyGoal extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'store_id',
        'month',
        'goal_amount',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'goal_amount' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(StoreGoalSplit::class);
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

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // ========================================
    // Helpers
    // ========================================

    public function getTotalSplitPercent(): float
    {
        return (float) $this->splits()->sum('percent');
    }

    public function isSplitComplete(): bool
    {
        return abs($this->getTotalSplitPercent() - 100.00) < 0.01;
    }

    public function getIndividualGoal(int $userId): float
    {
        $split = $this->splits()->where('user_id', $userId)->first();

        if (!$split) {
            return 0;
        }

        return round((float) $this->goal_amount * ($split->percent / 100), 2);
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'month', 'goal_amount', 'active'])
            ->logOnlyDirty()
            ->useLogName('goals');
    }
}
