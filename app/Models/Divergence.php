<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Divergence extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_closing_line_id',
        'status',
        'justification_required',
    ];

    protected $casts = [
        'justification_required' => 'boolean',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESOLVED,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function cashClosingLine(): BelongsTo
    {
        return $this->belongsTo(CashClosingLine::class);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function resolve(): void
    {
        $this->update(['status' => self::STATUS_RESOLVED]);
    }
}
