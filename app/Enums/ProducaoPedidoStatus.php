<?php

declare(strict_types=1);

namespace App\Enums;

enum ProducaoPedidoStatus: int
{
    case CARRINHO_ABERTO = 1;
    case ENCOMENDA_REALIZADA = 2;
    case PEDIDO_ACEITO = 3;
    case PEDIDO_DESPACHADO = 4;
    case RECEBIDO = 5;
    case CANCELADO = 6;

    public function label(): string
    {
        return match ($this) {
            self::CARRINHO_ABERTO => 'Carrinho Aberto',
            self::ENCOMENDA_REALIZADA => 'Encomenda Realizada',
            self::PEDIDO_ACEITO => 'Pedido Aceito',
            self::PEDIDO_DESPACHADO => 'Pedido Despachado',
            self::RECEBIDO => 'Recebido',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CARRINHO_ABERTO => 'slate',
            self::ENCOMENDA_REALIZADA => 'orange',
            self::PEDIDO_ACEITO => 'teal',
            self::PEDIDO_DESPACHADO => 'indigo',
            self::RECEBIDO => 'green',
            self::CANCELADO => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CARRINHO_ABERTO => 'shopping-cart',
            self::ENCOMENDA_REALIZADA => 'send',
            self::PEDIDO_ACEITO => 'check-circle',
            self::PEDIDO_DESPACHADO => 'truck',
            self::RECEBIDO => 'package-check',
            self::CANCELADO => 'x-circle',
        };
    }

    /**
     * Status visíveis para a fábrica (exclui carrinho e cancelado)
     */
    public function isVisibleToFactory(): bool
    {
        return !in_array($this, [self::CARRINHO_ABERTO, self::CANCELADO]);
    }

    /**
     * Transições permitidas a partir deste status
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CARRINHO_ABERTO => [self::ENCOMENDA_REALIZADA, self::CANCELADO],
            self::ENCOMENDA_REALIZADA => [self::PEDIDO_ACEITO, self::CANCELADO],
            self::PEDIDO_ACEITO => [self::PEDIDO_DESPACHADO, self::CANCELADO],
            self::PEDIDO_DESPACHADO => [self::RECEBIDO],
            self::RECEBIDO => [],
            self::CANCELADO => [],
        };
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
