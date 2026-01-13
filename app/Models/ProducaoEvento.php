<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProducaoEvento extends Model
{
    public $timestamps = false;

    protected $table = 'producao_eventos';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'from_status',
        'to_status',
        'metadata',
        'actor_id',
        'actor_type',
        'actor_name',
        'created_at',
    ];

    protected $casts = [
        'from_status' => 'integer',
        'to_status' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ========================================
    // Constants - Action Types
    // ========================================

    public const ACTION_CAPA_CRIADA = 'capa_criada';
    public const ACTION_FOTO_ENVIADA = 'foto_enviada';
    public const ACTION_PAGAMENTO_REGISTRADO = 'pagamento_registrado';
    public const ACTION_CARRINHO_CRIADO = 'carrinho_criado';
    public const ACTION_ITEM_ADICIONADO = 'item_adicionado';
    public const ACTION_ITEM_REMOVIDO = 'item_removido';
    public const ACTION_CARRINHO_FECHADO = 'carrinho_fechado';
    public const ACTION_PEDIDO_ACEITO = 'pedido_aceito';
    public const ACTION_PEDIDO_DESPACHADO = 'pedido_despachado';
    public const ACTION_PEDIDO_RECEBIDO = 'pedido_recebido';
    public const ACTION_PEDIDO_CANCELADO = 'pedido_cancelado';
    public const ACTION_ITENS_DISTRIBUIDOS = 'itens_distribuidos';

    // ========================================
    // Constants - Entity Types
    // ========================================

    public const ENTITY_PRODUCAO_PEDIDO = 'producao_pedido';
    public const ENTITY_CAPA_PERSONALIZADA = 'capa_personalizada';

    // ========================================
    // Constants - Actor Types
    // ========================================

    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_VENDEDOR = 'vendedor';
    public const ACTOR_FABRICA = 'fabrica';
    public const ACTOR_SISTEMA = 'sistema';

    // ========================================
    // Accessors
    // ========================================

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CAPA_CRIADA => 'Capa Criada',
            self::ACTION_FOTO_ENVIADA => 'Foto Enviada',
            self::ACTION_PAGAMENTO_REGISTRADO => 'Pagamento Registrado',
            self::ACTION_CARRINHO_CRIADO => 'Carrinho Criado',
            self::ACTION_ITEM_ADICIONADO => 'Item Adicionado',
            self::ACTION_ITEM_REMOVIDO => 'Item Removido',
            self::ACTION_CARRINHO_FECHADO => 'Carrinho Fechado',
            self::ACTION_PEDIDO_ACEITO => 'Pedido Aceito pela Fábrica',
            self::ACTION_PEDIDO_DESPACHADO => 'Pedido Despachado',
            self::ACTION_PEDIDO_RECEBIDO => 'Pedido Recebido',
            self::ACTION_PEDIDO_CANCELADO => 'Pedido Cancelado',
            self::ACTION_ITENS_DISTRIBUIDOS => 'Itens Distribuídos',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    public function getActionIconAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CAPA_CRIADA => 'plus-circle',
            self::ACTION_FOTO_ENVIADA => 'image',
            self::ACTION_PAGAMENTO_REGISTRADO => 'credit-card',
            self::ACTION_CARRINHO_CRIADO => 'shopping-cart',
            self::ACTION_ITEM_ADICIONADO => 'plus',
            self::ACTION_ITEM_REMOVIDO => 'minus',
            self::ACTION_CARRINHO_FECHADO => 'check',
            self::ACTION_PEDIDO_ACEITO => 'check-circle',
            self::ACTION_PEDIDO_DESPACHADO => 'truck',
            self::ACTION_PEDIDO_RECEBIDO => 'package-check',
            self::ACTION_PEDIDO_CANCELADO => 'x-circle',
            self::ACTION_ITENS_DISTRIBUIDOS => 'share',
            default => 'info',
        };
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc');
    }

    public function scopeForProducaoPedido($query, int $pedidoId)
    {
        return $query->forEntity(self::ENTITY_PRODUCAO_PEDIDO, $pedidoId);
    }

    public function scopeForCapaPersonalizada($query, int $capaId)
    {
        return $query->forEntity(self::ENTITY_CAPA_PERSONALIZADA, $capaId);
    }
}
