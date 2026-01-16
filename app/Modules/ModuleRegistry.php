<?php

declare(strict_types=1);

namespace App\Modules;

use App\Modules\Contracts\ModuleInterface;
use Illuminate\Support\Collection;

/**
 * Registry that manages all installed modules.
 *
 * Singleton pattern - use ModuleRegistry::getInstance()
 */
class ModuleRegistry
{
    private static ?self $instance = null;

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    private function __construct()
    {
        // Private constructor for singleton
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a module.
     */
    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->getId()] = $module;
    }

    /**
     * Get a module by ID.
     */
    public function get(string $moduleId): ?ModuleInterface
    {
        return $this->modules[$moduleId] ?? null;
    }

    /**
     * Check if module exists.
     */
    public function has(string $moduleId): bool
    {
        return isset($this->modules[$moduleId]);
    }

    /**
     * Get all registered modules.
     *
     * @return Collection<string, ModuleInterface>
     */
    public function all(): Collection
    {
        return collect($this->modules);
    }

    /**
     * Get module IDs.
     *
     * @return string[]
     */
    public function getModuleIds(): array
    {
        return array_keys($this->modules);
    }

    /**
     * Get modules as array for API.
     */
    public function toArray(): array
    {
        return $this->all()
            ->map(fn(ModuleInterface $m) => $m->toArray())
            ->values()
            ->toArray();
    }

    /**
     * Boot all registered modules.
     * Called during app bootstrap.
     */
    public function boot(): void
    {
        // Auto-register core modules
        $this->registerCoreModules();
    }

    /**
     * Register core system modules.
     */
    private function registerCoreModules(): void
    {
        $coreModules = [
            \App\Modules\PedidosSimples\PedidosSimplesModule::class,
            \App\Modules\CapasPersonalizadas\CapasPersonalizadasModule::class,
            \App\Modules\Fabrica\FabricaModule::class,
            \App\Modules\Comunicados\ComunicadosModule::class,
            \App\Modules\CatalogoAparelhos\CatalogoAparelhosModule::class,
            \App\Modules\WhatsAppInstances\WhatsAppInstancesModule::class,
            \App\Modules\ConferenciaCaixa\ConferenciaCaixaModule::class,
            \App\Modules\Comemoracoes\ComemoracoesModule::class,
        ];

        foreach ($coreModules as $moduleClass) {
            if (class_exists($moduleClass)) {
                $this->register(new $moduleClass());
            }
        }
    }

    /**
     * Reset the registry (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
