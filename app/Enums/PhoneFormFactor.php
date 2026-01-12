<?php

declare(strict_types=1);

namespace App\Enums;

enum PhoneFormFactor: string
{
    case SMARTPHONE = 'smartphone';
    case TABLET = 'tablet';
    case WATCH = 'watch';
    case FEATURE_PHONE = 'feature_phone';

    public function label(): string
    {
        return match ($this) {
            self::SMARTPHONE => 'Smartphone',
            self::TABLET => 'Tablet',
            self::WATCH => 'Smartwatch',
            self::FEATURE_PHONE => 'Celular Básico',
        };
    }
}
