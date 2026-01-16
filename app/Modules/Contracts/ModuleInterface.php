<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

/**
 * Interface that all modules must implement.
 *
 * A module is a self-contained package that includes:
 * - Status definitions and workflows
 * - Permissions (abilities, screens, features)
 * - Conditional fields per status
 * - Automations (scheduled jobs)
 */
interface ModuleInterface
{
    // ========================================
    // Identification
    // ========================================

    /**
     * Unique module identifier (slug).
     * Example: 'pedidos-simples'
     */
    public function getId(): string;

    /**
     * Human-readable module name.
     * Example: 'Pedidos Simples'
     */
    public function getName(): string;

    /**
     * Module description.
     */
    public function getDescription(): string;

    /**
     * Module version (semver).
     * Example: '1.0.0'
     */
    public function getVersion(): string;

    /**
     * Lucide icon name for UI.
     * Example: 'FileCheck'
     */
    public function getIcon(): string;

    // ========================================
    // Dependencies
    // ========================================

    /**
     * Other modules this module depends on.
     * Example: ['customers', 'payment-methods']
     *
     * @return string[]
     */
    public function getDependencies(): array;

    // ========================================
    // Status & Workflow
    // ========================================

    /**
     * Get all statuses for this module.
     *
     * @return array<int, array{name: string, label: string, color: string, icon?: string, final: bool}>
     */
    public function getStatuses(): array;

    /**
     * Get allowed transitions between statuses.
     *
     * @return array<int, int[]> Map of from_status => [to_status, ...]
     */
    public function getTransitions(): array;

    /**
     * Get the role matrix for status transitions.
     *
     * @return array<int, array<int, string[]>> [from][to] => roles[]
     */
    public function getTransitionRoleMatrix(): array;

    // ========================================
    // Permissions
    // ========================================

    /**
     * Get all permissions defined by this module.
     *
     * @return array<int, array{name: string, display_name: string, type: string, description?: string}>
     */
    public function getPermissions(): array;

    /**
     * Get screen permissions for this module.
     *
     * @return array<int, array{name: string, display_name: string, path?: string}>
     */
    public function getScreens(): array;

    // ========================================
    // UI Texts & Labels
    // ========================================

    /**
     * Get UI texts for the module.
     * Includes menu labels, tooltips, page titles, empty states, loading/error texts.
     *
     * @return array{
     *   menu_label: string,
     *   menu_tooltip: string,
     *   page_title: string,
     *   page_description: string,
     *   create_button: string,
     *   empty_state: string,
     *   loading_title?: string,
     *   loading_description?: string,
     *   error_title?: string,
     *   error_description?: string,
     *   retry_button?: string,
     *   filters?: array
     * }
     */
    public function getTexts(): array;

    // ========================================
    // Actions
    // ========================================

    /**
     * Get available actions for this module.
     * Each action includes label, icon, tooltip, shortcuts, permissions.
     *
     * @return array<string, array{
     *   label: string,
     *   icon: string,
     *   tooltip: string,
     *   shortcut?: string,
     *   shortcut_modifier?: string,
     *   permission?: string,
     *   confirm?: bool,
     *   confirm_title?: string,
     *   confirm_message?: string,
     *   confirm_button?: string,
     *   cancel_button?: string,
     *   confirm_variant?: string,
     *   available_in_status?: int[],
     *   requires_fields?: string[]
     * }>
     */
    public function getActions(): array;

    // ========================================
    // Permission Groups
    // ========================================

    /**
     * Get permissions organized by groups for UI.
     *
     * @return array<string, array{
     *   label: string,
     *   icon: string,
     *   description?: string,
     *   permissions: string[]
     * }>
     */
    public function getPermissionGroups(): array;

    // ========================================
    // Documentation
    // ========================================

    /**
     * Get module documentation for help section.
     *
     * @return array{
     *   overview: string,
     *   workflow?: array{title: string, steps: string[]},
     *   roles?: array<string, string>,
     *   faq?: array<string, string>
     * }
     */
    public function getDocumentation(): array;

    // ========================================
    // Filters
    // ========================================

    /**
     * Get available filters for the list view.
     *
     * @return array<string, array{
     *   type: string,
     *   label: string,
     *   options?: string|array,
     *   presets?: string[]
     * }>
     */
    public function getFilters(): array;

    // ========================================
    // Table Configuration
    // ========================================

    /**
     * Get table column configuration.
     *
     * @return array<string, array>
     */
    public function getTableColumns(): array;

    // ========================================
    // Bulk Actions
    // ========================================

    /**
     * Get available bulk actions for selected items.
     *
     * @return array<string, array{
     *   label: string,
     *   icon: string,
     *   permission?: string,
     *   confirm?: bool,
     *   confirm_message?: string
     * }>
     */
    public function getBulkActions(): array;

    // ========================================
    // Row Actions
    // ========================================

    /**
     * Get quick actions for table rows.
     *
     * @return array{
     *   primary?: array,
     *   secondary?: array
     * }
     */
    public function getRowActions(): array;

    // ========================================
    // Notifications
    // ========================================

    /**
     * Get notification templates for CRUD operations.
     *
     * @return array<string, array{
     *   title: string,
     *   description: string,
     *   variant: string
     * }>
     */
    public function getNotifications(): array;

    // ========================================
    // Stats Cards
    // ========================================

    /**
     * Get stats cards configuration for dashboard.
     *
     * @return array{
     *   enabled: bool,
     *   permission?: string,
     *   cards?: array
     * }
     */
    public function getStatsCards(): array;

    // ========================================
    // Conditional Fields
    // ========================================

    /**
     * Get fields that are required/shown based on status.
     *
     * @return array<string, array<string, array{type: string, required: bool, options?: array}>>
     */
    public function getConditionalFields(): array;

    // ========================================
    // Automations
    // ========================================

    /**
     * Get automated actions for this module.
     *
     * @return array<int, array{name: string, trigger: string, config: array}>
     */
    public function getAutomations(): array;

    // ========================================
    // Lifecycle Hooks
    // ========================================

    /**
     * Called when module is installed.
     */
    public function onInstall(): void;

    /**
     * Called when module is uninstalled.
     */
    public function onUninstall(): void;

    /**
     * Called when module is activated for a specific store.
     */
    public function onActivate(int $storeId): void;

    /**
     * Called when module is deactivated for a specific store.
     */
    public function onDeactivate(int $storeId): void;

    // ========================================
    // Transition Validation
    // ========================================

    /**
     * Check if a status transition is allowed.
     */
    public function canTransition(int $fromStatus, int $toStatus): bool;

    /**
     * Check if a user can perform a specific transition.
     *
     * @param string[] $userRoles
     */
    public function canUserTransition(int $fromStatus, int $toStatus, array $userRoles): bool;

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Get status by ID.
     */
    public function getStatus(int $statusId): ?array;

    /**
     * Get allowed transitions from a specific status.
     *
     * @return int[]
     */
    public function getAllowedTransitionsFrom(int $statusId): array;

    /**
     * Convert module to array for API responses.
     */
    public function toArray(): array;
}
