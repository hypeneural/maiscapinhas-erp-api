<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementScope: string
{
    case GLOBAL = 'global';
    case STORE = 'store';
    case USER = 'user';
    case ROLE = 'role';

    public function label(): string
    {
        return match ($this) {
            self::GLOBAL => 'Global',
            self::STORE => 'Loja',
            self::USER => 'Usuário',
            self::ROLE => 'Cargo',
        };
    }

    public function requiresTargets(): bool
    {
        return $this !== self::GLOBAL;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
