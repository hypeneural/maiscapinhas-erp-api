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

        $limit = (int) $request->input('limit', 50);

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

    // ========================================
    // Module Configuration (CRUD)
    // ========================================

    /**
     * Get module config with schema.
     *
     * Returns the current configuration values, default values, and a dynamic schema
     * that describes each configurable field. The schema is organized in sections
     * (e.g., notifications, deadlines, requirements) with field types and validation rules.
     *
     * **Schema Structure:**
     * - `sections`: Grouped configuration fields by category
     * - `sections.{key}.fields`: Individual field definitions with type, label, hint, options
     * - `defaults`: Default values for all fields
     *
     * **Field Types:**
     * - `switch`: Boolean toggle
     * - `number`: Integer with min/max
     * - `select`: Dropdown with predefined options
     * - `text`/`textarea`: String input
     *
     * **Conditional Fields:**
     * Fields with `depends_on` should only be shown when the referenced field is true.
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     *
     * @response 200 scenario="success" {
     *   "module_id": "pedidos-simples",
     *   "module_name": "Pedidos Simples",
     *   "config": {
     *     "notify_on_status_change": false,
     *     "notification_channel": "whatsapp",
     *     "warning_after_days": 5,
     *     "auto_cancel_days": 20,
     *     "require_customer_phone": true,
     *     "require_notes": false
     *   },
     *   "schema": {
     *     "sections": {
     *       "notifications": {
     *         "label": "Notificações",
     *         "icon": "Bell",
     *         "description": "Configurações de notificação ao cliente",
     *         "fields": {
     *           "notify_on_status_change": {
     *             "type": "switch",
     *             "label": "Notificar ao mudar status",
     *             "hint": "Enviar notificação WhatsApp quando o status mudar",
     *             "default": false
     *           },
     *           "notification_channel": {
     *             "type": "select",
     *             "label": "Canal de notificação",
     *             "options": {"whatsapp": "WhatsApp", "email": "E-mail", "both": "Ambos"},
     *             "default": "whatsapp",
     *             "depends_on": "notify_on_status_change"
     *           }
     *         }
     *       }
     *     },
     *     "defaults": {}
     *   },
     *   "has_custom_config": false
     * }
     * @response 404 scenario="module not found" {"message": "Módulo não encontrado."}
     *
     * @responseField module_id string The module identifier.
     * @responseField module_name string Human-readable module name.
     * @responseField config object Current merged configuration (defaults + custom).
     * @responseField schema object Dynamic schema for UI rendering with sections and field definitions.
     * @responseField has_custom_config boolean Whether any custom config has been saved.
     */
    public function getConfig(string $moduleId): JsonResponse
    {
        $dbModule = Module::find($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $schema = $module->getConfigSchema();
        $defaults = $module->getDefaultConfig();
        $currentConfig = $dbModule?->config ?? [];

        // Merge defaults with current config
        $mergedConfig = array_merge($defaults, $currentConfig);

        return response()->json([
            'module_id' => $moduleId,
            'module_name' => $module->getName(),
            'config' => $mergedConfig,
            'schema' => $schema,
            'has_custom_config' => !empty($currentConfig),
        ]);
    }

    /**
     * Update module config.
     *
     * Updates the global configuration for a module. Only fields defined in the
     * module's config schema can be updated. Validation is automatically applied
     * based on field types (switch=boolean, number=integer with min/max, etc.).
     *
     * The update is merged with existing config, so you only need to send changed fields.
     * All changes are logged in the module's audit log.
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     *
     * @bodyParam notify_on_status_change boolean Enable status change notifications. Example: true
     * @bodyParam notification_channel string Notification channel: whatsapp, email, or both. Example: whatsapp
     * @bodyParam warning_after_days integer Days before warning alert (1-60). Example: 3
     * @bodyParam auto_cancel_days integer Days before auto-cancel (0-90, 0=disabled). Example: 20
     * @bodyParam require_customer_phone boolean Require customer phone on creation. Example: true
     * @bodyParam require_notes boolean Require notes field. Example: false
     *
     * @response 200 scenario="success" {
     *   "message": "Configurações atualizadas.",
     *   "config": {
     *     "notify_on_status_change": true,
     *     "notification_channel": "whatsapp",
     *     "warning_after_days": 3,
     *     "auto_cancel_days": 20,
     *     "require_customer_phone": true,
     *     "require_notes": false
     *   }
     * }
     * @response 404 scenario="module not found" {"message": "Módulo não encontrado."}
     * @response 422 scenario="validation error" {"message": "The warning_after_days field must be at least 1."}
     */
    public function updateConfig(Request $request, string $moduleId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $schema = $module->getConfigSchema();
        $defaults = $module->getDefaultConfig();

        // Build validation rules from schema
        $validationRules = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $fieldKey => $fieldConfig) {
                $rules = ['sometimes'];

                switch ($fieldConfig['type']) {
                    case 'switch':
                        $rules[] = 'boolean';
                        break;
                    case 'number':
                        $rules[] = 'integer';
                        if (isset($fieldConfig['min']))
                            $rules[] = 'min:' . $fieldConfig['min'];
                        if (isset($fieldConfig['max']))
                            $rules[] = 'max:' . $fieldConfig['max'];
                        break;
                    case 'select':
                        $options = array_keys($fieldConfig['options'] ?? []);
                        if (!empty($options)) {
                            $rules[] = 'in:' . implode(',', $options);
                        }
                        break;
                    case 'text':
                    case 'textarea':
                        $rules[] = 'string';
                        if (isset($fieldConfig['max']))
                            $rules[] = 'max:' . $fieldConfig['max'];
                        break;
                }

                $validationRules[$fieldKey] = $rules;
            }
        }

        $validated = $request->validate($validationRules);

        // Merge with existing config
        $currentConfig = $dbModule->config ?? [];
        $newConfig = array_merge($currentConfig, $validated);

        $dbModule->update(['config' => $newConfig]);

        // Log the change
        $this->logAudit($dbModule, 'config_updated', [
            'changes' => $validated,
            'previous' => array_intersect_key($currentConfig, $validated),
        ], auth()->user());

        // Return merged config
        $mergedConfig = array_merge($defaults, $newConfig);

        return response()->json([
            'message' => 'Configurações atualizadas.',
            'config' => $mergedConfig,
        ]);
    }

    /**
     * Reset module config to defaults.
     *
     * Removes all custom configuration and restores the module to its default
     * settings as defined in the module class. This action is logged in the
     * audit trail with the previous configuration values.
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     *
     * @response 200 scenario="success" {
     *   "message": "Configurações restauradas para os valores padrão.",
     *   "config": {
     *     "notify_on_status_change": false,
     *     "notification_channel": "whatsapp",
     *     "warning_after_days": 5,
     *     "auto_cancel_days": 20,
     *     "require_customer_phone": true,
     *     "require_notes": false
     *   }
     * }
     * @response 404 scenario="module not found" {"message": "Módulo não encontrado."}
     */
    public function resetConfig(string $moduleId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $previousConfig = $dbModule->config;
        $dbModule->update(['config' => null]);

        // Log the change
        $this->logAudit($dbModule, 'config_reset', [
            'previous_config' => $previousConfig,
        ], auth()->user());

        return response()->json([
            'message' => 'Configurações restauradas para os valores padrão.',
            'config' => $module->getDefaultConfig(),
        ]);
    }

    /**
     * Get store-specific config.
     *
     * Returns the configuration for a module on a specific store. Store config
     * overrides global config. The response includes both the global config,
     * store-specific overrides, and the effective (merged) configuration.
     *
     * **Config Hierarchy:**
     * 1. Module defaults (from code)
     * 2. Global config (from modules table)
     * 3. Store config (from module_store pivot) - highest priority
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     * @urlParam store integer required The store ID. Example: 1
     *
     * @response 200 scenario="success" {
     *   "module_id": "pedidos-simples",
     *   "store_id": 1,
     *   "global_config": {
     *     "notify_on_status_change": true,
     *     "warning_after_days": 5
     *   },
     *   "store_config": {
     *     "warning_after_days": 2
     *   },
     *   "effective_config": {
     *     "notify_on_status_change": true,
     *     "warning_after_days": 2
     *   },
     *   "schema": {}
     * }
     * @response 404 scenario="module not active for store" {"message": "Módulo não ativo para esta loja."}
     */
    public function getStoreConfig(string $moduleId, int $storeId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        // Get store-specific config from pivot table
        $storeModule = $dbModule->stores()->where('stores.id', $storeId)->first();

        if (!$storeModule) {
            return response()->json(['message' => 'Módulo não ativo para esta loja.'], 404);
        }

        $globalConfig = array_merge($module->getDefaultConfig(), $dbModule->config ?? []);
        $storeConfig = $storeModule->pivot->config ?? [];
        $mergedConfig = array_merge($globalConfig, $storeConfig);

        return response()->json([
            'module_id' => $moduleId,
            'store_id' => $storeId,
            'global_config' => $globalConfig,
            'store_config' => $storeConfig,
            'effective_config' => $mergedConfig,
            'schema' => $module->getConfigSchema(),
        ]);
    }

    /**
     * Update store-specific config.
     *
     * Updates the configuration for a module on a specific store. These settings
     * override the global module configuration for this store only.
     *
     * Use this endpoint when a store needs different settings than the default.
     * For example, one store might need shorter warning periods or different
     * notification settings.
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     * @urlParam store integer required The store ID. Example: 1
     *
     * @bodyParam warning_after_days integer Days before warning (overrides global). Example: 2
     * @bodyParam notify_on_status_change boolean Enable notifications (overrides global). Example: true
     *
     * @response 200 scenario="success" {
     *   "message": "Configurações da loja #1 atualizadas.",
     *   "store_config": {
     *     "warning_after_days": 2,
     *     "notify_on_status_change": true
     *   }
     * }
     * @response 404 scenario="module not found" {"message": "Módulo não encontrado."}
     */
    public function updateStoreConfig(Request $request, string $moduleId, int $storeId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        // Validate against schema (same logic as updateConfig)
        $schema = $module->getConfigSchema();
        $validationRules = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $fieldKey => $fieldConfig) {
                $rules = ['sometimes'];
                switch ($fieldConfig['type']) {
                    case 'switch':
                        $rules[] = 'boolean';
                        break;
                    case 'number':
                        $rules[] = 'integer';
                        if (isset($fieldConfig['min']))
                            $rules[] = 'min:' . $fieldConfig['min'];
                        if (isset($fieldConfig['max']))
                            $rules[] = 'max:' . $fieldConfig['max'];
                        break;
                    case 'select':
                        $options = array_keys($fieldConfig['options'] ?? []);
                        if (!empty($options))
                            $rules[] = 'in:' . implode(',', $options);
                        break;
                    default:
                        $rules[] = 'string';
                        break;
                }
                $validationRules[$fieldKey] = $rules;
            }
        }

        $validated = $request->validate($validationRules);

        // Get current store config
        $pivot = $dbModule->stores()->where('stores.id', $storeId)->first()?->pivot;
        $currentConfig = $pivot?->config ?? [];
        $newConfig = array_merge($currentConfig, $validated);

        // Update pivot
        $dbModule->stores()->updateExistingPivot($storeId, ['config' => $newConfig]);

        // Log the change
        $this->logAudit($dbModule, 'store_config_updated', [
            'store_id' => $storeId,
            'changes' => $validated,
        ], auth()->user());

        return response()->json([
            'message' => "Configurações da loja #{$storeId} atualizadas.",
            'store_config' => $newConfig,
        ]);
    }

    // ========================================
    // Status Management (CRUD)
    // ========================================

    /**
     * Get validation schema.
     *
     * Returns the complete validation schema for editing module components:
     * texts, statuses, and actions. This schema allows the frontend to render
     * dynamic forms with proper validation before submission.
     *
     * **Included Data:**
     * - `schema.texts`: Validation rules for text/label fields
     * - `schema.status`: Validation rules for status editing
     * - `schema.action`: Validation rules for action editing
     * - `allowed_values`: Lists of valid icons, colors, badge variants
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     *
     * @response 200 scenario="success" {
     *   "module_id": "pedidos-simples",
     *   "schema": {
     *     "texts": {
     *       "menu_label": {"type": "string", "required": false, "min": 1, "max": 100}
     *     },
     *     "status": {
     *       "name": {"type": "string", "required": true, "pattern": "^[a-z_]+$", "max": 50},
     *       "label": {"type": "string", "required": true, "min": 2, "max": 50},
     *       "color": {"type": "enum", "allowed": ["blue", "red", "yellow", "green"]},
     *       "icon": {"type": "enum", "allowed": ["FileCheck", "Truck", "Store"]}
     *     }
     *   },
     *   "allowed_values": {
     *     "icons": ["FileCheck", "Truck", "Store", "Bell"],
     *     "colors": ["blue", "red", "yellow", "green"],
     *     "badge_variants": ["default", "destructive", "outline"]
     *   }
     * }
     * @response 404 scenario="module not found" {"message": "Módulo não encontrado."}
     */
    public function getSchema(string $moduleId): JsonResponse
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        $module = $registry->get($moduleId);
        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        // Available icons (Lucide)
        $allowedIcons = [
            'FileCheck',
            'Truck',
            'Store',
            'Bell',
            'CheckCircle',
            'XCircle',
            'AlertCircle',
            'Clock',
            'User',
            'UserCheck',
            'Package',
            'Send',
            'Plus',
            'Edit',
            'Trash',
            'Eye',
            'Settings',
            'Shield',
            'Key',
            'Palette',
            'LayoutDashboard',
            'ClipboardList',
            'CreditCard',
        ];

        // Available colors
        $allowedColors = ['blue', 'red', 'yellow', 'green', 'purple', 'gray', 'orange', 'cyan', 'pink'];

        // Badge variants (Shadcn)
        $badgeVariants = ['default', 'destructive', 'outline', 'secondary', 'success', 'warning'];

        return response()->json([
            'module_id' => $moduleId,
            'schema' => [
                'texts' => [
                    'menu_label' => ['type' => 'string', 'required' => false, 'min' => 1, 'max' => 100],
                    'menu_tooltip' => ['type' => 'string', 'required' => false, 'max' => 255],
                    'page_title' => ['type' => 'string', 'required' => false, 'min' => 1, 'max' => 100],
                    'page_description' => ['type' => 'string', 'required' => false, 'max' => 500],
                    'create_button' => ['type' => 'string', 'required' => false, 'max' => 50],
                    'empty_state' => ['type' => 'string', 'required' => false, 'max' => 255],
                ],
                'status' => [
                    'name' => ['type' => 'string', 'required' => true, 'pattern' => '^[a-z_]+$', 'max' => 50, 'hint' => 'Slug interno (ex: aguardando_cliente)'],
                    'label' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 50],
                    'description' => ['type' => 'string', 'required' => false, 'max' => 255],
                    'color' => ['type' => 'enum', 'allowed' => $allowedColors],
                    'icon' => ['type' => 'enum', 'allowed' => $allowedIcons],
                    'badge_variant' => ['type' => 'enum', 'allowed' => $badgeVariants],
                    'can_edit' => ['type' => 'boolean', 'default' => true],
                    'final' => ['type' => 'boolean', 'default' => false, 'hint' => 'Status final encerra o fluxo'],
                    'tooltip' => ['type' => 'string', 'required' => false, 'max' => 255],
                    'help_text' => ['type' => 'string', 'required' => false, 'max' => 500],
                ],
                'action' => [
                    'label' => ['type' => 'string', 'required' => true, 'max' => 50],
                    'icon' => ['type' => 'enum', 'allowed' => $allowedIcons],
                    'tooltip' => ['type' => 'string', 'required' => false, 'max' => 255],
                    'permission' => ['type' => 'string', 'required' => false, 'pattern' => '^[a-z._-]+$'],
                    'available_in_status' => ['type' => 'array', 'items' => 'integer'],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                    'confirm_title' => ['type' => 'string', 'max' => 100],
                    'confirm_message' => ['type' => 'string', 'max' => 500],
                    'requires_fields' => ['type' => 'array', 'items' => 'string'],
                ],
            ],
            'allowed_values' => [
                'icons' => $allowedIcons,
                'colors' => $allowedColors,
                'badge_variants' => $badgeVariants,
            ],
        ]);
    }

    /**
     * Update status configuration.
     *
     * Allows Super Admin to customize status properties like labels, colors,
     * icons, and tooltips. Changes are stored as overrides and merged with
     * the base module definition.
     *
     * **Editable Fields:**
     * - `label`: Display name (2-50 chars)
     * - `description`: Status description
     * - `color`: Visual color (blue, red, yellow, etc.)
     * - `icon`: Lucide icon name
     * - `badge_variant`: Shadcn badge variant
     * - `can_edit`: Whether records in this status can be edited
     * - `final`: Whether this is a terminal status
     * - `tooltip`, `help_text`: UI guidance
     *
     * @urlParam module string required The module ID. Example: pedidos-simples
     * @urlParam status string required The status key. Example: 3
     *
     * @bodyParam label string The display label. Example: Disponível para Retirada
     * @bodyParam description string Status description. Example: Produto pronto para retirada
     * @bodyParam color string Color: blue,red,yellow,green,purple,gray,orange,cyan,pink. Example: green
     * @bodyParam icon string Lucide icon name. Example: CheckCircle
     * @bodyParam badge_variant string Variant: default,destructive,outline,secondary,success,warning. Example: success
     * @bodyParam can_edit boolean Can records be edited in this status. Example: false
     * @bodyParam final boolean Is this a terminal status. Example: false
     * @bodyParam tooltip string Tooltip for UI. Example: Produto pronto
     * @bodyParam help_text string Help text for admins. Example: O vendedor deve notificar o cliente
     *
     * @response 200 scenario="success" {
     *   "message": "Status '3' atualizado.",
     *   "data": {
     *     "name": "disponivel",
     *     "label": "Disponível para Retirada",
     *     "color": "green",
     *     "icon": "CheckCircle"
     *   }
     * }
     * @response 404 scenario="status not found" {"message": "Status não encontrado."}
     */
    public function updateStatus(Request $request, string $moduleId, string $statusKey): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        // Check if status exists in base module
        $statuses = $module->getStatuses();
        if (!isset($statuses[$statusKey])) {
            // Check if it's a custom status in overrides
            $customStatuses = $dbModule->status_overrides ?? [];
            if (!isset($customStatuses[$statusKey])) {
                return response()->json(['message' => 'Status não encontrado.'], 404);
            }
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'min:2', 'max:50'],
            'description' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'in:blue,red,yellow,green,purple,gray,orange,cyan,pink'],
            'icon' => ['sometimes', 'string', 'max:50'],
            'badge_variant' => ['sometimes', 'string', 'in:default,destructive,outline,secondary,success,warning'],
            'can_edit' => ['sometimes', 'boolean'],
            'final' => ['sometimes', 'boolean'],
            'tooltip' => ['sometimes', 'string', 'max:255'],
            'help_text' => ['sometimes', 'string', 'max:500'],
        ]);

        // Merge with existing overrides
        $statusOverrides = $dbModule->status_overrides ?? [];
        $statusOverrides[$statusKey] = array_merge($statusOverrides[$statusKey] ?? [], $validated);

        $dbModule->update(['status_overrides' => $statusOverrides]);

        // Log the change
        $this->logAudit($dbModule, 'status_updated', [
            'status_key' => $statusKey,
            'changes' => $validated,
        ], auth()->user());

        return response()->json([
            'message' => "Status '{$statusKey}' atualizado.",
            'data' => $dbModule->getStatuses()[$statusKey] ?? null,
        ]);
    }

    /**
     * Create a custom status.
     * Allows Super Admin to add new statuses to a module.
     */
    public function createStatus(Request $request, string $moduleId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:50'],
            'status' => ['required', 'array'],
            'status.name' => ['required', 'string', 'regex:/^[a-z_]+$/', 'max:50'],
            'status.label' => ['required', 'string', 'min:2', 'max:50'],
            'status.description' => ['sometimes', 'string', 'max:255'],
            'status.color' => ['required', 'string', 'in:blue,red,yellow,green,purple,gray,orange,cyan,pink'],
            'status.icon' => ['required', 'string', 'max:50'],
            'status.badge_variant' => ['sometimes', 'string', 'in:default,destructive,outline,secondary,success,warning'],
            'status.can_edit' => ['sometimes', 'boolean'],
            'status.final' => ['sometimes', 'boolean'],
            'status.tooltip' => ['sometimes', 'string', 'max:255'],
            'status.help_text' => ['sometimes', 'string', 'max:500'],
            // Transitions from this status
            'transitions_to' => ['sometimes', 'array'],
            'transitions_to.*' => ['string'],
            // Transitions to this status
            'transitions_from' => ['sometimes', 'array'],
            'transitions_from.*' => ['string'],
        ]);

        $key = $validated['key'];
        $statuses = $module->getStatuses();

        // Check if key already exists
        if (isset($statuses[$key])) {
            return response()->json(['message' => "Status com key '{$key}' já existe."], 422);
        }

        // Add custom status
        $statusOverrides = $dbModule->status_overrides ?? [];
        $statusOverrides[$key] = array_merge($validated['status'], [
            '_custom' => true,
            '_created_at' => now()->toIso8601String(),
        ]);

        $updates = ['status_overrides' => $statusOverrides];

        // Update transitions if provided
        if (isset($validated['transitions_to']) || isset($validated['transitions_from'])) {
            $transitionOverrides = $dbModule->transition_overrides ?? [];

            // Transitions from this status
            if (isset($validated['transitions_to'])) {
                $transitionOverrides[$key] = $validated['transitions_to'];
            }

            // Transitions to this status (from other statuses)
            if (isset($validated['transitions_from'])) {
                foreach ($validated['transitions_from'] as $fromKey) {
                    if (!isset($transitionOverrides[$fromKey])) {
                        $transitionOverrides[$fromKey] = [];
                    }
                    if (!in_array($key, $transitionOverrides[$fromKey])) {
                        $transitionOverrides[$fromKey][] = $key;
                    }
                }
            }

            $updates['transition_overrides'] = $transitionOverrides;
        }

        $dbModule->update($updates);

        // Log the change
        $this->logAudit($dbModule, 'status_created', [
            'status_key' => $key,
            'status' => $validated['status'],
        ], auth()->user());

        return response()->json([
            'message' => "Status '{$key}' criado.",
            'data' => $statusOverrides[$key],
            'all_statuses' => $dbModule->getStatuses(),
        ], 201);
    }

    /**
     * Delete a custom status.
     * Only custom statuses can be deleted.
     */
    public function deleteStatus(Request $request, string $moduleId, string $statusKey): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $baseStatuses = $module->getStatuses();
        $customStatuses = $dbModule->status_overrides ?? [];

        // Can only delete custom statuses (not base module statuses)
        if (isset($baseStatuses[$statusKey]) && !isset($customStatuses[$statusKey]['_custom'])) {
            return response()->json([
                'message' => 'Não é possível deletar status base do módulo.',
                'hint' => 'Use o endpoint updateStatus para modificar propriedades do status.',
            ], 422);
        }

        // Force check - require preview first unless force=true
        $force = $request->boolean('force', false);
        if (!$force) {
            // Calculate impact
            $impact = $this->calculateStatusImpact($dbModule, $moduleId, $statusKey);
            if ($impact['affected_records'] > 0) {
                return response()->json([
                    'message' => 'Existem registros neste status. Use force=true para confirmar.',
                    'impact' => $impact,
                ], 409);
            }
        }

        // Remove from status_overrides
        if (isset($customStatuses[$statusKey])) {
            unset($customStatuses[$statusKey]);
            $dbModule->status_overrides = $customStatuses;
        }

        // Remove from transition_overrides
        $transitionOverrides = $dbModule->transition_overrides ?? [];
        unset($transitionOverrides[$statusKey]);
        foreach ($transitionOverrides as $from => &$toList) {
            $toList = array_filter($toList, fn($to) => $to !== $statusKey);
        }
        $dbModule->transition_overrides = $transitionOverrides;

        $dbModule->save();

        // Log the change
        $this->logAudit($dbModule, 'status_deleted', [
            'status_key' => $statusKey,
        ], auth()->user());

        return response()->json([
            'message' => "Status '{$statusKey}' removido.",
            'all_statuses' => $dbModule->getStatuses(),
        ]);
    }

    /**
     * Preview impact of a proposed change.
     * Helps admin understand consequences before confirming.
     */
    public function previewImpact(Request $request, string $moduleId): JsonResponse
    {
        $dbModule = Module::findOrFail($moduleId);

        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado.'], 404);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:delete_status,update_status,update_transition,delete_action'],
            'status_key' => ['required_if:action,delete_status,update_status', 'string'],
            'changes' => ['sometimes', 'array'],
        ]);

        $action = $validated['action'];
        $statusKey = $validated['status_key'] ?? null;
        $changes = $validated['changes'] ?? [];

        $impact = [
            'action' => $action,
            'can_proceed' => true,
            'affected_records' => 0,
            'warnings' => [],
            'suggestions' => [],
        ];

        switch ($action) {
            case 'delete_status':
                $impact = array_merge($impact, $this->calculateStatusImpact($dbModule, $moduleId, $statusKey));
                break;

            case 'update_status':
                if (isset($changes['final']) && $changes['final'] === true) {
                    // Check if there are transitions FROM this status
                    $transitions = $module->getTransitions();
                    if (isset($transitions[$statusKey]) && count($transitions[$statusKey]) > 0) {
                        $count = count($transitions[$statusKey]);
                        $impact['warnings'][] = "Este status tem {$count} transições de saída que serão invalidadas.";
                        $impact['suggestions'][] = 'Remova as transições de saída antes de marcar como final.';
                    }
                }
                break;

            case 'update_transition':
                // For now, just acknowledge
                $impact['warnings'][] = 'Alterações em transições podem afetar o fluxo de trabalho dos usuários.';
                break;

            case 'delete_action':
                $actionKey = $validated['changes']['action_key'] ?? null;
                if ($actionKey) {
                    $impact['warnings'][] = "A ação '{$actionKey}' será removida de todos os status onde está disponível.";
                }
                break;
        }

        return response()->json($impact);
    }

    /**
     * Calculate impact of deleting a status.
     */
    protected function calculateStatusImpact(Module $dbModule, string $moduleId, string $statusKey): array
    {
        $impact = [
            'status_key' => $statusKey,
            'can_proceed' => true,
            'affected_records' => 0,
            'warnings' => [],
            'suggestions' => [],
        ];

        // Count affected records based on module type
        $modelClass = match ($moduleId) {
            'pedidos-simples' => \App\Models\Pedido::class,
            'capas-personalizadas' => \App\Models\CapaPersonalizada::class,
            default => null,
        };

        if ($modelClass && class_exists($modelClass)) {
            $count = $modelClass::where('status', $statusKey)->count();
            $impact['affected_records'] = $count;

            if ($count > 0) {
                $impact['warnings'][] = "{$count} registros estão neste status.";
                $impact['suggestions'][] = 'Mova os registros para outro status antes de deletar.';
                $impact['can_proceed'] = false;
            }
        }

        // Check transitions TO this status
        $registry = ModuleRegistry::getInstance();
        $registry->boot();
        $module = $registry->get($moduleId);

        if ($module) {
            $transitions = $module->getTransitions();
            $incomingCount = 0;
            $outgoingCount = 0;

            foreach ($transitions as $from => $toList) {
                if (in_array($statusKey, $toList)) {
                    $incomingCount++;
                }
                if ($from === $statusKey) {
                    $outgoingCount = count($toList);
                }
            }

            if ($incomingCount > 0) {
                $impact['warnings'][] = "{$incomingCount} status(s) tem transição para este status.";
            }
            if ($outgoingCount > 0) {
                $impact['warnings'][] = "Este status tem {$outgoingCount} transição(ões) de saída.";
            }
        }

        // Check actions linked to this status
        $actions = $module?->getActions() ?? [];
        $linkedActions = [];
        foreach ($actions as $actionKey => $actionConfig) {
            $availableIn = $actionConfig['available_in_status'] ?? [];
            if (in_array($statusKey, $availableIn) || in_array((int) $statusKey, $availableIn)) {
                $linkedActions[] = $actionKey;
            }
        }
        if (count($linkedActions) > 0) {
            $impact['warnings'][] = count($linkedActions) . " ação(ões) estão vinculadas a este status: " . implode(', ', $linkedActions);
        }

        return $impact;
    }
}

