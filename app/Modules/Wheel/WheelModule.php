<?php

declare(strict_types=1);

namespace App\Modules\Wheel;

use App\Modules\BaseModule;

/**
 * Module: Wheel (Roleta nas TVs)
 *
 * Módulo administrativo para gerenciamento do sistema de Roleta nas TVs.
 * Permite configurar screens (TVs), campanhas, prêmios, segmentos e inventário.
 * 
 * Acesso restrito a Super Admin.
 */
class WheelModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'wheel';
    }

    public function getName(): string
    {
        return 'Roleta nas TVs';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento do sistema de roleta interativa exibida nas TVs das vitrines. Permite configurar campanhas, prêmios, segmentos e monitorar a saúde das TVs.';
    }

    public function getIcon(): string
    {
        return 'Target';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // ========================================
    // Statuses (para campanhas)
    // ========================================

    public function getStatuses(): array
    {
        return [
            1 => [
                'name' => 'draft',
                'label' => 'Rascunho',
                'color' => 'gray',
                'icon' => 'FileEdit',
                'final' => false,
            ],
            2 => [
                'name' => 'active',
                'label' => 'Ativa',
                'color' => 'green',
                'icon' => 'Play',
                'final' => false,
            ],
            3 => [
                'name' => 'paused',
                'label' => 'Pausada',
                'color' => 'yellow',
                'icon' => 'Pause',
                'final' => false,
            ],
            4 => [
                'name' => 'ended',
                'label' => 'Encerrada',
                'color' => 'red',
                'icon' => 'Square',
                'final' => true,
            ],
        ];
    }

    public function getTransitions(): array
    {
        return [
            1 => [2],      // draft → active
            2 => [3, 4],   // active → paused, ended
            3 => [2, 4],   // paused → active, ended
            4 => [],       // ended (final)
        ];
    }

    public function getTransitionRoleMatrix(): array
    {
        return [
            1 => [2 => ['super_admin']],
            2 => [
                3 => ['super_admin'],
                4 => ['super_admin'],
            ],
            3 => [
                2 => ['super_admin'],
                4 => ['super_admin'],
            ],
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            // Geral
            ['name' => 'wheel.admin', 'display_name' => 'Acesso ao Módulo Roleta', 'type' => 'ability'],

            // Screens (TVs)
            ['name' => 'wheel.screens.view', 'display_name' => 'Ver TVs', 'type' => 'ability'],
            ['name' => 'wheel.screens.manage', 'display_name' => 'Gerenciar TVs', 'type' => 'ability'],

            // Campaigns
            ['name' => 'wheel.campaigns.view', 'display_name' => 'Ver Campanhas', 'type' => 'ability'],
            ['name' => 'wheel.campaigns.manage', 'display_name' => 'Gerenciar Campanhas', 'type' => 'ability'],

            // Prizes
            ['name' => 'wheel.prizes.view', 'display_name' => 'Ver Prêmios', 'type' => 'ability'],
            ['name' => 'wheel.prizes.manage', 'display_name' => 'Gerenciar Prêmios', 'type' => 'ability'],

            // Inventory
            ['name' => 'wheel.inventory.manage', 'display_name' => 'Gerenciar Estoque', 'type' => 'ability'],

            // Analytics & Logs
            ['name' => 'wheel.analytics.view', 'display_name' => 'Ver Analytics', 'type' => 'ability'],
            ['name' => 'wheel.logs.view', 'display_name' => 'Ver Logs', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Screens
    // ========================================

    public function getScreens(): array
    {
        return [
            ['name' => 'wheel.dashboard', 'display_name' => 'Dashboard Roleta', 'path' => '/admin/wheel'],
            ['name' => 'wheel.screens.list', 'display_name' => 'Lista de TVs', 'path' => '/admin/wheel/screens'],
            ['name' => 'wheel.screens.detail', 'display_name' => 'Detalhes da TV', 'path' => '/admin/wheel/screens/:id'],
            ['name' => 'wheel.campaigns.list', 'display_name' => 'Lista de Campanhas', 'path' => '/admin/wheel/campaigns'],
            ['name' => 'wheel.campaigns.detail', 'display_name' => 'Detalhes da Campanha', 'path' => '/admin/wheel/campaigns/:id'],
            ['name' => 'wheel.campaigns.segments', 'display_name' => 'Configurar Roleta', 'path' => '/admin/wheel/campaigns/:id/segments'],
            ['name' => 'wheel.prizes.list', 'display_name' => 'Lista de Prêmios', 'path' => '/admin/wheel/prizes'],
            ['name' => 'wheel.logs', 'display_name' => 'Logs de Eventos', 'path' => '/admin/wheel/logs'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            // Screen actions
            'rotate_secret' => [
                'label' => 'Gerar Novo Token',
                'icon' => 'RefreshCw',
                'permission' => 'wheel.screens.manage',
                'confirm' => true,
                'confirm_title' => 'Gerar novo token?',
                'confirm_message' => 'O token atual será invalidado. A TV precisará ser reconfigurada.',
                'confirm_variant' => 'warning',
            ],
            'set_maintenance' => [
                'label' => 'Colocar em Manutenção',
                'icon' => 'Wrench',
                'permission' => 'wheel.screens.manage',
            ],

            // Campaign actions
            'activate_campaign' => [
                'label' => 'Ativar Campanha',
                'icon' => 'Play',
                'permission' => 'wheel.campaigns.manage',
                'confirm' => true,
                'confirm_title' => 'Ativar campanha?',
                'confirm_message' => 'A campanha será ativada e ficará disponível nas TVs vinculadas.',
            ],
            'pause_campaign' => [
                'label' => 'Pausar Campanha',
                'icon' => 'Pause',
                'permission' => 'wheel.campaigns.manage',
            ],
            'end_campaign' => [
                'label' => 'Encerrar Campanha',
                'icon' => 'Square',
                'permission' => 'wheel.campaigns.manage',
                'confirm' => true,
                'confirm_title' => 'Encerrar campanha?',
                'confirm_message' => 'Esta ação não pode ser desfeita. A campanha será encerrada permanentemente.',
                'confirm_variant' => 'destructive',
            ],

            // Prize actions
            'toggle_prize' => [
                'label' => 'Ativar/Desativar',
                'icon' => 'ToggleLeft',
                'permission' => 'wheel.prizes.manage',
            ],

            // Inventory actions
            'add_stock' => [
                'label' => 'Adicionar Estoque',
                'icon' => 'Plus',
                'permission' => 'wheel.inventory.manage',
            ],
            'reset_daily' => [
                'label' => 'Resetar Limite Diário',
                'icon' => 'RotateCcw',
                'permission' => 'wheel.inventory.manage',
            ],
        ];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Roleta nas TVs',
            'menu_tooltip' => 'Gerenciar sistema de roleta interativa',
            'page_title' => 'Roleta nas TVs',
            'page_description' => 'Configure campanhas, prêmios e monitore as TVs',
            'create_button' => 'Nova Campanha',
            'empty_state' => 'Nenhuma campanha criada ainda.',

            // Custom
            'screen_online' => 'Online',
            'screen_offline' => 'Offline',
            'screen_maintenance' => 'Em Manutenção',
            'campaign_draft' => 'Rascunho',
            'campaign_active' => 'Ativa',
            'campaign_paused' => 'Pausada',
            'campaign_ended' => 'Encerrada',
        ];
    }

    // ========================================
    // Permission Groups
    // ========================================

    public function getPermissionGroups(): array
    {
        return [
            'screens' => [
                'label' => 'TVs / Totens',
                'icon' => 'Monitor',
                'description' => 'Gerenciar TVs que exibem a roleta',
                'permissions' => [
                    'wheel.screens.view',
                    'wheel.screens.manage',
                ],
            ],
            'campaigns' => [
                'label' => 'Campanhas',
                'icon' => 'Target',
                'description' => 'Criar e gerenciar campanhas de roleta',
                'permissions' => [
                    'wheel.campaigns.view',
                    'wheel.campaigns.manage',
                ],
            ],
            'prizes' => [
                'label' => 'Prêmios',
                'icon' => 'Gift',
                'description' => 'Configurar prêmios disponíveis',
                'permissions' => [
                    'wheel.prizes.view',
                    'wheel.prizes.manage',
                ],
            ],
            'inventory' => [
                'label' => 'Estoque',
                'icon' => 'Package',
                'description' => 'Controlar limites de prêmios',
                'permissions' => [
                    'wheel.inventory.manage',
                ],
            ],
            'analytics' => [
                'label' => 'Analytics e Logs',
                'icon' => 'BarChart2',
                'description' => 'Visualizar estatísticas e auditoria',
                'permissions' => [
                    'wheel.analytics.view',
                    'wheel.logs.view',
                ],
            ],
        ];
    }

    // ========================================
    // Stats Cards (Dashboard)
    // ========================================

    public function getStatsCards(): array
    {
        return [
            'enabled' => true,
            'permission' => 'wheel.analytics.view',
            'cards' => [
                'screens_online' => [
                    'label' => 'TVs Online',
                    'icon' => 'Monitor',
                    'color' => 'green',
                    'endpoint' => '/api/v1/admin/wheel/analytics/screens-online',
                ],
                'active_campaigns' => [
                    'label' => 'Campanhas Ativas',
                    'icon' => 'Target',
                    'color' => 'blue',
                    'endpoint' => '/api/v1/admin/wheel/analytics/active-campaigns',
                ],
                'spins_today' => [
                    'label' => 'Giros Hoje',
                    'icon' => 'RotateCw',
                    'color' => 'purple',
                    'endpoint' => '/api/v1/admin/wheel/analytics/spins-today',
                ],
                'prizes_won' => [
                    'label' => 'Prêmios Ganhos',
                    'icon' => 'Gift',
                    'color' => 'yellow',
                    'endpoint' => '/api/v1/admin/wheel/analytics/prizes-won',
                ],
            ],
        ];
    }

    // ========================================
    // Documentation
    // ========================================

    public function getDocumentation(): array
    {
        return [
            'overview' => 'O módulo Roleta nas TVs permite configurar e gerenciar o sistema de roleta interativa exibido nas TVs das vitrines. Os clientes escaneiam um QR Code para participar e podem ganhar prêmios instantâneos.',
            'workflow' => [
                'title' => 'Fluxo de Configuração',
                'steps' => [
                    '1. Cadastrar uma TV no sistema e gerar o token de autenticação',
                    '2. Criar uma campanha com as configurações desejadas',
                    '3. Adicionar prêmios ao catálogo',
                    '4. Configurar os segmentos da roleta (fatias) com os pesos de probabilidade',
                    '5. Definir os limites de estoque por prêmio',
                    '6. Vincular a campanha à TV',
                    '7. Ativar a campanha para iniciar',
                ],
            ],
            'roles' => [
                'super_admin' => 'Acesso total ao módulo',
            ],
            'faq' => [
                'Como funciona o peso de probabilidade?' => 'O peso define a chance relativa de cada segmento ser sorteado. Ex: se um segmento tem peso 10 e outro peso 5, o primeiro tem o dobro de chance.',
                'Posso usar o mesmo prêmio em múltiplos segmentos?' => 'Sim, você pode ter o mesmo prêmio em várias fatias da roleta para aumentar a probabilidade.',
                'O que acontece quando o estoque acaba?' => 'O segmento é automaticamente ignorado no sorteio. Se todos os segmentos ficarem sem estoque, a roleta para de funcionar.',
            ],
        ];
    }
}
