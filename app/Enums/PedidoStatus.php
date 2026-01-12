<?php

declare(strict_types=1);

namespace App\Enums;

enum PedidoStatus: int
{
    case SOLICITADO = 1;
    case PRODUTO_INDISPONIVEL = 2;
    case DISPONIVEL_LOJA = 3;
    case VENDA_REALIZADA = 4;
    case CANCELADO = 5;

    public function label(): string
    {
        return match ($this) {
            self::SOLICITADO => 'Solicitado',
            self::PRODUTO_INDISPONIVEL => 'Produto Indisponível',
            self::DISPONIVEL_LOJA => 'Disponível na Loja',
            self::VENDA_REALIZADA => 'Venda Realizada',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SOLICITADO => 'blue',
            self::PRODUTO_INDISPONIVEL => 'red',
            self::DISPONIVEL_LOJA => 'yellow',
            self::VENDA_REALIZADA => 'green',
            self::CANCELADO => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::VENDA_REALIZADA, self::CANCELADO]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
