<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status de uma sessão da roleta (QR Code ativo).
 */
enum SessionStatus: string
{
    case WAITING = 'waiting';       // Aguardando jogador escanear QR
    case ACTIVE = 'active';         // Jogador conectado, sessão em andamento
    case SPINNING = 'spinning';     // Giro em andamento
    case COMPLETED = 'completed';   // Sessão finalizada com sucesso
    case EXPIRED = 'expired';       // QR expirou sem uso
    case CANCELLED = 'cancelled';   // Cancelada manualmente

    public function label(): string
    {
        return match ($this) {
            self::WAITING => 'Aguardando',
            self::ACTIVE => 'Ativa',
            self::SPINNING => 'Girando',
            self::COMPLETED => 'Concluída',
            self::EXPIRED => 'Expirada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WAITING => 'blue',
            self::ACTIVE => 'green',
            self::SPINNING => 'purple',
            self::COMPLETED => 'gray',
            self::EXPIRED => 'orange',
            self::CANCELLED => 'red',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::EXPIRED, self::CANCELLED]);
    }

    public function canJoin(): bool
    {
        return $this === self::WAITING;
    }

    public function canSpin(): bool
    {
        return $this === self::ACTIVE;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
