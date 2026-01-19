<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de prêmios disponíveis na roleta.
 */
enum PrizeType: string
{
    case PRODUCT = 'product';
    case COUPON = 'coupon';
    case NOTHING = 'nothing';
    case TRY_AGAIN = 'try_again';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'Produto',
            self::COUPON => 'Cupom',
            self::NOTHING => 'Nada',
            self::TRY_AGAIN => 'Tente Novamente',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PRODUCT => '🎁',
            self::COUPON => '🎟️',
            self::NOTHING => '😢',
            self::TRY_AGAIN => '🔄',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PRODUCT => 'green',
            self::COUPON => 'blue',
            self::NOTHING => 'gray',
            self::TRY_AGAIN => 'yellow',
        };
    }

    /**
     * Indica se o prêmio requer resgate.
     */
    public function requiresRedeem(): bool
    {
        return in_array($this, [self::PRODUCT, self::COUPON], true);
    }

    /**
     * Indica se o prêmio consome inventário.
     */
    public function consumesInventory(): bool
    {
        return in_array($this, [self::PRODUCT, self::COUPON], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
