<?php

declare(strict_types=1);

namespace App\Modules\Comemoracoes;

use App\Modules\BaseModule;

/**
 * Module: Comemoracoes
 *
 * Exibe aniversariantes (nascimento e empresa) do mês.
 */
class ComemoracoesModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'comemoracoes';
    }

    public function getName(): string
    {
        return 'Comemorações';
    }

    public function getDescription(): string
    {
        return 'Exibe aniversariantes de nascimento e de empresa do mês, com countdown e destaques.';
    }

    public function getIcon(): string
    {
        return 'Cake';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // No workflow statuses for this module
    public function getStatuses(): array
    {
        return [];
    }

    public function getTransitions(): array
    {
        return [];
    }

    public function getTransitionRoleMatrix(): array
    {
        return [];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => 'celebrations.view', 'display_name' => 'Ver Comemorações', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Screens
    // ========================================

    public function getScreens(): array
    {
        return [
            ['name' => 'celebrations.month', 'display_name' => 'Aniversariantes do Mês', 'path' => '/celebrations'],
            ['name' => 'celebrations.widget', 'display_name' => 'Widget Dashboard', 'path' => '/dashboard'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Comemorações',
            'menu_tooltip' => 'Aniversariantes do mês',
            'page_title' => 'Comemorações',
            'birthday_label' => 'Aniversário',
            'work_anniversary_label' => 'Aniversário de Empresa',
            'today_title' => 'Comemorando Hoje',
            'upcoming_title' => 'Próximos Aniversários',
            'empty_message' => 'Nenhum aniversariante este mês.',
        ];
    }

    public function getPermissionGroups(): array
    {
        return [];
    }
}
