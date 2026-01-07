<?php

declare(strict_types=1);

namespace App\Enums;

enum BonusStatus: string
{
    case PROVISIONAL = 'provisional';
    case CONFIRMED = 'confirmed';
    case ZEROED = 'zeroed';

    public function label(): string
    {
        return match ($this) {
            self::PROVISIONAL => 'Provisório',
            self::CONFIRMED => 'Confirmado',
            self::ZEROED => 'Zerado',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::CONFIRMED;
    }
}
