<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'active',
        'is_super_admin',
        // Address fields
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        // Profile fields
        'birth_date',
        'hire_date',
        'whatsapp',
        'avatar_url',
        'instagram',
        'cpf',
        'pix_key',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'is_super_admin' => 'boolean',
            'birth_date' => 'date',
            'hire_date' => 'date',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'seller_id');
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class, 'seller_id');
    }

    public function announcementReceipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // ========================================
    // Super Admin
    // ========================================

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    // ========================================
    // Store Access Helpers
    // ========================================

    public function hasAccessToStore(int $storeId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->storeUsers()->where('store_id', $storeId)->exists();
    }

    public function roleInStore(int $storeId): ?string
    {
        $storeUser = $this->storeUsers()->where('store_id', $storeId)->first();
        return $storeUser?->role;
    }

    public function storeUserFor(int $storeId): ?StoreUser
    {
        return $this->storeUsers()->where('store_id', $storeId)->first();
    }

    public function canApproveInStore(int $storeId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        $storeUser = $this->storeUserFor($storeId);
        return $storeUser?->canApproveClosings() ?? false;
    }

    public function isAdminInStore(int $storeId): bool
    {
        return $this->roleInStore($storeId) === StoreUser::ROLE_ADMIN;
    }

    public function isGlobalAdmin(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->storeUsers()
            ->where('role', StoreUser::ROLE_ADMIN)
            ->exists();
    }

    // ========================================
    // Formatted Data for API
    // ========================================

    public function getStoresWithRoles(): array
    {
        return $this->storeUsers()
            ->with('store')
            ->get()
            ->map(fn(StoreUser $su) => [
                'id' => $su->store->id,
                'name' => $su->store->name,
                'city' => $su->store->city,
                'role' => $su->role,
            ])
            ->toArray();
    }
}
