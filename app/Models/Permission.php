<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    // Use existing Spatie table
    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'type',
        'module',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // ========================================
    // Constants
    // ========================================

    public const TYPE_ABILITY = 'ability';
    public const TYPE_SCREEN = 'screen';
    public const TYPE_FEATURE = 'feature';

    public const TYPES = [
        self::TYPE_ABILITY,
        self::TYPE_SCREEN,
        self::TYPE_FEATURE,
    ];

    // ========================================
    // Scopes
    // ========================================

    public function scopeAbilities($query)
    {
        return $query->where('type', self::TYPE_ABILITY);
    }

    public function scopeScreens($query)
    {
        return $query->where('type', self::TYPE_SCREEN);
    }

    public function scopeFeatures($query)
    {
        return $query->where('type', self::TYPE_FEATURE);
    }

    public function scopeOfModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('module')->orderBy('sort_order')->orderBy('name');
    }

    // ========================================
    // Helpers
    // ========================================

    public function isAbility(): bool
    {
        return $this->type === self::TYPE_ABILITY;
    }

    public function isScreen(): bool
    {
        return $this->type === self::TYPE_SCREEN;
    }

    public function isFeature(): bool
    {
        return $this->type === self::TYPE_FEATURE;
    }
}
