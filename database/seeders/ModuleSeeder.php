<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Install all core modules in the database.
     */
    public function run(): void
    {
        $registry = ModuleRegistry::getInstance();
        $registry->boot();

        foreach ($registry->all() as $module) {
            Module::updateOrCreate(
                ['id' => $module->getId()],
                [
                    'name' => $module->getName(),
                    'description' => $module->getDescription(),
                    'version' => $module->getVersion(),
                    'icon' => $module->getIcon(),
                    'is_core' => true,
                    'is_active' => true,
                    'installed_at' => now(),
                ]
            );

            $this->command->info("✓ Módulo '{$module->getName()}' instalado.");
        }

        $this->command->newLine();
        $this->command->info("Total: {$registry->all()->count()} módulos instalados.");
    }
}
