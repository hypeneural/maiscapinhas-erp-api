<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'role',
    ];

    // Valid roles per store
    public const ROLE_ADMIN = 'admin';
    public const ROLE_GERENTE = 'gerente';
    public const ROLE_CONFERENTE = 'conferente';
    public const ROLE_VENDEDOR = 'vendedor';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_GERENTE,
        self::ROLE_CONFERENTE,
        self::ROLE_VENDEDOR,
    ];

    // Roles that can approve/reject closings
    public const APPROVAL_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_GERENTE,
        self::ROLE_CONFERENTE,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // Helpers
    // ========================================

    public function canApproveClosings(): bool
    {
        return in_array($this->role, self::APPROVAL_ROLES);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isGerente(): bool
    {
        return $this->role === self::ROLE_GERENTE;
    }

    public function isConferente(): bool
    {
        return $this->role === self::ROLE_CONFERENTE;
    }

    public function isVendedor(): bool
    {
        return $this->role === self::ROLE_VENDEDOR;
    }
}
