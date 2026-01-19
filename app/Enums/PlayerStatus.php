<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status de um jogador na sessão da roleta.
 */
enum PlayerStatus: string
{
    case PENDING = 'pending';           // Entrou na fila, aguardando verificação
    case VERIFYING = 'verifying';       // Verificação WhatsApp em andamento
    case VERIFIED = 'verified';         // Verificado, aguardando vez de girar
    case SPINNING = 'spinning';         // Girando a roleta
    case WON = 'won';                   // Ganhou prêmio
    case LOST = 'lost';                 // Não ganhou (nothing/try_again)
    case LEFT = 'left';                 // Saiu da fila
    case TIMEOUT = 'timeout';           // Expirou por inatividade

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::VERIFYING => 'Verificando',
            self::VERIFIED => 'Verificado',
            self::SPINNING => 'Girando',
            self::WON => 'Ganhou',
            self::LOST => 'Não Ganhou',
            self::LEFT => 'Saiu',
            self::TIMEOUT => 'Expirado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::VERIFYING => 'blue',
            self::VERIFIED => 'green',
            self::SPINNING => 'purple',
            self::WON => 'emerald',
            self::LOST => 'gray',
            self::LEFT => 'orange',
            self::TIMEOUT => 'red',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::WON, self::LOST, self::LEFT, self::TIMEOUT]);
    }

    public function canSpin(): bool
    {
        return $this === self::VERIFIED;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
