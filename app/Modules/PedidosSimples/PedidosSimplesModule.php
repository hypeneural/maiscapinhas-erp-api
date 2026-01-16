<?php

declare(strict_types=1);

namespace App\Modules\PedidosSimples;

use App\Modules\BaseModule;

/**
 * Module: Pedidos Simples
 *
 * Gerenciamento de pedidos de encomenda de produtos.
 */
class PedidosSimplesModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'pedidos-simples';
    }

    public function getName(): string
    {
        return 'Pedidos Simples';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento de pedidos de encomenda de produtos.';
    }

    public function getIcon(): string
    {
        return 'FileCheck';
    }

    public function getDependencies(): array
    {
        return ['customers', 'payment-methods'];
    }

    // ========================================
    // Status
    // ========================================

    public function getStatuses(): array
    {
        return [
            1 => [
                'name' => 'solicitado',
                'label' => 'Solicitado',
                'description' => 'Pedido criado, aguardando processamento pelo administrativo.',
                'color' => 'blue',
                'icon' => 'clipboard-list',
                'badge_variant' => 'secondary',
                'can_edit' => true,
                'final' => false,
            ],
            2 => [
                'name' => 'indisponivel',
                'label' => 'Produto Indisponível',
                'description' => 'Produto não disponível no momento. Aguardando reposição.',
                'color' => 'red',
                'icon' => 'alert-circle',
                'badge_variant' => 'destructive',
                'can_edit' => true,
                'final' => false,
            ],
            3 => [
                'name' => 'disponivel',
                'label' => 'Disponível na Loja',
                'description' => 'Produto chegou na loja. Aguardando vendedor avisar o cliente.',
                'color' => 'yellow',
                'icon' => 'store',
                'badge_variant' => 'warning',
                'can_edit' => false,
                'final' => false,
            ],
            4 => [
                'name' => 'aguardando',
                'label' => 'Aguardando Cliente',
                'description' => 'Cliente foi notificado. Aguardando retirada do produto.',
                'color' => 'purple',
                'icon' => 'user-clock',
                'badge_variant' => 'outline',
                'can_edit' => false,
                'final' => false,
            ],
            5 => [
                'name' => 'concluido',
                'label' => 'Venda Concluída',
                'description' => 'Cliente retirou o produto e pagou. Pedido finalizado.',
                'color' => 'green',
                'icon' => 'check-circle',
                'badge_variant' => 'success',
                'can_edit' => false,
                'final' => true,
            ],
            6 => [
                'name' => 'cancelado',
                'label' => 'Cancelado',
                'description' => 'Pedido cancelado. Verifique o motivo do cancelamento.',
                'color' => 'gray',
                'icon' => 'x-circle',
                'badge_variant' => 'secondary',
                'can_edit' => false,
                'final' => true,
            ],
        ];
    }

    // ========================================
    // UI Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Pedidos',
            'menu_tooltip' => 'Gerenciar pedidos de encomenda',
            'page_title' => 'Pedidos de Encomenda',
            'page_description' => 'Acompanhe todos os pedidos de encomenda de produtos.',
            'create_button' => 'Novo Pedido',
            'empty_state' => 'Nenhum pedido encontrado. Clique em "Novo Pedido" para criar.',
            'loading_title' => 'Carregando pedidos...',
            'loading_description' => 'Aguarde enquanto buscamos os pedidos.',
            'error_title' => 'Erro ao carregar pedidos',
            'error_description' => 'Não foi possível carregar a lista de pedidos.',
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
                'label' => 'Novo Pedido',
                'icon' => 'Plus',
                'tooltip' => 'Criar um novo pedido de encomenda',
                'shortcut' => 'N',
                'shortcut_modifier' => null,
                'permission' => 'pedidos.create',
            ],
            'edit' => [
                'label' => 'Editar',
                'icon' => 'Edit',
                'tooltip' => 'Editar dados do pedido',
                'permission' => 'pedidos.update',
                'available_in_status' => [1, 2],
            ],
            'avisar_cliente' => [
                'label' => 'Avisar Cliente',
                'icon' => 'Bell',
                'tooltip' => 'Enviar notificação WhatsApp para o cliente informando que o produto está disponível',
                'shortcut' => 'A',
                'shortcut_modifier' => null,
                'confirm' => true,
                'confirm_title' => 'Avisar Cliente?',
                'confirm_message' => 'O cliente receberá uma mensagem WhatsApp informando que o produto está disponível.',
                'confirm_button' => 'Sim, Enviar',
                'cancel_button' => 'Cancelar',
                'confirm_variant' => 'default',
                'permission' => 'pedidos.status.to-aguardando',
                'available_in_status' => [3],
            ],
            'finalizar_venda' => [
                'label' => 'Finalizar Venda',
                'icon' => 'CheckCircle',
                'tooltip' => 'Registrar que o cliente retirou o produto e pagou',
                'confirm' => true,
                'confirm_title' => 'Finalizar Venda',
                'confirm_message' => 'Confirma o registro do pagamento e conclusão da venda?',
                'confirm_button' => 'Confirmar Venda',
                'cancel_button' => 'Voltar',
                'confirm_variant' => 'default',
                'permission' => 'pedidos.status.to-concluido',
                'available_in_status' => [4],
                'requires_fields' => ['payment_amount', 'payment_date', 'payment_method_id'],
            ],
            'cancelar' => [
                'label' => 'Cancelar Pedido',
                'icon' => 'X',
                'tooltip' => 'Cancelar este pedido. Requer justificativa obrigatória.',
                'confirm' => true,
                'confirm_title' => 'Cancelar Pedido',
                'confirm_message' => 'Tem certeza que deseja cancelar este pedido? Esta ação não pode ser desfeita.',
                'confirm_button' => 'Sim, Cancelar',
                'cancel_button' => 'Não, Voltar',
                'confirm_variant' => 'destructive',
                'permission' => 'pedidos.cancel',
                'available_in_status' => [1, 2, 3, 4],
                'requires_fields' => ['cancelation_reason'],
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
                'description' => 'Controla o que o usuário pode ver',
                'permissions' => ['pedidos.view', 'pedidos.view-all', 'pedidos.view-global'],
            ],
            'gerenciamento' => [
                'label' => 'Gerenciamento',
                'icon' => 'Edit',
                'description' => 'Criar, editar e cancelar pedidos',
                'permissions' => ['pedidos.create', 'pedidos.update', 'pedidos.cancel', 'pedidos.assign-seller'],
            ],
            'status' => [
                'label' => 'Transições de Status',
                'icon' => 'ArrowRight',
                'description' => 'Controla quais status o usuário pode alterar',
                'permissions' => ['pedidos.status.to-disponivel', 'pedidos.status.to-aguardando', 'pedidos.status.to-concluido'],
            ],
            'notificacoes' => [
                'label' => 'Notificações',
                'icon' => 'Bell',
                'description' => 'Enviar notificações aos clientes',
                'permissions' => ['pedidos.send-whatsapp'],
            ],
        ];
    }

    // ========================================
    // Documentation
    // ========================================

    public function getDocumentation(): array
    {
        return [
            'overview' => 'O módulo de Pedidos Simples gerencia encomendas de produtos. Ideal para quando o cliente solicita um produto que não está disponível no estoque.',
            'workflow' => [
                'title' => 'Fluxo do Pedido',
                'steps' => [
                    '1. Vendedor cria o pedido quando cliente solicita um produto',
                    '2. Administrador marca como "Disponível na Loja" quando produto chega',
                    '3. Vendedor clica em "Avisar Cliente" para enviar WhatsApp',
                    '4. Cliente retira o produto e vendedor clica em "Finalizar Venda"',
                ],
            ],
            'roles' => [
                'vendedor' => 'Cria pedidos, avisa cliente via WhatsApp, finaliza venda',
                'admin' => 'Marca pedido como disponível, gerencia todos os pedidos da loja',
                'gerente' => 'Mesmas permissões do admin, com acesso a relatórios',
            ],
            'faq' => [
                'Como cancelar um pedido?' => 'Clique em "Cancelar Pedido" e informe o motivo. O cancelamento requer justificativa obrigatória.',
                'O que acontece após 20 dias?' => 'Pedidos não finalizados são cancelados automaticamente pelo sistema.',
                'Como avisar o cliente?' => 'Quando o pedido estiver "Disponível na Loja", clique no botão "Avisar Cliente" para enviar uma mensagem WhatsApp.',
            ],
        ];
    }

    // ========================================
    // Transitions
    // ========================================

    public function getTransitions(): array
    {
        return [
            1 => [2, 3, 6],     // solicitado → indisponivel, disponivel, cancelado
            2 => [1, 6],        // indisponivel → solicitado, cancelado
            3 => [4, 6],        // disponivel → aguardando, cancelado
            4 => [5, 6],        // aguardando → concluido, cancelado
        ];
    }

    public function getTransitionRoleMatrix(): array
    {
        return [
            // From solicitado
            1 => [
                2 => ['admin', 'gerente', 'super-admin'],       // → indisponivel
                3 => ['admin', 'gerente', 'super-admin'],       // → disponivel
                6 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → cancelado
            ],
            // From indisponivel
            2 => [
                1 => ['admin', 'gerente', 'super-admin'],       // → solicitado
                6 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → cancelado
            ],
            // From disponivel
            3 => [
                4 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → aguardando (AVISAR CLIENTE)
                6 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → cancelado
            ],
            // From aguardando
            4 => [
                5 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → concluido
                6 => ['vendedor', 'admin', 'gerente', 'super-admin'], // → cancelado
            ],
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => 'pedidos.view', 'display_name' => 'Ver pedidos (próprios)', 'type' => 'ability'],
            ['name' => 'pedidos.view-all', 'display_name' => 'Ver todos os pedidos da loja', 'type' => 'ability'],
            ['name' => 'pedidos.view-global', 'display_name' => 'Ver pedidos de todas as lojas', 'type' => 'ability'],
            ['name' => 'pedidos.create', 'display_name' => 'Criar pedido', 'type' => 'ability'],
            ['name' => 'pedidos.update', 'display_name' => 'Editar pedido', 'type' => 'ability'],
            ['name' => 'pedidos.cancel', 'display_name' => 'Cancelar pedido', 'type' => 'ability'],
            ['name' => 'pedidos.assign-seller', 'display_name' => 'Atribuir vendedor', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-disponivel', 'display_name' => 'Marcar disponível na loja', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-aguardando', 'display_name' => 'Avisar cliente', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-concluido', 'display_name' => 'Finalizar venda', 'type' => 'ability'],
            ['name' => 'pedidos.send-whatsapp', 'display_name' => 'Enviar notificação WhatsApp', 'type' => 'ability'],
        ];
    }

    public function getScreens(): array
    {
        return [
            ['name' => 'screen.pedidos', 'display_name' => 'Menu Pedidos', 'path' => '/pedidos'],
            ['name' => 'screen.pedidos.list', 'display_name' => 'Lista de Pedidos', 'path' => '/pedidos'],
            ['name' => 'screen.pedidos.create', 'display_name' => 'Novo Pedido', 'path' => '/pedidos/new'],
            ['name' => 'screen.pedidos.detail', 'display_name' => 'Detalhe do Pedido', 'path' => '/pedidos/:id'],
            ['name' => 'screen.pedidos.edit', 'display_name' => 'Editar Pedido', 'path' => '/pedidos/:id/edit'],
        ];
    }

    // ========================================
    // Conditional Fields
    // ========================================

    public function getConditionalFields(): array
    {
        return [
            'cancelado' => [
                'cancelation_reason' => [
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'seller_request' => 'Por solicitação do vendedor',
                        'customer_request' => 'Por solicitação do cliente',
                        'unavailable' => 'Indisponibilidade do produto',
                        'seller_inertia' => 'Inércia do vendedor',
                        'customer_no_show' => 'Não comparecimento do cliente',
                        'other' => 'Outro motivo',
                    ],
                ],
                'cancelation_notes' => [
                    'type' => 'textarea',
                    'required' => false, // Required if reason = 'other'
                ],
            ],
            'concluido' => [
                'payment_amount' => [
                    'type' => 'money',
                    'required' => true,
                ],
                'payment_date' => [
                    'type' => 'date',
                    'required' => true,
                ],
                'payment_method_id' => [
                    'type' => 'select',
                    'required' => true,
                    'source' => 'payment_methods',
                ],
                'notes' => [
                    'type' => 'textarea',
                    'required' => false,
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
                    'new_status' => 6, // cancelado
                    'set_fields' => [
                        'cancelation_reason' => 'seller_inertia',
                        'cancelation_notes' => 'Cancelado automaticamente por inércia do vendedor.',
                    ],
                ],
            ],
            [
                'name' => 'auto_cancel_aguardando',
                'display_name' => 'Cancelar após 20 dias aguardando',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 4, // aguardando
                    'days' => 20,
                    'action' => 'change_status',
                    'new_status' => 6, // cancelado
                    'set_fields' => [
                        'cancelation_reason' => 'customer_no_show',
                        'cancelation_notes' => 'Cancelado automaticamente por não comparecimento do cliente.',
                    ],
                ],
            ],
            [
                'name' => 'notify_customer_reminder',
                'display_name' => 'Lembrete 5+5 dias',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 4, // aguardando
                    'days' => 5,
                    'action' => 'send_notification',
                    'channel' => 'whatsapp',
                    'repeat_after' => 5,
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
                ['key' => 'product_description', 'label' => 'Produto', 'sortable' => false],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'seller_name', 'label' => 'Vendedor', 'sortable' => true],
                ['key' => 'created_at', 'label' => 'Data', 'type' => 'date', 'format' => 'dd/MM/yyyy'],
            ],
            'compact' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'customer_name', 'label' => 'Cliente'],
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
            'change_status' => [
                'label' => 'Alterar Status',
                'icon' => 'RefreshCw',
                'permission' => 'pedidos.bulk-update',
                'requires_selection' => true,
                'min_selection' => 1,
                'max_selection' => 50,
            ],
            'export' => [
                'label' => 'Exportar Selecionados',
                'icon' => 'Download',
                'permission' => 'pedidos.export',
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
                ['action' => 'edit', 'label' => 'Editar', 'icon' => 'Edit', 'permission' => 'pedidos.update'],
                ['action' => 'duplicate', 'label' => 'Duplicar', 'icon' => 'Copy', 'permission' => 'pedidos.create'],
                ['action' => 'cancel', 'label' => 'Cancelar', 'icon' => 'X', 'permission' => 'pedidos.cancel', 'variant' => 'destructive'],
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
                'title' => 'Pedido criado!',
                'description' => 'Pedido #{id} foi criado com sucesso.',
                'variant' => 'success',
            ],
            'status_changed' => [
                'title' => 'Status alterado',
                'description' => 'Pedido #{id} agora está {status}.',
                'variant' => 'info',
            ],
            'notified' => [
                'title' => 'Cliente notificado',
                'description' => 'O cliente foi avisado via WhatsApp.',
                'variant' => 'success',
            ],
            'completed' => [
                'title' => 'Venda concluída!',
                'description' => 'Pedido #{id} foi finalizado.',
                'variant' => 'success',
            ],
            'cancelled' => [
                'title' => 'Pedido cancelado',
                'description' => 'Pedido #{id} foi cancelado.',
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
            'permission' => 'pedidos.view-stats',
            'cards' => [
                ['id' => 'total', 'label' => 'Total', 'icon' => 'Package', 'color' => 'blue'],
                ['id' => 'pending', 'label' => 'Pendentes', 'icon' => 'Clock', 'color' => 'yellow'],
                ['id' => 'available', 'label' => 'Disponíveis', 'icon' => 'Store', 'color' => 'green'],
                ['id' => 'completed', 'label' => 'Concluídos', 'icon' => 'CheckCircle', 'color' => 'emerald'],
            ],
        ];
    }
}
