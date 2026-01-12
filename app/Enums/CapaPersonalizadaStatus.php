<?php

declare(strict_types=1);

namespace App\Enums;

enum CapaPersonalizadaStatus: int
{
    case ENCOMENDA_SOLICITADA = 1;
    case PRODUTO_INDISPONIVEL = 2;
    case DISPONIVEL_LOJA = 3;
    case VENDA_REALIZADA = 4;
    case CANCELADA = 5;
    case ENVIADO_PRODUCAO = 6;

    public function label(): string
    {
        return match ($this) {
            self::ENCOMENDA_SOLICITADA => 'Encomenda Solicitada',
            self::PRODUTO_INDISPONIVEL => 'Produto Indisponível',
            self::DISPONIVEL_LOJA => 'Disponível na Loja',
            self::VENDA_REALIZADA => 'Venda Realizada',
            self::CANCELADA => 'Cancelada',
            self::ENVIADO_PRODUCAO => 'Enviado para Produção',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ENCOMENDA_SOLICITADA => 'blue',
            self::PRODUTO_INDISPONIVEL => 'red',
            self::DISPONIVEL_LOJA => 'yellow',
            self::VENDA_REALIZADA => 'green',
            self::CANCELADA => 'gray',
            self::ENVIADO_PRODUCAO => 'purple',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::VENDA_REALIZADA, self::CANCELADA]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
