<?php

declare(strict_types=1);

namespace App\Modules\Traits;

/**
 * Trait HasPermissions
 *
 * Auto-generates CRUD permissions based on module ID.
 * Also supports custom permissions and screen-based permissions.
 *
 * Usage:
 *   use HasPermissions;
 *   
 *   // Optionally define custom permissions:
 *   protected array $customPermissions = [
 *       ['name' => 'module.export', 'display_name' => 'Exportar'],
 *   ];
 */
trait HasPermissions
{
    /**
     * Get auto-generated CRUD permissions + custom ones.
     */
    public function getPermissions(): array
    {
        $moduleId = $this->getId();
        $moduleName = $this->getName();
        $singular = $this->getSingularName();

        // Base CRUD permissions
        $permissions = [
            [
                'name' => "{$moduleId}.view",
                'display_name' => "Ver {$moduleName}",
                'type' => 'ability',
                'description' => "Visualizar lista de {$moduleName}",
            ],
            [
                'name' => "{$moduleId}.create",
                'display_name' => "Criar {$singular}",
                'type' => 'ability',
                'description' => "Criar novo registro em {$moduleName}",
            ],
            [
                'name' => "{$moduleId}.update",
                'display_name' => "Editar {$singular}",
                'type' => 'ability',
                'description' => "Atualizar registros em {$moduleName}",
            ],
            [
                'name' => "{$moduleId}.delete",
                'display_name' => "Excluir {$singular}",
                'type' => 'ability',
                'description' => "Excluir registros de {$moduleName}",
            ],
        ];

        // Add status transition permissions if using HasStatuses
        if (method_exists($this, 'getStatuses')) {
            foreach ($this->getStatuses() as $key => $status) {
                $permissions[] = [
                    'name' => "{$moduleId}.status.{$key}",
                    'display_name' => "Mover para {$status['label']}",
                    'type' => 'ability',
                    'description' => "Alterar status para {$status['label']}",
                ];
            }
        }

        // Add custom permissions
        if (property_exists($this, 'customPermissions')) {
            $permissions = array_merge($permissions, $this->customPermissions);
        }

        return $permissions;
    }

    /**
     * Get singular name for permission labels.
     */
    protected function getSingularName(): string
    {
        $name = $this->getName();
        // Simple Portuguese singular
        if (str_ends_with($name, 's')) {
            return rtrim($name, 's');
        }
        return $name;
    }

    /**
     * Check if user has permission for this module.
     */
    public function userHasPermission(string $permission, $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (!$user)
            return false;

        $fullPermission = "{$this->getId()}.{$permission}";

        // Check if user has the permission
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo($fullPermission);
        }

        // Fallback: super-admin always has access
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('super-admin');
        }

        return false;
    }

    /**
     * Get default role matrix for transitions.
     */
    public function getTransitionRoleMatrix(): array
    {
        if (!method_exists($this, 'getTransitions')) {
            return [];
        }

        $matrix = [];
        $defaultRoles = ['admin', 'super-admin'];

        foreach ($this->getTransitions() as $from => $toList) {
            $matrix[$from] = [];
            foreach ($toList as $to) {
                $matrix[$from][$to] = $defaultRoles;
            }
        }

        return $matrix;
    }
}
