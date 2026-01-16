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
            $dbModule = Module::with('stores')->find($module->getId());

            // Count active stores
            $storesCount = $dbModule ? $dbModule->stores()->wherePivot('is_active', true)->count() : 0;

            return [
                'id' => $module->getId(),
                'slug' => $module->getId(), // alias for frontend consistency
                'name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'icon' => $module->getIcon(), // Lucide icon name (e.g., "ShoppingCart")
                'dependencies' => $module->getDependencies(),
                'is_installed' => $dbModule !== null,
                'is_active' => $dbModule?->is_active ?? false,
                'is_core' => $dbModule?->is_core ?? (method_exists($module, 'isCore') ? $module->isCore() : false),
                'status' => $this->getModuleStatus($dbModule),
                'stores_count' => $storesCount,
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
     * Get module status string.
     */
    protected function getModuleStatus(?Module $dbModule): string
    {
        if (!$dbModule) {
            return 'not_installed';
        }
        return $dbModule->is_active ? 'active' : 'inactive';
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
     * List all stores with module activation status.
     * Shows which stores have this module active/inactive.
     */
    public function stores(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $dbModule = Module::with('stores')->find($moduleId);

        // Get all stores
        $allStores = \App\Models\Store::where('active', true)->get();

        // Map stores with module status
        $stores = $allStores->map(function ($store) use ($dbModule) {
            $pivotData = $dbModule?->stores->firstWhere('id', $store->id)?->pivot;

            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'city' => $store->city,
                'is_active' => $pivotData?->is_active ?? false,
                'activated_at' => $pivotData?->activated_at,
                'config' => $pivotData?->config ?? [],
            ];
        });

        return response()->json([
            'module_id' => $moduleId,
            'module_name' => $module->getName(),
            'stores' => $stores,
            'total' => $stores->count(),
            'active_count' => $stores->where('is_active', true)->count(),
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

    /**
     * Update module texts.
     * Allows Super Admin to customize UI labels, messages, etc.
     */
    public function updateTexts(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validated = $request->validate([
            'texts' => ['required', 'array'],
            'texts.menu_label' => ['sometimes', 'string', 'max:100'],
            'texts.menu_tooltip' => ['sometimes', 'string', 'max:255'],
            'texts.page_title' => ['sometimes', 'string', 'max:100'],
            'texts.page_description' => ['sometimes', 'string', 'max:500'],
            'texts.create_button' => ['sometimes', 'string', 'max:50'],
            'texts.empty_state' => ['sometimes', 'string', 'max:255'],
            'texts.loading_title' => ['sometimes', 'string', 'max:100'],
            'texts.loading_description' => ['sometimes', 'string', 'max:255'],
            'texts.error_title' => ['sometimes', 'string', 'max:100'],
            'texts.error_description' => ['sometimes', 'string', 'max:255'],
            'texts.retry_button' => ['sometimes', 'string', 'max:50'],
        ]);

        // Merge with existing overrides
        $currentOverrides = $module->text_overrides ?? [];
        $newOverrides = array_merge($currentOverrides, $validated['texts']);

        $module->update([
            'text_overrides' => $newOverrides,
        ]);

        // Log the change
        $this->logAudit($module, 'texts_updated', $validated['texts'], auth()->user());

        // Get merged texts
        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $moduleInstance = $registry->get($moduleId);
        $baseTexts = $moduleInstance ? $moduleInstance->getTexts() : [];
        $mergedTexts = array_merge($baseTexts, $newOverrides);

        return response()->json([
            'message' => 'Textos atualizados.',
            'data' => $mergedTexts,
        ]);
    }

    /**
     * Update a specific action.
     * Allows Super Admin to customize action labels, icons, confirmations, etc.
     */
    public function updateAction(Request $request, string $moduleId, string $actionId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'icon' => ['sometimes', 'string', 'max:50'],
            'tooltip' => ['sometimes', 'string', 'max:255'],
            'shortcut' => ['sometimes', 'nullable', 'string', 'max:10'],
            'shortcut_modifier' => ['sometimes', 'nullable', 'string', 'in:ctrl,alt,shift,meta'],
            'confirm' => ['sometimes', 'boolean'],
            'confirm_title' => ['sometimes', 'string', 'max:100'],
            'confirm_message' => ['sometimes', 'string', 'max:500'],
            'confirm_button' => ['sometimes', 'string', 'max:50'],
            'cancel_button' => ['sometimes', 'string', 'max:50'],
            'confirm_variant' => ['sometimes', 'string', 'in:default,destructive,warning'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Merge with existing overrides
        $currentOverrides = $module->action_overrides ?? [];
        $currentOverrides[$actionId] = array_merge(
            $currentOverrides[$actionId] ?? [],
            $validated
        );

        $module->update([
            'action_overrides' => $currentOverrides,
        ]);

        // Log the change
        $this->logAudit($module, 'action_updated', [
            'action_id' => $actionId,
            'changes' => $validated,
        ], auth()->user());

        return response()->json([
            'message' => "Ação '{$actionId}' atualizada.",
            'data' => $currentOverrides[$actionId],
        ]);
    }

    /**
     * Create a custom action.
     * Allows Super Admin to add new actions to a module.
     */
    public function createAction(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'label' => ['required', 'string', 'max:100'],
            'icon' => ['required', 'string', 'max:50'],
            'tooltip' => ['sometimes', 'string', 'max:255'],
            'shortcut' => ['sometimes', 'nullable', 'string', 'max:10'],
            'shortcut_modifier' => ['sometimes', 'nullable', 'string', 'in:ctrl,alt,shift,meta'],
            'confirm' => ['sometimes', 'boolean'],
            'confirm_title' => ['sometimes', 'string', 'max:100'],
            'confirm_message' => ['sometimes', 'string', 'max:500'],
            'confirm_button' => ['sometimes', 'string', 'max:50'],
            'cancel_button' => ['sometimes', 'string', 'max:50'],
            'confirm_variant' => ['sometimes', 'string', 'in:default,destructive,warning'],
            'permission' => ['sometimes', 'string', 'max:100'],
            'available_in_status' => ['sometimes', 'array'],
            'available_in_status.*' => ['integer'],
            'api_endpoint' => ['sometimes', 'string', 'max:255'],
            'api_method' => ['sometimes', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
        ]);

        $actionId = $validated['id'];
        unset($validated['id']);

        // Add to custom actions
        $customActions = $module->custom_actions ?? [];
        $customActions[$actionId] = array_merge($validated, [
            'is_custom' => true,
            'created_at' => now()->toIso8601String(),
        ]);

        $module->update([
            'custom_actions' => $customActions,
        ]);

        // Log the change
        $this->logAudit($module, 'action_created', [
            'action_id' => $actionId,
            'action' => $customActions[$actionId],
        ], auth()->user());

        return response()->json([
            'message' => "Ação '{$actionId}' criada.",
            'data' => $customActions[$actionId],
        ], 201);
    }

    /**
     * Delete a custom action.
     */
    public function deleteAction(string $moduleId, string $actionId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $customActions = $module->custom_actions ?? [];

        if (!isset($customActions[$actionId])) {
            return response()->json([
                'message' => 'Ação customizada não encontrada.',
            ], 404);
        }

        unset($customActions[$actionId]);

        $module->update([
            'custom_actions' => $customActions,
        ]);

        // Log the change
        $this->logAudit($module, 'action_deleted', ['action_id' => $actionId], auth()->user());

        return response()->json([
            'message' => "Ação '{$actionId}' removida.",
        ]);
    }

    /**
     * Get module audit log.
     * Returns history of changes made to the module configuration.
     */
    public function getAuditLog(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $limit = $request->input('limit', 50);

        // Fetch from audit_log table if exists, otherwise from module's audit_log column
        $auditLog = $module->audit_log ?? [];

        // Sort by date descending
        usort($auditLog, fn($a, $b) => strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0));

        // Apply limit
        $auditLog = array_slice($auditLog, 0, $limit);

        return response()->json([
            'module_id' => $moduleId,
            'entries' => $auditLog,
            'total' => count($module->audit_log ?? []),
        ]);
    }

    /**
     * Log an audit entry.
     */
    protected function logAudit(Module $module, string $action, array $data, $user): void
    {
        $auditLog = $module->audit_log ?? [];

        $entry = [
            'action' => $action,
            'data' => $data,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'timestamp' => now()->toIso8601String(),
            'ip_address' => request()->ip(),
        ];

        // Prepend new entry
        array_unshift($auditLog, $entry);

        // Keep only last 100 entries
        $auditLog = array_slice($auditLog, 0, 100);

        $module->update(['audit_log' => $auditLog]);
    }
}
