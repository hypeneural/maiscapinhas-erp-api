<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status de um giro (spin) da roleta.
 */
enum SpinStatus: string
{
    case PENDING = 'pending';       // Giro solicitado, aguardando processamento
    case PROCESSING = 'processing'; // Backend processando (lock)
    case SPINNING = 'spinning';     // Animação em andamento
    case COMPLETED = 'completed';   // Giro finalizado com sucesso
    case FAILED = 'failed';         // Falhou (erro, estoque zerado, etc.)
    case CANCELLED = 'cancelled';   // Cancelado

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::SPINNING => 'Girando',
            self::COMPLETED => 'Concluído',
            self::FAILED => 'Falhou',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
