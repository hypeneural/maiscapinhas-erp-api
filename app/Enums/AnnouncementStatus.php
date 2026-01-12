<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementStatus: string
{
    case DRAFT = 'draft';
    case SCHEDULED = 'scheduled';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SCHEDULED => 'Agendado',
            self::ACTIVE => 'Ativo',
            self::EXPIRED => 'Expirado',
            self::ARCHIVED => 'Arquivado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SCHEDULED => 'blue',
            self::ACTIVE => 'green',
            self::EXPIRED => 'orange',
            self::ARCHIVED => 'gray',
        };
    }

    public function isVisible(): bool
    {
        return in_array($this, [self::SCHEDULED, self::ACTIVE]);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::SCHEDULED]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
