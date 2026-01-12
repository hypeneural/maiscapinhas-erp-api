<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhoneFormFactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'marketing_name',
        'release_year',
        'form_factor',
    ];

    protected $casts = [
        'release_year' => 'integer',
        'form_factor' => PhoneFormFactor::class,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function brand(): BelongsTo
    {
        return $this->belongsTo(PhoneBrand::class, 'brand_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(CustomerDevice::class, 'phone_model_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where('marketing_name', 'like', "%{$term}%");
    }

    public function scopeByBrand($query, ?int $brandId)
    {
        if (!$brandId) {
            return $query;
        }

        return $query->where('brand_id', $brandId);
    }

    public function scopeByFormFactor($query, ?string $formFactor)
    {
        if (!$formFactor) {
            return $query;
        }

        return $query->where('form_factor', $formFactor);
    }

    public function scopeByReleaseYear($query, ?int $year)
    {
        if (!$year) {
            return $query;
        }

        return $query->where('release_year', $year);
    }

    // ========================================
    // Accessors
    // ========================================

    public function getFullNameAttribute(): string
    {
        return "{$this->brand?->brand_name} {$this->marketing_name}";
    }
}
