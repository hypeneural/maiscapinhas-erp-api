<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CashClosing extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'cash_shift_id',
        'status',
        'closed_by',
        'closed_at',
        'version',
        'justification_text',
        'justified',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'version' => 'integer',
        'justified' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashClosingLine::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // ========================================
    // Status Helpers
    // ========================================

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function canBeSubmitted(): bool
    {
        return $this->isDraft() || $this->isRejected();
    }

    public function canBeApproved(): bool
    {
        return $this->isSubmitted();
    }

    public function canBeRejected(): bool
    {
        return $this->isSubmitted();
    }

    // ========================================
    // Validation Helpers
    // ========================================

    /**
     * Check if there are divergences without justification.
     * Now checks the shift-level justification (justified flag), not per-line.
     */
    public function hasDivergence(): bool
    {
        return $this->getTotalDifference() != 0;
    }

    /**
     * Check if divergence is unjustified (has divergence but not marked as justified).
     */
    public function hasUnjustifiedDivergence(): bool
    {
        return $this->hasDivergence() && !$this->justified;
    }

    public function getTotalDifference(): float
    {
        return (float) $this->lines()->sum('diff_value');
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'closed_by', 'closed_at', 'version', 'justified', 'justification_text'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
