<?php

declare(strict_types=1);

namespace App\Modules\CatalogoAparelhos;

use App\Modules\BaseModule;

/**
 * Module: CatalogoAparelhos
 *
 * Gerenciamento de Catálogo de Aparelhos (Marcas e Modelos de Telefone).
 * Este é um módulo de dados de referência, sem fluxo de status.
 */
class CatalogoAparelhosModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'catalogo-aparelhos';
    }

    public function getName(): string
    {
        return 'Catálogo de Aparelhos';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento de marcas e modelos de telefone. Dados de referência usados pelos módulos de pedidos e capas personalizadas.';
    }

    public function getIcon(): string
    {
        return 'Smartphone';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // ========================================
    // Statuses (Not applicable for this module)
    // ========================================

    public function getStatuses(): array
    {
        // Catálogo de aparelhos não tem workflow de status
        // É um módulo de dados de referência (CRUD puro)
        return [];
    }

    // ========================================
    // Transitions (Not applicable for this module)
    // ========================================

    public function getTransitions(): array
    {
        return [];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => 'phone_catalog.view', 'display_name' => 'Ver Catálogo de Aparelhos', 'type' => 'ability'],
            ['name' => 'phone_catalog.create', 'display_name' => 'Criar Marcas e Modelos', 'type' => 'ability'],
            ['name' => 'phone_catalog.update', 'display_name' => 'Editar Marcas e Modelos', 'type' => 'ability'],
            ['name' => 'phone_catalog.delete', 'display_name' => 'Excluir Marcas e Modelos', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'create_brand' => [
                'label' => 'Nova Marca',
                'icon' => 'Plus',
                'permission' => 'phone_catalog.create',
            ],
            'create_model' => [
                'label' => 'Novo Modelo',
                'icon' => 'Plus',
                'permission' => 'phone_catalog.create',
            ],
        ];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'Catálogo de Aparelhos',
            'menu_tooltip' => 'Gerenciar marcas e modelos de telefone',
            'page_title' => 'Catálogo de Aparelhos',
            'page_description' => 'Gerencie as marcas e modelos de telefone disponíveis no sistema',
            'brands_title' => 'Marcas',
            'models_title' => 'Modelos',
            'create_brand_button' => 'Nova Marca',
            'create_model_button' => 'Novo Modelo',
            'empty_brands' => 'Nenhuma marca cadastrada.',
            'empty_models' => 'Nenhum modelo cadastrado.',
        ];
    }

    // ========================================
    // Screens
    // ========================================

    public function getScreens(): array
    {
        return [
            ['name' => 'phone_catalog.list', 'display_name' => 'Lista de Aparelhos', 'path' => '/phone-catalog'],
            ['name' => 'phone_catalog.brands', 'display_name' => 'Gerenciar Marcas', 'path' => '/phone-catalog/brands'],
            ['name' => 'phone_catalog.models', 'display_name' => 'Gerenciar Modelos', 'path' => '/phone-catalog/models'],
        ];
    }

    // ========================================
    // Transition Role Matrix
    // ========================================

    public function getTransitionRoleMatrix(): array
    {
        // Não há workflow de status neste módulo (CRUD puro)
        return [];
    }
}
