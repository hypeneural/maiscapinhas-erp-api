<?php

declare(strict_types=1);

namespace App\Modules\Fabrica;

use App\Modules\BaseModule;

/**
 * Module: Fábrica (Produção)
 *
 * Gestão de pedidos de produção de capas personalizadas.
 * Este módulo gerencia o fluxo de produção entre Admin e Fábrica.
 *
 * **Sincronização de Status:**
 * - Ao aceitar pedido → Capas mudam para "Em Produção"
 * - Ao despachar → Capas mudam para "Despachado"
 * - Ao receber → Capas mudam para "Disponível na Loja"
 * - Ao recusar item → Capa específica muda para "Recusada pela Fábrica"
 */
class FabricaModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'fabrica';
    }

    public function getName(): string
    {
        return 'Fábrica';
    }

    public function getDescription(): string
    {
        return 'Portal da fábrica para gestão de pedidos de produção de capas personalizadas.';
    }

    public function getIcon(): string
    {
        return 'Factory';
    }

    public function getDependencies(): array
    {
        return ['capas-personalizadas'];
    }

    // ========================================
    // Status
    // ========================================

    public function getStatuses(): array
    {
        return [
            1 => [
                'name' => 'carrinho_aberto',
                'label' => 'Carrinho Aberto',
                'description' => 'Itens estão sendo adicionados ao carrinho de produção.',
                'color' => 'slate',
                'icon' => 'ShoppingCart',
                'badge_variant' => 'secondary',
                'can_edit' => true,
                'final' => false,
                'tooltip' => 'Admin está selecionando capas para enviar à fábrica',
            ],
            2 => [
                'name' => 'encomenda_realizada',
                'label' => 'Encomenda Realizada',
                'description' => 'Pedido enviado à fábrica, aguardando aceitação.',
                'color' => 'orange',
                'icon' => 'Send',
                'badge_variant' => 'warning',
                'can_edit' => false,
                'final' => false,
                'tooltip' => 'Aguardando fábrica aceitar e informar valor',
            ],
            3 => [
                'name' => 'pedido_aceito',
                'label' => 'Pedido Aceito',
                'description' => 'Fábrica aceitou o pedido e iniciou produção.',
                'color' => 'teal',
                'icon' => 'CheckCircle',
                'badge_variant' => 'default',
                'can_edit' => false,
                'final' => false,
                'tooltip' => 'Capas estão em produção na fábrica',
            ],
            4 => [
                'name' => 'pedido_despachado',
                'label' => 'Pedido Despachado',
                'description' => 'Fábrica despachou o pedido.',
                'color' => 'indigo',
                'icon' => 'Truck',
                'badge_variant' => 'default',
                'can_edit' => false,
                'final' => false,
                'tooltip' => 'Pedido a caminho da loja',
            ],
            5 => [
                'name' => 'recebido',
                'label' => 'Recebido',
                'description' => 'Admin recebeu o pedido e distribuiu às lojas.',
                'color' => 'green',
                'icon' => 'PackageCheck',
                'badge_variant' => 'success',
                'can_edit' => false,
                'final' => true,
                'tooltip' => 'Pedido concluído com sucesso',
            ],
            6 => [
                'name' => 'cancelado',
                'label' => 'Cancelado',
                'description' => 'Pedido cancelado.',
                'color' => 'red',
                'icon' => 'XCircle',
                'badge_variant' => 'destructive',
                'can_edit' => false,
                'final' => true,
                'tooltip' => 'Pedido foi cancelado',
            ],
        ];
    }

    // ========================================
    // Transitions
    // ========================================

    public function getTransitions(): array
    {
        return [
            1 => [2, 6],        // Carrinho → Encomenda Realizada, Cancelado
            2 => [3, 6],        // Encomenda Realizada → Pedido Aceito, Cancelado
            3 => [4, 6],        // Pedido Aceito → Despachado, Cancelado
            4 => [5],           // Despachado → Recebido
            5 => [],            // Recebido (final)
            6 => [],            // Cancelado (final)
        ];
    }

    public function getTransitionRoleMatrix(): array
    {
        return [
            1 => [
                2 => ['admin', 'super-admin'],
                6 => ['admin', 'super-admin'],
            ],
            2 => [
                3 => ['fabrica'],
                6 => ['admin', 'super-admin', 'fabrica'],
            ],
            3 => [
                4 => ['fabrica'],
                6 => ['admin', 'super-admin'],
            ],
            4 => [
                5 => ['admin', 'super-admin'],
            ],
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            [
                'name' => 'fabrica.pedidos.view',
                'display_name' => 'Ver Pedidos',
                'type' => 'ability',
                'description' => 'Visualizar pedidos de produção',
            ],
            [
                'name' => 'fabrica.pedidos.accept',
                'display_name' => 'Aceitar Pedidos',
                'type' => 'ability',
                'description' => 'Aceitar pedidos e informar valor',
            ],
            [
                'name' => 'fabrica.pedidos.dispatch',
                'display_name' => 'Despachar Pedidos',
                'type' => 'ability',
                'description' => 'Registrar despacho e código de rastreio',
            ],
            [
                'name' => 'fabrica.pedidos.reject_item',
                'display_name' => 'Recusar Itens',
                'type' => 'ability',
                'description' => 'Recusar itens individuais com justificativa',
            ],
            [
                'name' => 'fabrica.pedidos.receive',
                'display_name' => 'Receber Pedidos',
                'type' => 'ability',
                'description' => 'Confirmar recebimento e distribuir às lojas',
            ],
            [
                'name' => 'fabrica.pedidos.cancel',
                'display_name' => 'Cancelar Pedidos',
                'type' => 'ability',
                'description' => 'Cancelar pedidos de produção',
            ],
            [
                'name' => 'fabrica.carrinho.manage',
                'display_name' => 'Gerenciar Carrinho',
                'type' => 'ability',
                'description' => 'Adicionar/remover itens do carrinho de produção',
            ],
        ];
    }

    public function getScreens(): array
    {
        return [
            [
                'name' => 'fabrica.dashboard',
                'display_name' => 'Dashboard Fábrica',
                'path' => '/fabrica',
            ],
            [
                'name' => 'fabrica.pedidos',
                'display_name' => 'Lista de Pedidos',
                'path' => '/fabrica/pedidos',
            ],
            [
                'name' => 'fabrica.pedidos.detail',
                'display_name' => 'Detalhes do Pedido',
                'path' => '/fabrica/pedidos/:id',
            ],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'accept' => [
                'label' => 'Aceitar Pedido',
                'icon' => 'CheckCircle',
                'tooltip' => 'Aceitar pedido e informar valor de produção',
                'permission' => 'fabrica.pedidos.accept',
                'available_in_status' => [2],
                'confirm' => true,
                'confirm_title' => 'Aceitar Pedido',
                'confirm_message' => 'Confirma a aceitação do pedido? Informe o valor total.',
                'requires_fields' => ['factory_total'],
            ],
            'dispatch' => [
                'label' => 'Despachar',
                'icon' => 'Truck',
                'tooltip' => 'Registrar despacho do pedido',
                'permission' => 'fabrica.pedidos.dispatch',
                'available_in_status' => [3],
                'confirm' => true,
                'confirm_title' => 'Despachar Pedido',
                'confirm_message' => 'Confirma o despacho? Opcionalmente informe o código de rastreio.',
            ],
            'receive' => [
                'label' => 'Confirmar Recebimento',
                'icon' => 'PackageCheck',
                'tooltip' => 'Confirmar recebimento e distribuir às lojas',
                'permission' => 'fabrica.pedidos.receive',
                'available_in_status' => [4],
                'confirm' => true,
                'confirm_title' => 'Confirmar Recebimento',
                'confirm_message' => 'Confirma o recebimento? As capas serão marcadas como disponíveis.',
            ],
            'reject_item' => [
                'label' => 'Recusar Item',
                'icon' => 'XCircle',
                'tooltip' => 'Recusar item individual com justificativa',
                'permission' => 'fabrica.pedidos.reject_item',
                'available_in_status' => [2, 3],
                'confirm' => true,
                'confirm_title' => 'Recusar Item',
                'confirm_message' => 'Confirma a recusa? Informe a justificativa.',
                'requires_fields' => ['reason'],
            ],
            'cancel' => [
                'label' => 'Cancelar Pedido',
                'icon' => 'XOctagon',
                'tooltip' => 'Cancelar pedido de produção',
                'permission' => 'fabrica.pedidos.cancel',
                'available_in_status' => [1, 2, 3],
                'confirm' => true,
                'confirm_title' => 'Cancelar Pedido',
                'confirm_message' => 'Tem certeza? Esta ação não pode ser desfeita.',
                'confirm_variant' => 'destructive',
            ],
            'download_photos' => [
                'label' => 'Baixar Fotos',
                'icon' => 'Download',
                'tooltip' => 'Baixar todas as fotos para produção',
                'permission' => 'fabrica.pedidos.view',
                'available_in_status' => [2, 3, 4],
            ],
        ];
    }

    // ========================================
    // UI Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Fábrica',
            'menu_tooltip' => 'Portal de produção',
            'page_title' => 'Pedidos de Produção',
            'page_description' => 'Gerencie os pedidos de produção de capas personalizadas',
            'create_button' => 'Novo Pedido',
            'empty_state' => 'Nenhum pedido de produção encontrado.',
            'loading_title' => 'Carregando pedidos...',
            'loading_description' => 'Aguarde enquanto buscamos os pedidos.',
            'error_title' => 'Erro ao carregar',
            'error_description' => 'Não foi possível carregar os pedidos.',
            'retry_button' => 'Tentar novamente',
        ];
    }

    // ========================================
    // Config Schema
    // ========================================

    public function getConfigSchema(): array
    {
        return [
            'sections' => [
                'production' => [
                    'label' => 'Produção',
                    'icon' => 'Factory',
                    'description' => 'Configurações de produção',
                    'fields' => [
                        'auto_sync_status' => [
                            'type' => 'switch',
                            'label' => 'Sincronizar status automaticamente',
                            'hint' => 'Atualizar status das capas quando status do pedido mudar',
                            'default' => true,
                        ],
                        'require_tracking_code' => [
                            'type' => 'switch',
                            'label' => 'Exigir código de rastreio',
                            'hint' => 'Obrigar informar código de rastreio ao despachar',
                            'default' => false,
                        ],
                        'require_rejection_reason' => [
                            'type' => 'switch',
                            'label' => 'Exigir justificativa de recusa',
                            'hint' => 'Obrigar justificativa ao recusar itens',
                            'default' => true,
                        ],
                    ],
                ],
                'notifications' => [
                    'label' => 'Notificações',
                    'icon' => 'Bell',
                    'description' => 'Alertas e notificações',
                    'fields' => [
                        'notify_on_new_order' => [
                            'type' => 'switch',
                            'label' => 'Notificar novo pedido',
                            'hint' => 'Enviar notificação à fábrica quando receber novo pedido',
                            'default' => true,
                        ],
                        'notify_on_dispatch' => [
                            'type' => 'switch',
                            'label' => 'Notificar despacho',
                            'hint' => 'Enviar notificação ao admin quando pedido for despachado',
                            'default' => true,
                        ],
                    ],
                ],
            ],
            'defaults' => $this->getDefaultConfig(),
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'auto_sync_status' => true,
            'require_tracking_code' => false,
            'require_rejection_reason' => true,
            'notify_on_new_order' => true,
            'notify_on_dispatch' => true,
        ];
    }

    // ========================================
    // Filters
    // ========================================

    public function getFilters(): array
    {
        return [
            'status' => [
                'type' => 'multi-select',
                'label' => 'Status',
                'options' => 'from_statuses',
            ],
            'date_range' => [
                'type' => 'date-range',
                'label' => 'Período',
                'presets' => ['today', 'week', 'month', 'custom'],
            ],
        ];
    }

    // ========================================
    // Table Columns
    // ========================================

    public function getTableColumns(): array
    {
        return [
            'default' => [
                ['key' => 'id', 'label' => '#', 'sortable' => true, 'width' => 80],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'total_itens', 'label' => 'Itens', 'sortable' => true],
                ['key' => 'factory_total', 'label' => 'Valor', 'type' => 'currency'],
                ['key' => 'created_at', 'label' => 'Criado em', 'type' => 'date', 'format' => 'dd/MM/yyyy'],
                ['key' => 'dispatched_at', 'label' => 'Despachado em', 'type' => 'date', 'format' => 'dd/MM/yyyy'],
            ],
        ];
    }

    // ========================================
    // Documentation
    // ========================================

    public function getDocumentation(): array
    {
        return [
            'overview' => 'O módulo Fábrica gerencia o fluxo de produção de capas personalizadas entre o admin e a fábrica.',
            'workflow' => [
                'title' => 'Fluxo de Produção',
                'steps' => [
                    '1. Admin adiciona capas ao carrinho de produção',
                    '2. Admin fecha o carrinho e envia para a fábrica',
                    '3. Fábrica aceita o pedido e informa valor',
                    '4. Fábrica produz e despacha o pedido',
                    '5. Admin recebe e distribui para as lojas',
                ],
            ],
            'roles' => [
                'fabrica' => 'Aceita pedidos, produz e despacha',
                'admin' => 'Cria pedidos, recebe e distribui',
            ],
        ];
    }
}
