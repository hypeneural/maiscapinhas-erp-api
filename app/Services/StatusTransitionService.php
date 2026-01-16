<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use App\Models\User;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\ModuleRegistry;

/**
 * Service for validating status transitions based on module configuration.
 */
class StatusTransitionService
{
    public function __construct(
        private PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Check if a status transition is allowed for a user.
     */
    public function canTransition(
        User $user,
        string $moduleId,
        int $fromStatus,
        int $toStatus,
        ?int $storeId = null
    ): bool {
        // Super admin bypasses all checks
        if ($user->is_super_admin) {
            return true;
        }

        // Get module instance
        $module = $this->getModule($moduleId);
        if (!$module) {
            return false;
        }

        // Check if transition is allowed at all
        if (!$module->canTransition($fromStatus, $toStatus)) {
            return false;
        }

        // Get role matrix (with DB overrides if any)
        $matrix = $this->getTransitionRoleMatrix($moduleId, $module);

        // Get allowed roles for this transition
        $allowedRoles = $matrix[$fromStatus][$toStatus] ?? [];

        if (empty($allowedRoles)) {
            return false;
        }

        // Get user's roles for this context
        $userRoles = $this->getUserRoles($user, $storeId);

        // Check if user has any allowed role
        return !empty(array_intersect($userRoles, $allowedRoles));
    }

    /**
     * Get allowed transitions for a user from a specific status.
     *
     * @return int[] Array of status IDs the user can transition to
     */
    public function getAllowedTransitions(
        User $user,
        string $moduleId,
        int $currentStatus,
        ?int $storeId = null
    ): array {
        $module = $this->getModule($moduleId);
        if (!$module) {
            return [];
        }

        // Super admin can do any defined transition
        if ($user->is_super_admin) {
            return $module->getAllowedTransitionsFrom($currentStatus);
        }

        $possibleTransitions = $module->getAllowedTransitionsFrom($currentStatus);
        $matrix = $this->getTransitionRoleMatrix($moduleId, $module);
        $userRoles = $this->getUserRoles($user, $storeId);

        $allowed = [];
        foreach ($possibleTransitions as $toStatus) {
            $allowedRoles = $matrix[$currentStatus][$toStatus] ?? [];
            if (!empty(array_intersect($userRoles, $allowedRoles))) {
                $allowed[] = $toStatus;
            }
        }

        return $allowed;
    }

    /**
     * Get status info with transition options for a user.
     */
    public function getStatusInfo(
        User $user,
        string $moduleId,
        int $currentStatus,
        ?int $storeId = null
    ): array {
        $module = $this->getModule($moduleId);
        if (!$module) {
            return [];
        }

        $status = $module->getStatus($currentStatus);
        $allowedTransitions = $this->getAllowedTransitions($user, $moduleId, $currentStatus, $storeId);

        return [
            'current' => [
                'id' => $currentStatus,
                'name' => $status['name'] ?? null,
                'label' => $status['label'] ?? 'Desconhecido',
                'color' => $status['color'] ?? 'gray',
                'is_final' => $status['final'] ?? false,
            ],
            'can_transition_to' => array_map(function ($statusId) use ($module) {
                $s = $module->getStatus($statusId);
                return [
                    'id' => $statusId,
                    'name' => $s['name'] ?? null,
                    'label' => $s['label'] ?? 'Desconhecido',
                    'color' => $s['color'] ?? 'gray',
                ];
            }, $allowedTransitions),
        ];
    }

    /**
     * Get the module instance from registry.
     */
    private function getModule(string $moduleId): ?ModuleInterface
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        return $registry->get($moduleId);
    }

    /**
     * Get transition role matrix with any DB overrides.
     */
    private function getTransitionRoleMatrix(string $moduleId, ModuleInterface $module): array
    {
        $dbModule = Module::find($moduleId);

        if ($dbModule && $dbModule->transition_overrides) {
            return $dbModule->getTransitionRoleMatrix();
        }

        return $module->getTransitionRoleMatrix();
    }

    /**
     * Get user's role names for a given store context.
     *
     * @return string[]
     */
    private function getUserRoles(User $user, ?int $storeId): array
    {
        // Get Spatie roles
        $roles = $user->getRoleNames()->toArray();

        // If store context, also check store-specific role
        if ($storeId) {
            $storeUser = $user->storeUsers()->where('store_id', $storeId)->first();
            if ($storeUser && $storeUser->role) {
                $roles[] = $storeUser->role;
            }
        }

        return array_unique($roles);
    }
}
