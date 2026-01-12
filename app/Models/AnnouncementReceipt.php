<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'announcement_id',
        'user_id',
        'store_id',
        'delivered_at',
        'seen_at',
        'acknowledged_at',
        'dismissed_at',
        'last_shown_at',
        'show_count',
        'snooze_until',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'last_shown_at' => 'datetime',
            'snooze_until' => 'datetime',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isSeen(): bool
    {
        return $this->seen_at !== null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isSnoozed(?\Carbon\Carbon $now = null): bool
    {
        $now = $now ?? now();
        return $this->snooze_until !== null && $this->snooze_until > $now;
    }

    /**
     * Mark as seen if not already seen.
     */
    public function markSeen(): self
    {
        if (!$this->isSeen()) {
            $this->seen_at = now();
            $this->save();
        }

        return $this;
    }

    /**
     * Mark as acknowledged.
     */
    public function markAcknowledged(): self
    {
        $this->acknowledged_at = now();
        if (!$this->isSeen()) {
            $this->seen_at = now();
        }
        $this->save();

        return $this;
    }

    /**
     * Mark as dismissed.
     */
    public function markDismissed(): self
    {
        $this->dismissed_at = now();
        $this->save();

        return $this;
    }

    /**
     * Update show tracking.
     */
    public function touchShown(): self
    {
        $this->last_shown_at = now();
        $this->show_count++;
        $this->save();

        return $this;
    }
}
