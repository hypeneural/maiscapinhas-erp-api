<?php

declare(strict_types=1);

namespace App\Enums;

enum StoreUserRole: string
{
    case ADMIN = 'admin';
    case GERENTE = 'gerente';
    case CONFERENTE = 'conferente';
    case VENDEDOR = 'vendedor';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrador',
            self::GERENTE => 'Gerente',
            self::CONFERENTE => 'Conferente',
            self::VENDEDOR => 'Vendedor',
        };
    }

    public function canApproveClosings(): bool
    {
        return in_array($this, [self::ADMIN, self::GERENTE, self::CONFERENTE]);
    }

    public function canManageRules(): bool
    {
        return in_array($this, [self::ADMIN, self::GERENTE]);
    }

    public function canManageGoals(): bool
    {
        return in_array($this, [self::ADMIN, self::GERENTE]);
    }

    public function isManager(): bool
    {
        return in_array($this, [self::ADMIN, self::GERENTE]);
    }
}
