<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementType: string
{
    case RECADO = 'recado';
    case ADVERTENCIA = 'advertencia';

    public function label(): string
    {
        return match ($this) {
            self::RECADO => 'Recado',
            self::ADVERTENCIA => 'Advertência',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
