<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status possíveis para uma Campaign (Campanha).
 */
enum CampaignStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ENDED = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::ACTIVE => 'Ativa',
            self::PAUSED => 'Pausada',
            self::ENDED => 'Encerrada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'green',
            self::PAUSED => 'yellow',
            self::ENDED => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'FileEdit',
            self::ACTIVE => 'Play',
            self::PAUSED => 'Pause',
            self::ENDED => 'Square',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Status que permitem edição completa.
     */
    public static function editable(): array
    {
        return [self::DRAFT->value, self::PAUSED->value];
    }

    /**
     * Verifica se a campanha pode ser ativada.
     */
    public function canActivate(): bool
    {
        return in_array($this, [self::DRAFT, self::PAUSED], true);
    }

    /**
     * Verifica se a campanha pode ser pausada.
     */
    public function canPause(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Verifica se a campanha pode ser encerrada.
     */
    public function canEnd(): bool
    {
        return in_array($this, [self::ACTIVE, self::PAUSED], true);
    }
}
