<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PhoneBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_name',
        'brand_slug',
        'parent_company',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function models(): HasMany
    {
        return $this->hasMany(PhoneModel::class, 'brand_id');
    }

    // ========================================
    // Boot
    // ========================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($brand) {
            if (empty($brand->brand_slug)) {
                $brand->brand_slug = Str::slug($brand->brand_name);
            }
        });

        static::updating(function ($brand) {
            if ($brand->isDirty('brand_name') && !$brand->isDirty('brand_slug')) {
                $brand->brand_slug = Str::slug($brand->brand_name);
            }
        });
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('brand_name', 'like', "%{$term}%")
                ->orWhere('brand_slug', 'like', "%{$term}%")
                ->orWhere('parent_company', 'like', "%{$term}%");
        });
    }
}
