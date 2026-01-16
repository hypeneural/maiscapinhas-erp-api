<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    // Use existing Spatie table
    protected $table = 'roles';

    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'description',
        'level',
        'is_system',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_system' => 'boolean',
    ];

    // ========================================
    // Constants - Role Names
    // ========================================

    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const FABRICA = 'fabrica';
    public const GERENTE = 'gerente';
    public const CONFERENTE = 'conferente';
    public const ESTOQUISTA = 'estoquista';
    public const VENDEDOR = 'vendedor';

    // ========================================
    // Constants - Role Levels
    // ========================================

    public const LEVEL_SUPER_ADMIN = 100;
    public const LEVEL_ADMIN = 90;
    public const LEVEL_FABRICA = 80;
    public const LEVEL_GERENTE = 70;
    public const LEVEL_CONFERENTE = 60;
    public const LEVEL_ESTOQUISTA = 50;
    public const LEVEL_VENDEDOR = 40;

    // ========================================
    // Scopes
    // ========================================

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeByLevel($query, string $direction = 'desc')
    {
        return $query->orderBy('level', $direction);
    }

    // ========================================
    // Helpers
    // ========================================

    public function getPermissionNames(): Collection
    {
        return $this->permissions->pluck('name');
    }

    public function isHigherThan(Role $other): bool
    {
        return $this->level > $other->level;
    }

    public function isAtLeast(Role $other): bool
    {
        return $this->level >= $other->level;
    }

    public static function findByName(string $name, ?string $guardName = null): \Spatie\Permission\Contracts\Role
    {
        $guardName = $guardName ?? config('auth.defaults.guard');
        return static::where('name', $name)->where('guard_name', $guardName)->firstOrFail();
    }
}
