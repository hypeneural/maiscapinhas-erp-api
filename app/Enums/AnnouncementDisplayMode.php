<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementDisplayMode: string
{
    case BANNER = 'banner';
    case MODAL = 'modal';
    case BOTH = 'both';

    public function label(): string
    {
        return match ($this) {
            self::BANNER => 'Banner',
            self::MODAL => 'Modal',
            self::BOTH => 'Banner e Modal',
        };
    }

    public function showsAsBanner(): bool
    {
        return in_array($this, [self::BANNER, self::BOTH]);
    }

    public function showsAsModal(): bool
    {
        return in_array($this, [self::MODAL, self::BOTH]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
