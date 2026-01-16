<?php

declare(strict_types=1);

namespace App\Modules\ConferenciaCaixa;

use App\Modules\BaseModule;

/**
 * Module: ConferenciaCaixa
 *
 * Gerenciamento de turnos de caixa e conferência de fechamento.
 * Workflow: draft → submitted → approved/rejected
 */
class ConferenciaCaixaModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'conferencia-caixa';
    }

    public function getName(): string
    {
        return 'Conferência de Caixa';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento de turnos de caixa, fechamentos e conferência. Workflow de aprovação para controle financeiro.';
    }

    public function getIcon(): string
    {
        return 'Calculator';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // ========================================
    // Statuses (based on CashClosingStatus enum)
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
                'name' => 'submitted',
                'label' => 'Enviado',
                'color' => 'yellow',
                'icon' => 'Send',
                'final' => false,
            ],
            3 => [
                'name' => 'approved',
                'label' => 'Aprovado',
                'color' => 'green',
                'icon' => 'CheckCircle',
                'final' => true,
            ],
            4 => [
                'name' => 'rejected',
                'label' => 'Rejeitado',
                'color' => 'red',
                'icon' => 'XCircle',
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
            1 => [2],       // draft → submitted
            2 => [3, 4],    // submitted → approved, rejected
            3 => [],        // approved → (final)
            4 => [1],       // rejected → draft (for correction)
        ];
    }

    // ========================================
    // Transition Role Matrix
    // ========================================

    public function getTransitionRoleMatrix(): array
    {
        return [
            1 => [  // draft →
                2 => ['vendedor', 'gerente', 'admin', 'conferente'],  // → submitted (enviar)
            ],
            2 => [  // submitted →
                3 => ['conferente', 'gerente', 'admin'],  // → approved
                4 => ['conferente', 'gerente', 'admin'],  // → rejected
            ],
            4 => [  // rejected →
                1 => ['vendedor', 'gerente', 'admin'],    // → draft (corrigir)
            ],
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => 'caixa.view', 'display_name' => 'Ver Turnos e Fechamentos', 'type' => 'ability'],
            ['name' => 'caixa.shift.open', 'display_name' => 'Abrir Turno', 'type' => 'ability'],
            ['name' => 'caixa.closing.create', 'display_name' => 'Criar/Editar Fechamento', 'type' => 'ability'],
            ['name' => 'caixa.closing.approve', 'display_name' => 'Aprovar Fechamento', 'type' => 'ability'],
            ['name' => 'caixa.closing.reject', 'display_name' => 'Rejeitar Fechamento', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Screens
    // ========================================

    public function getScreens(): array
    {
        return [
            ['name' => 'caixa.shifts', 'display_name' => 'Turnos de Caixa', 'path' => '/cash/shifts'],
            ['name' => 'caixa.pending', 'display_name' => 'Pendentes de Conferência', 'path' => '/cash/pending'],
            ['name' => 'caixa.divergent', 'display_name' => 'Divergências', 'path' => '/cash/divergent'],
            ['name' => 'caixa.closing', 'display_name' => 'Fechamento', 'path' => '/cash/closing/:id'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'open_shift' => [
                'label' => 'Abrir Turno',
                'icon' => 'Play',
                'permission' => 'caixa.shift.open',
            ],
            'submit' => [
                'label' => 'Enviar para Conferência',
                'icon' => 'Send',
                'permission' => 'caixa.closing.create',
                'confirm' => true,
                'confirm_title' => 'Enviar Fechamento?',
                'confirm_message' => 'O fechamento será enviado para conferência.',
            ],
            'approve' => [
                'label' => 'Aprovar',
                'icon' => 'Check',
                'permission' => 'caixa.closing.approve',
                'confirm' => true,
                'confirm_title' => 'Aprovar Fechamento?',
                'confirm_message' => 'Esta ação é irreversível.',
            ],
            'reject' => [
                'label' => 'Rejeitar',
                'icon' => 'X',
                'permission' => 'caixa.closing.reject',
                'confirm' => true,
                'confirm_title' => 'Rejeitar Fechamento?',
                'confirm_message' => 'Informe o motivo da rejeição.',
                'requires_input' => true,
                'input_label' => 'Motivo',
            ],
        ];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Caixa',
            'menu_tooltip' => 'Gerenciar turnos e fechamentos de caixa',
            'page_title' => 'Conferência de Caixa',
            'page_description' => 'Gerencie turnos e aprove fechamentos de caixa',
            'shifts_title' => 'Turnos',
            'closings_title' => 'Fechamentos',
            'pending_title' => 'Pendentes',
            'divergent_title' => 'Divergências',
            'empty_shifts' => 'Nenhum turno encontrado.',
            'empty_pending' => 'Nenhum fechamento pendente.',
        ];
    }

    // ========================================
    // Permission Groups
    // ========================================

    public function getPermissionGroups(): array
    {
        return [
            'shifts' => [
                'label' => 'Turnos',
                'icon' => 'Clock',
                'description' => 'Gerenciar turnos de caixa',
                'permissions' => [
                    'caixa.view',
                    'caixa.shift.open',
                ],
            ],
            'closings' => [
                'label' => 'Fechamentos',
                'icon' => 'CheckSquare',
                'description' => 'Criar e conferir fechamentos',
                'permissions' => [
                    'caixa.closing.create',
                    'caixa.closing.approve',
                    'caixa.closing.reject',
                ],
            ],
        ];
    }
}
