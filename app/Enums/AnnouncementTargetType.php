<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementTargetType: string
{
    case STORE = 'store';
    case USER = 'user';
    case ROLE = 'role';

    public function label(): string
    {
        return match ($this) {
            self::STORE => 'Loja',
            self::USER => 'Usuário',
            self::ROLE => 'Cargo',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
