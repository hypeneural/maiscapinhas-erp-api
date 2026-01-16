<?php

declare(strict_types=1);

namespace App\Modules;

use App\Modules\Contracts\ModuleInterface;

/**
 * Base module implementation with common functionality.
 *
 * Extend this class to create new modules.
 */
abstract class BaseModule implements ModuleInterface
{
    /**
     * Default version if not overridden.
     */
    protected string $version = '1.0.0';

    /**
     * Whether this is a core system module.
     */
    protected bool $isCore = false;

    // ========================================
    // Default Implementations
    // ========================================

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getConditionalFields(): array
    {
        return [];
    }

    public function getAutomations(): array
    {
        return [];
    }

    public function getTexts(): array
    {
        return [
            'menu_label' => $this->getName(),
            'menu_tooltip' => $this->getDescription(),
            'page_title' => $this->getName(),
            'page_description' => $this->getDescription(),
            'create_button' => 'Novo',
            'empty_state' => 'Nenhum item encontrado.',
            'loading_title' => 'Carregando...',
            'loading_description' => 'Aguarde enquanto buscamos os dados.',
            'error_title' => 'Erro ao carregar',
            'error_description' => 'Não foi possível carregar os dados.',
            'retry_button' => 'Tentar novamente',
            'filters' => [],
        ];
    }

    public function getActions(): array
    {
        return [];
    }

    public function getPermissionGroups(): array
    {
        // Default: one group with all permissions
        return [
            'geral' => [
                'label' => 'Geral',
                'icon' => 'Settings',
                'permissions' => $this->getPermissionNames(),
            ],
        ];
    }

    public function getDocumentation(): array
    {
        return [
            'overview' => $this->getDescription(),
        ];
    }

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

