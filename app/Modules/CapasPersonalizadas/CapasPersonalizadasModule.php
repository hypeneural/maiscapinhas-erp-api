<?php

declare(strict_types=1);

namespace App\Modules\CapasPersonalizadas;

use App\Modules\BaseModule;

/**
 * Module: Capas Personalizadas
 *
 * Gerenciamento de capas com foto do cliente + fluxo de produção.
 */
class CapasPersonalizadasModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'capas-personalizadas';
    }

    public function getName(): string
    {
        return 'Capas Personalizadas';
    }

    public function getDescription(): string
    {
        return 'Capas com foto do cliente, integrado ao fluxo de produção e fábrica.';
    }

    public function getIcon(): string
    {
        return 'Palette';
    }

    public function getDependencies(): array
    {
        return ['customers', 'payment-methods', 'producao'];
    }

    // ========================================
    // Status
    // ========================================

    public function getStatuses(): array
    {
        return [
            1 => [
                'name' => 'solicitada',
                'label' => 'Encomenda Solicitada',
                'color' => 'blue',
                'icon' => 'clipboard-list',
                'final' => false,
            ],
            2 => [
                'name' => 'indisponivel',
                'label' => 'Produto Indisponível',
                'color' => 'red',
                'icon' => 'alert-circle',
                'final' => false,
            ],
            3 => [
                'name' => 'disponivel',
                'label' => 'Disponível na Loja',
                'color' => 'yellow',
                'icon' => 'store',
                'final' => false,
            ],
            4 => [
                'name' => 'concluida',
                'label' => 'Venda Realizada',
                'color' => 'green',
                'icon' => 'check-circle',
                'final' => true,
            ],
            5 => [
                'name' => 'cancelada',
                'label' => 'Cancelada',
                'color' => 'gray',
                'icon' => 'x-circle',
                'final' => true,
            ],
            6 => [
                'name' => 'enviado_producao',
                'label' => 'Encomendado à Fábrica',
                'color' => 'orange',
                'icon' => 'send',
                'final' => false,
            ],
            7 => [
                'name' => 'no_carrinho',
                'label' => 'No Carrinho de Produção',
                'color' => 'slate',
                'icon' => 'shopping-cart',
                'final' => false,
            ],
            8 => [
                'name' => 'aguardando',
                'label' => 'Aguardando Cliente',
                'color' => 'purple',
                'icon' => 'user-clock',
                'final' => false,
            ],
            9 => [
                'name' => 'em_producao',
                'label' => 'Em Produção',
                'color' => 'teal',
                'icon' => 'factory',
                'final' => false,
            ],
            10 => [
                'name' => 'despachado',
                'label' => 'Despachado',
                'color' => 'indigo',
                'icon' => 'truck',
                'final' => false,
            ],
        ];
    }

    // ========================================
    // Transitions
    // ========================================

    public function getTransitions(): array
    {
        return [
            1 => [2, 5, 7],         // solicitada → indisponivel, cancelada, carrinho
            2 => [1, 5],            // indisponivel → solicitada, cancelada
            7 => [6, 5],            // carrinho → enviado_producao, cancelada (admin)
            6 => [9, 5],            // enviado → em_producao, cancelada (fabrica)
            9 => [10, 5],           // em_producao → despachado, cancelada
            10 => [3],              // despachado → disponivel
            3 => [8, 5],            // disponivel → aguardando, cancelada
            8 => [4, 5],            // aguardando → concluida, cancelada
        ];
    }

    public function getTransitionRoleMatrix(): array
    {
        return [
            // From solicitada
            1 => [
                2 => ['admin', 'gerente', 'super-admin'],
                5 => ['vendedor', 'admin', 'gerente', 'super-admin'], // vendedor pode cancelar AQUI
                7 => ['admin', 'gerente', 'estoquista', 'super-admin'],
            ],
            // From indisponivel
            2 => [
                1 => ['admin', 'gerente', 'super-admin'],
                5 => ['admin', 'gerente', 'super-admin'],
            ],
            // From carrinho (vendedor NÃO pode mais cancelar)
            7 => [
                6 => ['admin', 'gerente', 'estoquista', 'super-admin'],
                5 => ['admin', 'gerente', 'super-admin'], // vendedor NÃO está aqui
            ],
            // From enviado
            6 => [
                9 => ['fabrica'], // fábrica aceita
                5 => ['fabrica', 'admin', 'super-admin'], // fábrica recusa
            ],
            // From em_producao
            9 => [
                10 => ['fabrica'], // fábrica despacha
                5 => ['fabrica', 'admin', 'super-admin'],
            ],
            // From despachado
            10 => [
                3 => ['admin', 'gerente', 'estoquista', 'super-admin'], // confirma recebimento
            ],
            // From disponivel
            3 => [
                8 => ['vendedor', 'admin', 'gerente', 'super-admin'], // avisar cliente
                5 => ['vendedor', 'admin', 'gerente', 'super-admin'],
            ],
            // From aguardando
            8 => [
                4 => ['vendedor', 'admin', 'gerente', 'super-admin'], // concluir
                5 => ['vendedor', 'admin', 'gerente', 'super-admin'],
            ],
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => 'capas.view', 'display_name' => 'Ver capas (próprias)', 'type' => 'ability'],
            ['name' => 'capas.view-all', 'display_name' => 'Ver todas as capas da loja', 'type' => 'ability'],
            ['name' => 'capas.view-global', 'display_name' => 'Ver capas de todas as lojas', 'type' => 'ability'],
            ['name' => 'capas.create', 'display_name' => 'Criar capa', 'type' => 'ability'],
            ['name' => 'capas.update', 'display_name' => 'Editar capa', 'type' => 'ability'],
            ['name' => 'capas.cancel-before-cart', 'display_name' => 'Cancelar (antes do carrinho)', 'type' => 'ability'],
            ['name' => 'capas.cancel-after-cart', 'display_name' => 'Cancelar (após carrinho)', 'type' => 'ability'],
            ['name' => 'capas.status.to-carrinho', 'display_name' => 'Adicionar ao carrinho', 'type' => 'ability'],
            ['name' => 'capas.status.to-enviado', 'display_name' => 'Enviar à fábrica', 'type' => 'ability'],
            ['name' => 'capas.status.to-producao', 'display_name' => 'Aceitar na fábrica', 'type' => 'ability'],
            ['name' => 'capas.status.to-despachado', 'display_name' => 'Despachar', 'type' => 'ability'],
            ['name' => 'capas.status.to-disponivel', 'display_name' => 'Confirmar recebimento', 'type' => 'ability'],
            ['name' => 'capas.status.to-aguardando', 'display_name' => 'Avisar cliente', 'type' => 'ability'],
            ['name' => 'capas.status.to-concluida', 'display_name' => 'Finalizar venda', 'type' => 'ability'],
            ['name' => 'capas.send-whatsapp', 'display_name' => 'Enviar notificação WhatsApp', 'type' => 'ability'],
            ['name' => 'capas.view-kanban', 'display_name' => 'Ver modo Kanban', 'type' => 'ability'],
        ];
    }

    public function getScreens(): array
    {
        return [
            ['name' => 'screen.capas', 'display_name' => 'Menu Capas', 'path' => '/capas'],
            ['name' => 'screen.capas.list', 'display_name' => 'Lista de Capas', 'path' => '/capas'],
            ['name' => 'screen.capas.create', 'display_name' => 'Nova Capa', 'path' => '/capas/new'],
            ['name' => 'screen.capas.detail', 'display_name' => 'Detalhe da Capa', 'path' => '/capas/:id'],
            ['name' => 'screen.capas.edit', 'display_name' => 'Editar Capa', 'path' => '/capas/:id/edit'],
            ['name' => 'screen.capas.production', 'display_name' => 'Enviar Produção', 'path' => '/capas/producao'],
            ['name' => 'screen.capas.kanban', 'display_name' => 'Kanban', 'path' => '/capas/kanban'],
        ];
    }

    // ========================================
    // UI Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Capas Personalizadas',
            'menu_tooltip' => 'Capas com foto do cliente',
            'page_title' => 'Capas Personalizadas',
            'page_description' => 'Gerencie capas com imagens personalizadas.',
            'create_button' => 'Nova Capa',
            'empty_state' => 'Nenhuma capa encontrada.',
            'loading_title' => 'Carregando capas...',
            'loading_description' => 'Aguarde enquanto buscamos as capas.',
            'error_title' => 'Erro ao carregar capas',
            'error_description' => 'Não foi possível carregar a lista de capas.',
            'retry_button' => 'Tentar novamente',
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'create' => [
                'label' => 'Nova Capa',
                'icon' => 'Plus',
                'tooltip' => 'Criar uma nova capa personalizada',
                'shortcut' => 'N',
                'shortcut_modifier' => null,
                'permission' => 'capas.create',
            ],
            'add_to_cart' => [
                'label' => 'Adicionar ao Carrinho',
                'icon' => 'ShoppingCart',
                'tooltip' => 'Adicionar esta capa ao carrinho de produção',
                'confirm' => true,
                'confirm_title' => 'Adicionar ao Carrinho?',
                'confirm_message' => 'A capa será enviada para produção.',
                'confirm_button' => 'Sim, Adicionar',
                'cancel_button' => 'Cancelar',
                'confirm_variant' => 'default',
                'permission' => 'capas.status.to-carrinho',
                'available_in_status' => [1],
            ],
            'avisar_cliente' => [
                'label' => 'Avisar Cliente',
                'icon' => 'Bell',
                'tooltip' => 'Enviar WhatsApp informando que a capa está pronta',
                'shortcut' => 'A',
                'shortcut_modifier' => null,
                'confirm' => true,
                'confirm_title' => 'Avisar Cliente?',
                'confirm_message' => 'O cliente receberá uma notificação WhatsApp.',
                'confirm_button' => 'Sim, Enviar',
                'cancel_button' => 'Cancelar',
                'confirm_variant' => 'default',
                'permission' => 'capas.status.to-aguardando',
                'available_in_status' => [3],
            ],
            'confirmar_recebimento' => [
                'label' => 'Confirmar Recebimento',
                'icon' => 'PackageCheck',
                'tooltip' => 'Confirmar que a capa chegou da fábrica',
                'confirm' => true,
                'confirm_title' => 'Confirmar Recebimento?',
                'confirm_message' => 'A capa ficará disponível para retirada.',
                'confirm_button' => 'Confirmar',
                'cancel_button' => 'Voltar',
                'confirm_variant' => 'default',
                'permission' => 'capas.status.to-disponivel',
                'available_in_status' => [10],
            ],
            'finalizar_venda' => [
                'label' => 'Finalizar Venda',
                'icon' => 'CheckCircle',
                'tooltip' => 'Registrar pagamento e finalizar',
                'confirm' => true,
                'confirm_title' => 'Finalizar Venda',
                'confirm_message' => 'Confirma o registro do pagamento?',
                'confirm_button' => 'Confirmar Venda',
                'cancel_button' => 'Voltar',
                'confirm_variant' => 'default',
                'permission' => 'capas.status.to-concluida',
                'available_in_status' => [8],
                'requires_fields' => ['payment_status', 'payment_1_amount', 'payment_1_date', 'payment_1_method_id'],
            ],
            'cancelar' => [
                'label' => 'Cancelar',
                'icon' => 'X',
                'tooltip' => 'Cancelar esta capa',
                'confirm' => true,
                'confirm_title' => 'Cancelar Capa',
                'confirm_message' => 'Tem certeza que deseja cancelar?',
                'confirm_button' => 'Sim, Cancelar',
                'cancel_button' => 'Não, Voltar',
                'confirm_variant' => 'destructive',
                'permission' => 'capas.cancel-before-cart',
                'available_in_status' => [1],
                'requires_fields' => ['cancelation_reason'],
            ],
            'view_kanban' => [
                'label' => 'Modo Kanban',
                'icon' => 'LayoutGrid',
                'tooltip' => 'Visualizar em modo Kanban',
                'permission' => 'capas.view-kanban',
            ],
        ];
    }

    // ========================================
    // Permission Groups
    // ========================================

    public function getPermissionGroups(): array
    {
        return [
            'visualizacao' => [
                'label' => 'Visualização',
                'icon' => 'Eye',
                'permissions' => ['capas.view', 'capas.view-all', 'capas.view-global', 'capas.view-kanban'],
            ],
            'gerenciamento' => [
                'label' => 'Gerenciamento',
                'icon' => 'Edit',
                'permissions' => ['capas.create', 'capas.update', 'capas.cancel-before-cart', 'capas.cancel-after-cart'],
            ],
            'producao' => [
                'label' => 'Produção',
                'icon' => 'Factory',
                'description' => 'Fluxo de produção e fábrica',
                'permissions' => ['capas.status.to-carrinho', 'capas.status.to-enviado', 'capas.status.to-producao', 'capas.status.to-despachado', 'capas.status.to-disponivel'],
            ],
            'venda' => [
                'label' => 'Venda',
                'icon' => 'DollarSign',
                'permissions' => ['capas.status.to-aguardando', 'capas.status.to-concluida', 'capas.send-whatsapp'],
            ],
        ];
    }

    // ========================================
    // Documentation
    // ========================================

    public function getDocumentation(): array
    {
        return [
            'overview' => 'Módulo para capas com foto do cliente. Integra com fluxo de produção da fábrica.',
            'workflow' => [
                'title' => 'Fluxo Completo',
                'steps' => [
                    '1. Vendedor cria a capa e faz upload da foto',
                    '2. Admin/Estoquista adiciona ao carrinho de produção',
                    '3. Carrinho é enviado para a fábrica',
                    '4. Fábrica aceita, produz e despacha',
                    '5. Admin confirma recebimento na loja',
                    '6. Vendedor avisa cliente e finaliza venda',
                ],
            ],
            'roles' => [
                'vendedor' => 'Cria capas, avisa cliente, finaliza venda',
                'estoquista' => 'Gerencia carrinho de produção',
                'fabrica' => 'Aceita, produz e despacha',
                'admin' => 'Acesso completo',
            ],
            'faq' => [
                'Posso cancelar após adicionar ao carrinho?' => 'Não, após o carrinho apenas Admin pode cancelar.',
                'Como funciona o pagamento parcial?' => 'Selecione "Pagamento Parcial" e preencha os dois pagamentos.',
            ],
        ];
    }

    // ========================================
    // Conditional Fields
    // ========================================

    public function getConditionalFields(): array
    {
        return [
            'cancelada' => [
                'cancelation_reason' => [
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'seller_request' => 'Por solicitação do vendedor',
                        'customer_request' => 'Por solicitação do cliente',
                        'image_defect' => 'Imagem com defeito',
                        'unavailable' => 'Indisponibilidade do produto',
                        'fabrica_reject' => 'Recusado pela fábrica',
                        'other' => 'Outro motivo',
                    ],
                ],
                'cancelation_notes' => [
                    'type' => 'textarea',
                    'required' => false,
                ],
            ],
            'concluida' => [
                'payment_status' => [
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'total' => 'Pagamento Total',
                        'partial' => 'Pagamento Parcial',
                        'pending' => 'Pagamento Pendente',
                    ],
                ],
                'payment_1_amount' => [
                    'type' => 'money',
                    'required' => true,
                ],
                'payment_1_date' => [
                    'type' => 'date',
                    'required' => true,
                ],
                'payment_1_method_id' => [
                    'type' => 'select',
                    'required' => true,
                    'source' => 'payment_methods',
                ],
                'payment_2_amount' => [
                    'type' => 'money',
                    'required' => false, // Only if partial
                ],
                'payment_2_date' => [
                    'type' => 'date',
                    'required' => false,
                ],
                'payment_2_method_id' => [
                    'type' => 'select',
                    'required' => false,
                    'source' => 'payment_methods',
                ],
            ],
        ];
    }

    // ========================================
    // Automations
    // ========================================

    public function getAutomations(): array
    {
        return [
            [
                'name' => 'auto_cancel_disponivel',
                'display_name' => 'Cancelar após 20 dias disponível',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 3, // disponivel
                    'days' => 20,
                    'action' => 'change_status',
                    'new_status' => 5, // cancelada
                    'set_fields' => [
                        'cancelation_reason' => 'seller_inertia',
                    ],
                ],
            ],
            [
                'name' => 'auto_cancel_aguardando',
                'display_name' => 'Cancelar após 20 dias aguardando',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 8, // aguardando
                    'days' => 20,
                    'action' => 'change_status',
                    'new_status' => 5, // cancelada
                    'set_fields' => [
                        'cancelation_reason' => 'customer_no_show',
                    ],
                ],
            ],
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
            'seller' => [
                'type' => 'select',
                'label' => 'Vendedor',
                'options' => 'from_users',
            ],
            'store' => [
                'type' => 'select',
                'label' => 'Loja',
                'options' => 'from_user_stores',
            ],
            'date_range' => [
                'type' => 'date-range',
                'label' => 'Período',
                'presets' => ['today', 'week', 'month', 'custom'],
            ],
            'production_status' => [
                'type' => 'select',
                'label' => 'Produção',
                'options' => [
                    'pending' => 'Aguardando envio',
                    'sent' => 'Enviado à fábrica',
                    'producing' => 'Em produção',
                    'shipped' => 'Despachado',
                ],
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
                ['key' => 'customer_name', 'label' => 'Cliente', 'sortable' => true],
                ['key' => 'phone_model', 'label' => 'Modelo', 'sortable' => true],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'seller_name', 'label' => 'Vendedor', 'sortable' => true],
                ['key' => 'created_at', 'label' => 'Data', 'type' => 'date', 'format' => 'dd/MM/yyyy'],
            ],
            'compact' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'customer_name', 'label' => 'Cliente'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ],
            'production' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'image_thumbnail', 'label' => 'Imagem', 'type' => 'image'],
                ['key' => 'phone_model', 'label' => 'Modelo'],
                ['key' => 'store_name', 'label' => 'Loja'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ],
        ];
    }

    // ========================================
    // Bulk Actions
    // ========================================

    public function getBulkActions(): array
    {
        return [
            'add_to_cart' => [
                'label' => 'Adicionar ao Carrinho',
                'icon' => 'ShoppingCart',
                'permission' => 'capas.status.to-carrinho',
                'requires_selection' => true,
                'min_selection' => 1,
                'max_selection' => 100,
            ],
            'export' => [
                'label' => 'Exportar Selecionados',
                'icon' => 'Download',
                'permission' => 'capas.export',
                'requires_selection' => true,
                'formats' => ['xlsx', 'pdf', 'csv'],
            ],
        ];
    }

    // ========================================
    // Row Actions
    // ========================================

    public function getRowActions(): array
    {
        return [
            'primary' => [
                'action' => 'view',
                'label' => 'Ver Detalhes',
                'icon' => 'Eye',
            ],
            'secondary' => [
                ['action' => 'edit', 'label' => 'Editar', 'icon' => 'Edit', 'permission' => 'capas.update'],
                ['action' => 'duplicate', 'label' => 'Duplicar', 'icon' => 'Copy', 'permission' => 'capas.create'],
                ['action' => 'cancel', 'label' => 'Cancelar', 'icon' => 'X', 'permission' => 'capas.cancel-before-cart', 'variant' => 'destructive'],
            ],
        ];
    }

    // ========================================
    // Notifications
    // ========================================

    public function getNotifications(): array
    {
        return [
            'created' => [
                'title' => 'Capa criada!',
                'description' => 'Capa #{id} foi criada com sucesso.',
                'variant' => 'success',
            ],
            'added_to_cart' => [
                'title' => 'Adicionado ao carrinho',
                'description' => 'Capa #{id} foi adicionada ao carrinho de produção.',
                'variant' => 'success',
            ],
            'sent_to_factory' => [
                'title' => 'Enviado à fábrica',
                'description' => 'Lote com {count} capas foi enviado.',
                'variant' => 'info',
            ],
            'received' => [
                'title' => 'Recebimento confirmado',
                'description' => 'Capa #{id} chegou na loja.',
                'variant' => 'success',
            ],
            'notified' => [
                'title' => 'Cliente notificado',
                'description' => 'O cliente foi avisado via WhatsApp.',
                'variant' => 'success',
            ],
            'completed' => [
                'title' => 'Venda concluída!',
                'description' => 'Capa #{id} foi finalizada.',
                'variant' => 'success',
            ],
            'cancelled' => [
                'title' => 'Capa cancelada',
                'description' => 'Capa #{id} foi cancelada.',
                'variant' => 'warning',
            ],
            'error' => [
                'title' => 'Erro',
                'description' => 'Não foi possível completar a ação.',
                'variant' => 'destructive',
            ],
        ];
    }

    // ========================================
    // Stats Cards
    // ========================================

    public function getStatsCards(): array
    {
        return [
            'enabled' => true,
            'permission' => 'capas.view-stats',
            'cards' => [
                ['id' => 'total', 'label' => 'Total', 'icon' => 'Image', 'color' => 'blue'],
                ['id' => 'in_cart', 'label' => 'No Carrinho', 'icon' => 'ShoppingCart', 'color' => 'slate'],
                ['id' => 'in_production', 'label' => 'Em Produção', 'icon' => 'Factory', 'color' => 'orange'],
                ['id' => 'available', 'label' => 'Disponíveis', 'icon' => 'Store', 'color' => 'green'],
                ['id' => 'completed', 'label' => 'Concluídas', 'icon' => 'CheckCircle', 'color' => 'emerald'],
            ],
        ];
    }
}
