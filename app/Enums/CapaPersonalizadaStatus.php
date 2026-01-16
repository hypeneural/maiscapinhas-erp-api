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
    case EM_PRODUCAO = 8;
    case DESPACHADO = 9;
    case RECUSADA_FABRICA = 10;

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
            self::EM_PRODUCAO => 'Em Produção',
            self::DESPACHADO => 'Despachado',
            self::RECUSADA_FABRICA => 'Recusada pela Fábrica',
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
            self::EM_PRODUCAO => 'teal',
            self::DESPACHADO => 'indigo',
            self::RECUSADA_FABRICA => 'red',
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
            self::EM_PRODUCAO => 'settings',
            self::DESPACHADO => 'truck',
            self::RECUSADA_FABRICA => 'x-octagon',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::VENDA_REALIZADA, self::CANCELADA, self::RECUSADA_FABRICA]);
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
        return in_array($this, [self::NO_CARRINHO, self::ENVIADO_PRODUCAO, self::EM_PRODUCAO, self::DESPACHADO]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

