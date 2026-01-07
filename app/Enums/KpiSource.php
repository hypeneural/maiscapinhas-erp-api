<?php

declare(strict_types=1);

namespace App\Enums;

enum KpiSource: string
{
    case FASTAPI = 'fastapi';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::FASTAPI => 'People Analytics API',
            self::MANUAL => 'Manual',
        };
    }
}
