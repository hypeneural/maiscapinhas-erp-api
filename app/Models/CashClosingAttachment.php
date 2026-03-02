<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CashClosingAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_closing_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected $appends = ['url'];

    // ========================================
    // Relationships
    // ========================================

    public function cashClosing(): BelongsTo
    {
        return $this->belongsTo(CashClosing::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ========================================
    // Accessors
    // ========================================

    public function getUrlAttribute(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        return $baseUrl . '/storage/' . ltrim($this->file_path, '/');
    }

    // ========================================
    // Helpers
    // ========================================

    public function isImage(): bool
    {
        return str_starts_with($this->file_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->file_type === 'application/pdf';
    }
}
