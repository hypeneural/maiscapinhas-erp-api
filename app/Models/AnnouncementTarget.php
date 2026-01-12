<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementTarget extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'announcement_id',
        'target_type',
        'target_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => AnnouncementTargetType::class,
            'created_at' => 'datetime',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isStoreTarget(): bool
    {
        return $this->target_type === AnnouncementTargetType::STORE;
    }

    public function isUserTarget(): bool
    {
        return $this->target_type === AnnouncementTargetType::USER;
    }

    public function isRoleTarget(): bool
    {
        return $this->target_type === AnnouncementTargetType::ROLE;
    }
}
