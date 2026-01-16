<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module model - represents a registered module in the system.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string $version
 * @property string|null $icon
 * @property bool $is_core
 * @property bool $is_active
 * @property array|null $config
 * @property array|null $status_overrides
 * @property array|null $transition_overrides
 */
class Module extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'version',
        'icon',
        'is_core',
        'is_active',
        'config',
        'status_overrides',
        'transition_overrides',
        'installed_at',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_active' => 'boolean',
        'config' => 'array',
        'status_overrides' => 'array',
        'transition_overrides' => 'array',
        'installed_at' => 'datetime',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'module_store')
            ->withPivot(['is_active', 'config', 'activated_at'])
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'module_permissions')
            ->withPivot(['is_required'])
            ->withTimestamps();
    }

    public function moduleStores(): HasMany
    {
        return $this->hasMany(ModuleStore::class, 'module_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCore($query)
    {
        return $query->where('is_core', true);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Check if module is active for a specific store.
     */
    public function isActiveForStore(int $storeId): bool
    {
        $pivot = $this->stores()->where('stores.id', $storeId)->first();
        return $pivot?->pivot?->is_active ?? false;
    }

    /**
     * Get the module instance from the registry.
     */
    public function getInstance(): ?\App\Modules\Contracts\ModuleInterface
    {
        return \App\Modules\ModuleRegistry::getInstance()->get($this->id);
    }

    /**
     * Get statuses with any Super Admin overrides applied.
     */
    public function getStatuses(): array
    {
        $instance = $this->getInstance();
        if (!$instance) {
            return [];
        }

        $statuses = $instance->getStatuses();

        // Apply any Super Admin overrides
        if ($this->status_overrides) {
            foreach ($this->status_overrides as $statusId => $overrides) {
                if (isset($statuses[$statusId])) {
                    $statuses[$statusId] = array_merge($statuses[$statusId], $overrides);
                }
            }
        }

        return $statuses;
    }

    /**
     * Get transition role matrix with any Super Admin overrides applied.
     */
    public function getTransitionRoleMatrix(): array
    {
        $instance = $this->getInstance();
        if (!$instance) {
            return [];
        }

        $matrix = $instance->getTransitionRoleMatrix();

        // Apply any Super Admin overrides
        if ($this->transition_overrides) {
            foreach ($this->transition_overrides as $from => $transitions) {
                foreach ($transitions as $to => $roles) {
                    $matrix[$from][$to] = $roles;
                }
            }
        }

        return $matrix;
    }
}
