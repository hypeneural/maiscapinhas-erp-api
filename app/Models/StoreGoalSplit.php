<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StoreGoalSplit extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'store_monthly_goal_id',
        'user_id',
        'percent',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function goal(): BelongsTo
    {
        return $this->belongsTo(StoreMonthlyGoal::class, 'store_monthly_goal_id');
    }

    /**
     * Alias for goal() - used by RankingService
     */
    public function storeMonthlyGoal(): BelongsTo
    {
        return $this->goal();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Accessor for goal_amount - returns the calculated individual goal
     */
    public function getGoalAmountAttribute(): float
    {
        return $this->getIndividualGoalAmount();
    }

    public function getIndividualGoalAmount(): float
    {
        if (!$this->goal) {
            return 0;
        }

        return round((float) $this->goal->goal_amount * ($this->percent / 100), 2);
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_monthly_goal_id', 'user_id', 'percent'])
            ->logOnlyDirty()
            ->useLogName('goals');
    }
}