    public function getTableColumns(): array
    {
        return [
            'default' => [
                ['key' => 'id', 'label' => '#', 'sortable' => true, 'width' => 80],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'created_at', 'label' => 'Data', 'type' => 'date', 'format' => 'dd/MM/yyyy'],
            ],
        ];
    }

    public function getBulkActions(): array
    {
        return [
            'export' => [
                'label' => 'Exportar Selecionados',
                'icon' => 'Download',
                'formats' => ['xlsx', 'pdf', 'csv'],
            ],
        ];
    }

    public function getRowActions(): array
    {
        return [
            'primary' => [
                'action' => 'view',
                'label' => 'Ver Detalhes',
                'icon' => 'Eye',
            ],
            'secondary' => [
                ['action' => 'edit', 'label' => 'Editar', 'icon' => 'Edit'],
            ],
        ];
    }

    public function getNotifications(): array
    {
        return [
            'created' => [
                'title' => 'Criado com sucesso!',
                'description' => 'O item foi criado.',
                'variant' => 'success',
            ],
            'updated' => [
                'title' => 'Atualizado',
                'description' => 'As alterações foram salvas.',
                'variant' => 'success',
            ],
            'deleted' => [
                'title' => 'Excluído',
                'description' => 'O item foi removido.',
                'variant' => 'warning',
            ],
            'error' => [
                'title' => 'Erro',
                'description' => 'Não foi possível completar a ação.',
                'variant' => 'destructive',
            ],
        ];
    }

    public function getStatsCards(): array
    {
        return [
            'enabled' => false,
        ];
    }

    // ========================================
    // Lifecycle Hooks (empty defaults)
    // ========================================

    public function onInstall(): void
    {
        // Override in subclass if needed
    }

    public function onUninstall(): void
    {
        // Override in subclass if needed
    }

    public function onActivate(int $storeId): void
    {
        // Override in subclass if needed
    }

    public function onDeactivate(int $storeId): void
    {
        // Override in subclass if needed
    }

    // ========================================
    // Module Configuration
    // ========================================

    public function getConfigSchema(): array
    {
        return [
            'sections' => [
                'notifications' => [
                    'label' => 'Notificações',
                    'icon' => 'Bell',
                    'description' => 'Configurações de notificação ao cliente',
                    'fields' => [
                        'notify_on_status_change' => [
                            'type' => 'switch',
                            'label' => 'Notificar ao mudar status',
                            'hint' => 'Enviar notificação WhatsApp quando o status mudar',
                            'default' => false,
                        ],
                        'notification_channel' => [
                            'type' => 'select',
                            'label' => 'Canal de notificação',
                            'options' => [
                                'whatsapp' => 'WhatsApp',
                                'email' => 'E-mail',
                                'both' => 'Ambos',
                            ],
                            'default' => 'whatsapp',
                            'depends_on' => 'notify_on_status_change',
                        ],
                    ],
                ],
                'deadlines' => [
                    'label' => 'Prazos',
                    'icon' => 'Clock',
                    'description' => 'Alertas e prazos automáticos',
                    'fields' => [
                        'warning_after_days' => [
                            'type' => 'number',
                            'label' => 'Alertar após X dias parado',
                            'hint' => 'Número de dias sem movimentação para exibir alerta',
                            'min' => 1,
                            'max' => 60,
                            'default' => 5,
                        ],
                        'auto_cancel_days' => [
                            'type' => 'number',
                            'label' => 'Cancelar automaticamente após X dias',
                            'hint' => 'Dias para cancelamento automático (0 = desativado)',
                            'min' => 0,
                            'max' => 90,
                            'default' => 20,
                        ],
                    ],
                ],
                'requirements' => [
                    'label' => 'Requisitos',
                    'icon' => 'CheckSquare',
                    'description' => 'Campos obrigatórios e validações',
                    'fields' => [
                        'require_customer_phone' => [
                            'type' => 'switch',
                            'label' => 'Exigir telefone do cliente',
                            'hint' => 'Telefone será obrigatório para criar registro',
                            'default' => true,
                        ],
                        'require_notes' => [
                            'type' => 'switch',
                            'label' => 'Exigir observações',
                            'hint' => 'Campo de observações será obrigatório',
                            'default' => false,
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
            'notify_on_status_change' => false,
            'notification_channel' => 'whatsapp',
            'warning_after_days' => 5,
            'auto_cancel_days' => 20,
            'require_customer_phone' => true,
            'require_notes' => false,
        ];
    }

    // ========================================
    // Transition Helpers
    // ========================================

    public function canTransition(int $fromStatus, int $toStatus): bool
    {
        $transitions = $this->getTransitions();

        if (!isset($transitions[$fromStatus])) {
            return false;
        }

        return in_array($toStatus, $transitions[$fromStatus], true);
    }

    public function canUserTransition(int $fromStatus, int $toStatus, array $userRoles): bool
    {
        // First check if transition is allowed at all
        if (!$this->canTransition($fromStatus, $toStatus)) {
            return false;
        }

        // Then check role matrix
        $matrix = $this->getTransitionRoleMatrix();

        if (!isset($matrix[$fromStatus][$toStatus])) {
            return false;
        }

        $allowedRoles = $matrix[$fromStatus][$toStatus];

        // Super admin can always transition
        if (in_array('super-admin', $userRoles, true)) {
            return true;
        }

        return !empty(array_intersect($userRoles, $allowedRoles));
    }

    // ========================================
    // Status Helpers
    // ========================================

    /**
     * Get status by ID.
     */
    public function getStatus(int $statusId): ?array
    {
        $statuses = $this->getStatuses();
        return $statuses[$statusId] ?? null;
    }

    /**
     * Get status label.
     */
    public function getStatusLabel(int $statusId): string
    {
        $status = $this->getStatus($statusId);
        return $status['label'] ?? 'Desconhecido';
    }

    /**
     * Get status color.
     */
    public function getStatusColor(int $statusId): string
    {
        $status = $this->getStatus($statusId);
        return $status['color'] ?? 'gray';
    }

    /**
     * Check if status is final.
     */
    public function isStatusFinal(int $statusId): bool
    {
        $status = $this->getStatus($statusId);
        return $status['final'] ?? false;
    }

    /**
     * Get all status IDs.
     *
     * @return int[]
     */
    public function getStatusIds(): array
    {
        return array_keys($this->getStatuses());
    }

    /**
     * Get allowed transitions from a specific status.
     *
     * @return int[]
     */
    public function getAllowedTransitionsFrom(int $statusId): array
    {
        $transitions = $this->getTransitions();
        return $transitions[$statusId] ?? [];
    }

    // ========================================
    // Permission Helpers
    // ========================================

    /**
     * Get all permission names.
     *
     * @return string[]
     */
    public function getPermissionNames(): array
    {
        $permissions = $this->getPermissions();
        return array_column($permissions, 'name');
    }

    /**
     * Get all screen names.
     *
     * @return string[]
     */
    public function getScreenNames(): array
    {
        $screens = $this->getScreens();
        return array_column($screens, 'name');
    }

    // ========================================
    // Serialization
    // ========================================

    /**
     * Convert module to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'version' => $this->getVersion(),
            'icon' => $this->getIcon(),
            'is_core' => $this->isCore,
            'dependencies' => $this->getDependencies(),
            'statuses' => $this->getStatuses(),
            'transitions' => $this->getTransitions(),
            'transition_role_matrix' => $this->getTransitionRoleMatrix(),
            'permissions' => $this->getPermissions(),
            'screens' => $this->getScreens(),
            'texts' => $this->getTexts(),
            'actions' => $this->getActions(),
            'permission_groups' => $this->getPermissionGroups(),
            'documentation' => $this->getDocumentation(),
            'filters' => $this->getFilters(),
            'table_columns' => $this->getTableColumns(),
            'bulk_actions' => $this->getBulkActions(),
            'row_actions' => $this->getRowActions(),
            'notifications' => $this->getNotifications(),
            'stats_cards' => $this->getStatsCards(),
            'conditional_fields' => $this->getConditionalFields(),
            'automations' => $this->getAutomations(),
        ];
    }

    /**
     * Convert module to minimal array (for list endpoints).
     */
    public function toMinimalArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'version' => $this->getVersion(),
            'icon' => $this->getIcon(),
            'is_core' => $this->isCore,
            'status_count' => count($this->getStatuses()),
            'permission_count' => count($this->getPermissions()) + count($this->getScreens()),
            'automation_count' => count($this->getAutomations()),
        ];
    }
}
