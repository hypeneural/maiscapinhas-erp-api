<?php

declare(strict_types=1);

namespace App\Modules\WhatsAppInstances;

use App\Modules\BaseModule;

/**
 * Module: WhatsAppInstances
 *
 * Gerenciamento de Instâncias WhatsApp (Evolution API).
 * Permite criar, gerenciar e conectar instâncias para envio de mensagens.
 */
class WhatsAppInstancesModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true;

    public function getId(): string
    {
        return 'whatsapp-instances';
    }

    public function getName(): string
    {
        return 'WhatsApp Instances';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento de instâncias WhatsApp via Evolution API. Permite criar, conectar e gerenciar instâncias para envio de mensagens e notificações.';
    }

    public function getIcon(): string
    {
        return 'MessageCircle';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // ========================================
    // Statuses (Not applicable - infrastructure module)
    // ========================================

    public function getStatuses(): array
    {
        // Módulo de infraestrutura, sem workflow de status
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
            // Instance Management
            ['name' => 'whatsapp.instances.view', 'display_name' => 'Ver Instâncias WhatsApp', 'type' => 'ability'],
            ['name' => 'whatsapp.instances.create', 'display_name' => 'Criar Instâncias', 'type' => 'ability'],
            ['name' => 'whatsapp.instances.update', 'display_name' => 'Editar Instâncias', 'type' => 'ability'],
            ['name' => 'whatsapp.instances.delete', 'display_name' => 'Excluir Instâncias', 'type' => 'ability'],
            ['name' => 'whatsapp.instances.manage-secrets', 'display_name' => 'Gerenciar Secrets (API Key, Token)', 'type' => 'ability'],
            ['name' => 'whatsapp.instances.connect', 'display_name' => 'Conectar/QR Code', 'type' => 'ability'],

            // Messaging
            ['name' => 'whatsapp.messages.send', 'display_name' => 'Enviar Mensagens WhatsApp', 'type' => 'ability'],
            ['name' => 'whatsapp.numbers.check', 'display_name' => 'Verificar Números WhatsApp', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Screens
    // ========================================

    public function getScreens(): array
    {
        return [
            ['name' => 'whatsapp.instances.list', 'display_name' => 'Lista de Instâncias', 'path' => '/admin/whatsapp'],
            ['name' => 'whatsapp.instances.detail', 'display_name' => 'Detalhes da Instância', 'path' => '/admin/whatsapp/:id'],
            ['name' => 'whatsapp.instances.create', 'display_name' => 'Nova Instância', 'path' => '/admin/whatsapp/new'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'create_instance' => [
                'label' => 'Nova Instância',
                'icon' => 'Plus',
                'permission' => 'whatsapp.instances.create',
            ],
            'connect' => [
                'label' => 'Conectar',
                'icon' => 'QrCode',
                'permission' => 'whatsapp.instances.connect',
                'tooltip' => 'Obter QR Code para conexão',
            ],
            'check_state' => [
                'label' => 'Verificar Estado',
                'icon' => 'RefreshCw',
                'permission' => 'whatsapp.instances.view',
            ],
            'set_default' => [
                'label' => 'Definir Como Favorita',
                'icon' => 'Star',
                'permission' => 'whatsapp.instances.update',
            ],
            'test_connection' => [
                'label' => 'Testar Conexão',
                'icon' => 'Send',
                'permission' => 'whatsapp.instances.connect',
                'confirm' => true,
                'confirm_title' => 'Testar Conexão?',
                'confirm_message' => 'Isso enviará uma mensagem de teste.',
            ],
        ];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => 'WhatsApp',
            'menu_tooltip' => 'Gerenciar instâncias WhatsApp',
            'page_title' => 'Instâncias WhatsApp',
            'page_description' => 'Gerencie as instâncias de WhatsApp para envio de notificações',
            'create_button' => 'Nova Instância',
            'empty_state' => 'Nenhuma instância cadastrada.',

            // Custom texts
            'connected' => 'Conectado',
            'disconnected' => 'Desconectado',
            'connecting' => 'Conectando...',
            'scan_qr' => 'Escaneie o QR Code com seu WhatsApp',
            'secret_masked' => '••••••••',
        ];
    }

    // ========================================
    // Permission Groups
    // ========================================

    public function getPermissionGroups(): array
    {
        return [
            'instances' => [
                'label' => 'Gerenciamento de Instâncias',
                'icon' => 'Server',
                'description' => 'Criar, editar e conectar instâncias WhatsApp',
                'permissions' => [
                    'whatsapp.instances.view',
                    'whatsapp.instances.create',
                    'whatsapp.instances.update',
                    'whatsapp.instances.delete',
                    'whatsapp.instances.manage-secrets',
                    'whatsapp.instances.connect',
                ],
            ],
            'messaging' => [
                'label' => 'Mensagens',
                'icon' => 'MessageCircle',
                'description' => 'Enviar mensagens e verificar números',
                'permissions' => [
                    'whatsapp.messages.send',
                    'whatsapp.numbers.check',
                ],
            ],
        ];
    }
}
