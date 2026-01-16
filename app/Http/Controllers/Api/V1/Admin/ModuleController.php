<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin API for managing modules.
 * Super Admin only.
 *
 * @group Admin - Modules
 */
class ModuleController extends Controller
{
    /**
     * List all modules.
     */
    public function index(): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $modules = $registry->all()->map(function ($module) {
            // Check if installed in DB
            $dbModule = Module::find($module->getId());

            return [
                'id' => $module->getId(),
                'name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'icon' => $module->getIcon(),
                'dependencies' => $module->getDependencies(),
                'is_installed' => $dbModule !== null,
                'is_active' => $dbModule?->is_active ?? false,
                'is_core' => $dbModule?->is_core ?? false,
                'status_count' => count($module->getStatuses()),
                'permission_count' => count($module->getPermissions()) + count($module->getScreens()),
                'automation_count' => count($module->getAutomations()),
            ];
        });

        return response()->json([
            'data' => $modules->values(),
            'total' => $modules->count(),
        ]);
    }

    /**
     * Get module details.
     */
    public function show(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $dbModule = Module::find($moduleId);

        return response()->json([
            'data' => [
                'id' => $module->getId(),
                'name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'icon' => $module->getIcon(),
                'dependencies' => $module->getDependencies(),
                'is_installed' => $dbModule !== null,
                'is_active' => $dbModule?->is_active ?? false,
                'statuses' => $dbModule ? $dbModule->getStatuses() : $module->getStatuses(),
                'transitions' => $module->getTransitions(),
                'transition_matrix' => $dbModule ? $dbModule->getTransitionRoleMatrix() : $module->getTransitionRoleMatrix(),
                'permissions' => $module->getPermissions(),
                'screens' => $module->getScreens(),
                'texts' => $module->getTexts(),
                'actions' => $module->getActions(),
                'permission_groups' => $module->getPermissionGroups(),
                'conditional_fields' => $module->getConditionalFields(),
                'automations' => $module->getAutomations(),
            ],
        ]);
    }

    /**
     * Get full module configuration (for frontend).
     * Returns everything needed to render the module UI.
     */
    public function full(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $dbModule = Module::find($moduleId);

        // Use toArray which includes all fields
        $data = $module->toArray();

        // Add DB-specific overrides if any
        if ($dbModule) {
            $data['is_installed'] = true;
            $data['is_active'] = $dbModule->is_active;
            $data['statuses'] = $dbModule->getStatuses();
            $data['transition_role_matrix'] = $dbModule->getTransitionRoleMatrix();
        } else {
            $data['is_installed'] = false;
            $data['is_active'] = false;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Install a module.
     */
    public function install(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        // Create in database
        $dbModule = Module::updateOrCreate(
            ['id' => $moduleId],
            [
                'name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'icon' => $module->getIcon(),
                'is_core' => true, // Default for now
                'is_active' => true,
                'installed_at' => now(),
            ]
        );

        // Run install hook
        $module->onInstall();

        return response()->json([
            'message' => "Módulo '{$module->getName()}' instalado com sucesso.",
            'data' => $dbModule,
        ], 201);
    }

    /**
     * Activate module globally.
     */
    public function activate(string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $module->update(['is_active' => true]);

        return response()->json([
            'message' => "Módulo '{$module->name}' ativado.",
        ]);
    }

    /**
     * Deactivate module globally.
     */
    public function deactivate(string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        if ($module->is_core) {
            return response()->json([
                'message' => 'Módulos core não podem ser desativados.',
            ], 422);
        }

        $module->update(['is_active' => false]);

        return response()->json([
            'message' => "Módulo '{$module->name}' desativado.",
        ]);
    }

    /**
     * Get transition role matrix for a module.
     */
    public function transitions(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $dbModule = Module::find($moduleId);

        return response()->json([
            'module_id' => $moduleId,
            'statuses' => $dbModule ? $dbModule->getStatuses() : $module->getStatuses(),
            'transitions' => $module->getTransitions(),
            'role_matrix' => $dbModule ? $dbModule->getTransitionRoleMatrix() : $module->getTransitionRoleMatrix(),
        ]);
    }

    /**
     * Update transition role matrix.
     * Allows Super Admin to customize who can make which transitions.
     */
    public function updateTransitions(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validated = $request->validate([
            'transitions' => ['required', 'array'],
            'transitions.*' => ['required', 'array'],
            'transitions.*.*' => ['array'], // roles array
        ]);

        $module->update([
            'transition_overrides' => $validated['transitions'],
        ]);

        return response()->json([
            'message' => 'Matriz de transições atualizada.',
            'data' => $module->getTransitionRoleMatrix(),
        ]);
    }

    /**
     * Activate module for a specific store.
     */
    public function activateForStore(Request $request, string $moduleId, int $storeId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $module->stores()->syncWithoutDetaching([
            $storeId => [
                'is_active' => true,
                'activated_at' => now(),
            ],
        ]);

        // Run activate hook
        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $moduleInstance = $registry->get($moduleId);
        $moduleInstance?->onActivate($storeId);

        return response()->json([
            'message' => "Módulo ativado para loja #{$storeId}.",
        ]);
    }

    /**
     * Deactivate module for a specific store.
     */
    public function deactivateForStore(string $moduleId, int $storeId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $module->stores()->updateExistingPivot($storeId, [
            'is_active' => false,
        ]);

        // Run deactivate hook
        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $moduleInstance = $registry->get($moduleId);
        $moduleInstance?->onDeactivate($storeId);

        return response()->json([
            'message' => "Módulo desativado para loja #{$storeId}.",
        ]);
    }
}
