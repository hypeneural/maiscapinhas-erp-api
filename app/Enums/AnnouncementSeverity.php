<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case DANGER = 'danger';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Informativo',
            self::WARNING => 'Atenção',
            self::DANGER => 'Urgente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFO => 'blue',
            self::WARNING => 'yellow',
            self::DANGER => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
