<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionStatus: string
{
    case PROVISIONAL = 'provisional';
    case CONFIRMED = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::PROVISIONAL => 'Provisório',
            self::CONFIRMED => 'Confirmado',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::CONFIRMED;
    }
}
