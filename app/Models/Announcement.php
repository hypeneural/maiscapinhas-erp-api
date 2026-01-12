<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Announcement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'message',
        'excerpt',
        'type',
        'severity',
        'display_mode',
        'icon',
        'image_url',
        'image_alt',
        'cta_label',
        'cta_url',
        'scope',
        'require_ack',
        'status',
        'starts_at',
        'expires_at',
        'repeat_every_minutes',
        'priority',
        'pinned_until',
        'meta_json',
        'created_by_user_id',
        'published_by_user_id',
        'published_at',
        'archived_by_user_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AnnouncementType::class,
            'severity' => AnnouncementSeverity::class,
            'display_mode' => AnnouncementDisplayMode::class,
            'scope' => AnnouncementScope::class,
            'status' => AnnouncementStatus::class,
            'require_ack' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'pinned_until' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function targets(): HasMany
    {
        return $this->hasMany(AnnouncementTarget::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    // ========================================
    // Scopes
    // ========================================

    /**
     * Scope for announcements that are currently active based on schedule.
     */
    public function scopeActiveNow($query, ?Carbon $now = null)
    {
        $now = $now ?? now();

        return $query
            ->whereIn('status', [AnnouncementStatus::ACTIVE->value, AnnouncementStatus::SCHEDULED->value])
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * Scope for announcements visible to users (not draft/archived).
     */
    public function scopeVisible($query)
    {
        return $query->whereNotIn('status', [
            AnnouncementStatus::DRAFT->value,
            AnnouncementStatus::ARCHIVED->value,
        ]);
    }

    /**
     * Order by priority and pinned status.
     */
    public function scopeOrdered($query, ?Carbon $now = null)
    {
        $now = $now ?? now();

        return $query
            ->orderByRaw('CASE WHEN pinned_until IS NOT NULL AND pinned_until > ? THEN 0 ELSE 1 END', [$now])
            ->orderByDesc('priority')
            ->orderByDesc('starts_at');
    }

    /**
     * Scope for critical announcements (danger + require_ack).
     */
    public function scopeCritical($query)
    {
        return $query
            ->where('severity', AnnouncementSeverity::DANGER->value)
            ->where('require_ack', true);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isCritical(): bool
    {
        return $this->severity === AnnouncementSeverity::DANGER && $this->require_ack;
    }

    public function isGlobal(): bool
    {
        return $this->scope === AnnouncementScope::GLOBAL;
    }

    public function isScheduled(): bool
    {
        return $this->status === AnnouncementStatus::SCHEDULED;
    }

    public function isDraft(): bool
    {
        return $this->status === AnnouncementStatus::DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === AnnouncementStatus::ACTIVE;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        $now = $now ?? now();
        return $this->expires_at !== null && $this->expires_at <= $now;
    }

    public function isWithinSchedule(?Carbon $now = null): bool
    {
        $now = $now ?? now();

        $startsOk = $this->starts_at === null || $this->starts_at <= $now;
        $expiresOk = $this->expires_at === null || $this->expires_at > $now;

        return $startsOk && $expiresOk;
    }

    public function isPinned(?Carbon $now = null): bool
    {
        $now = $now ?? now();
        return $this->pinned_until !== null && $this->pinned_until > $now;
    }

    /**
     * Get the user's receipt for this announcement.
     */
    public function receiptForUser(int $userId): ?AnnouncementReceipt
    {
        return $this->receipts()->where('user_id', $userId)->first();
    }

    /**
     * Generate excerpt from message if not set.
     */
    public function getExcerptAttribute($value): string
    {
        if ($value) {
            return $value;
        }

        return \Illuminate\Support\Str::limit(strip_tags($this->message), 197);
    }
}
