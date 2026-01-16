<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ModuleStore model - pivot table with extra attributes.
 *
 * @property int $id
 * @property string $module_id
 * @property int $store_id
 * @property bool $is_active
 * @property array|null $config
 */
class ModuleStore extends Model
{
    protected $table = 'module_store';

    protected $fillable = [
        'module_id',
        'store_id',
        'is_active',
        'config',
        'activated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
        'activated_at' => 'datetime',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForModule($query, string $moduleId)
    {
        return $query->where('module_id', $moduleId);
    }
}
