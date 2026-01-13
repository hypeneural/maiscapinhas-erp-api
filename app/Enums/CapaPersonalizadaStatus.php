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
    case NO_CARRINHO = 7;

    public function label(): string
    {
        return match ($this) {
            self::ENCOMENDA_SOLICITADA => 'Encomenda Solicitada',
            self::PRODUTO_INDISPONIVEL => 'Produto Indisponível',
            self::DISPONIVEL_LOJA => 'Disponível na Loja',
            self::VENDA_REALIZADA => 'Venda Realizada',
            self::CANCELADA => 'Cancelada',
            self::ENVIADO_PRODUCAO => 'Encomendado à Fábrica',
            self::NO_CARRINHO => 'No Carrinho de Produção',
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
            self::ENVIADO_PRODUCAO => 'orange',
            self::NO_CARRINHO => 'slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ENCOMENDA_SOLICITADA => 'clipboard-list',
            self::PRODUTO_INDISPONIVEL => 'alert-circle',
            self::DISPONIVEL_LOJA => 'store',
            self::VENDA_REALIZADA => 'check-circle',
            self::CANCELADA => 'x-circle',
            self::ENVIADO_PRODUCAO => 'send',
            self::NO_CARRINHO => 'shopping-cart',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::VENDA_REALIZADA, self::CANCELADA]);
    }

    /**
     * Verifica se a capa pode ser adicionada ao carrinho
     */
    public function canAddToCart(): bool
    {
        return $this === self::ENCOMENDA_SOLICITADA;
    }

    /**
     * Verifica se a capa está em fluxo de produção
     */
    public function isInProductionFlow(): bool
    {
        return in_array($this, [self::NO_CARRINHO, self::ENVIADO_PRODUCAO]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
