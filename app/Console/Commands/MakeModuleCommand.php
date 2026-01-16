<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Generate a new module with all necessary files.
 *
 * Usage:
 *   php artisan make:module Comunicados
 *   php artisan make:module Conferencia --with-migration
 *   php artisan make:module Devolucoes --with-all
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The name of the module (e.g., Comunicados)}
        {--with-migration : Generate migration file}
        {--with-controller : Generate controller}
        {--with-model : Generate model}
        {--with-service : Generate service class}
        {--with-all : Generate all files}
        {--statuses=3 : Number of default statuses to generate}';

    protected $description = 'Generate a new module with all necessary files';

    protected Filesystem $files;
    protected string $moduleName;
    protected string $moduleSlug;
    protected string $modulePath;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $this->moduleName = Str::studly($this->argument('name'));
        $this->moduleSlug = Str::kebab($this->moduleName);
        $this->modulePath = app_path("Modules/{$this->moduleName}");

        $this->info("🚀 Creating module: {$this->moduleName}");

        // Create module directory
        $this->createDirectory($this->modulePath);

        // Always create the module class
        $this->createModuleClass();

        // Optional files
        $withAll = $this->option('with-all');

        if ($withAll || $this->option('with-controller')) {
            $this->createController();
        }

        if ($withAll || $this->option('with-model')) {
            $this->createModel();
        }

        if ($withAll || $this->option('with-service')) {
            $this->createService();
        }

        if ($withAll || $this->option('with-migration')) {
            $this->createMigration();
        }

        // Always create routes file
        $this->createRoutes();

        // Update ModuleRegistry
        $this->updateRegistry();

        $this->newLine();
        $this->info("✅ Module {$this->moduleName} created successfully!");
        $this->newLine();

        $this->table(
            ['File', 'Path'],
            $this->getCreatedFiles()
        );

        $this->newLine();
        $this->comment("📝 Next steps:");
        $this->line("  1. Review generated files in app/Modules/{$this->moduleName}/");
        $this->line("  2. Customize statuses, permissions, and actions");
        $this->line("  3. Run migrations if created: php artisan migrate");
        $this->line("  4. Install module: POST /api/v1/admin/modules/{$this->moduleSlug}/install");

        return Command::SUCCESS;
    }

    protected function createDirectory(string $path): void
    {
        if (!$this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    protected function createModuleClass(): void
    {
        $statusCount = (int) $this->option('statuses');
        $statuses = $this->generateDefaultStatuses($statusCount);
        $transitions = $this->generateDefaultTransitions($statusCount);

        $content = $this->getModuleStub();
        $content = $this->replacePlaceholders($content, [
            'statuses' => $statuses,
            'transitions' => $transitions,
        ]);

        $path = "{$this->modulePath}/{$this->moduleName}Module.php";
        $this->files->put($path, $content);

        $this->components->info("Created: {$this->moduleName}Module.php");
    }

    protected function createController(): void
    {
        $content = $this->getControllerStub();
        $content = $this->replacePlaceholders($content);

        $path = app_path("Http/Controllers/Api/V1/{$this->moduleName}Controller.php");
        $this->files->put($path, $content);

        $this->components->info("Created: {$this->moduleName}Controller.php");
    }

    protected function createModel(): void
    {
        $singular = Str::singular($this->moduleName);
        $content = $this->getModelStub();
        $content = str_replace('{{ModelName}}', $singular, $content);
        $content = str_replace('{{tableName}}', Str::snake(Str::plural($this->moduleName)), $content);
        $content = $this->replacePlaceholders($content);

        $path = app_path("Models/{$singular}.php");
        $this->files->put($path, $content);

        $this->components->info("Created: {$singular}.php (Model)");
    }

    protected function createService(): void
    {
        $singular = Str::singular($this->moduleName);
        $content = $this->getServiceStub();
        $content = str_replace('{{ModelName}}', $singular, $content);
        $content = $this->replacePlaceholders($content);

        $path = app_path("Services/{$singular}Service.php");
        $this->files->put($path, $content);

        $this->components->info("Created: {$singular}Service.php");
    }

    protected function createMigration(): void
    {
        $tableName = Str::snake(Str::plural($this->moduleName));
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_create_{$tableName}_table.php";

        $content = $this->getMigrationStub();
        $content = str_replace('{{tableName}}', $tableName, $content);
        $content = $this->replacePlaceholders($content);

        $path = database_path("migrations/{$filename}");
        $this->files->put($path, $content);

        $this->components->info("Created: {$filename}");
    }

    protected function createRoutes(): void
    {
        $content = $this->getRoutesStub();
        $content = $this->replacePlaceholders($content);

        $path = "{$this->modulePath}/routes.php";
        $this->files->put($path, $content);

        $this->components->info("Created: routes.php");
    }

    protected function updateRegistry(): void
    {
        $registryPath = app_path('Modules/ModuleRegistry.php');
        $content = $this->files->get($registryPath);

        // Check if already registered
        if (Str::contains($content, "{$this->moduleName}Module::class")) {
            $this->components->warn("Module already registered in ModuleRegistry");
            return;
        }

        // Add import and registration
        $import = "\\App\\Modules\\{$this->moduleName}\\{$this->moduleName}Module::class,";

        $content = str_replace(
            "\\App\\Modules\\Fabrica\\FabricaModule::class,",
            "\\App\\Modules\\Fabrica\\FabricaModule::class,\n            {$import}",
            $content
        );

        $this->files->put($registryPath, $content);
        $this->components->info("Updated: ModuleRegistry.php");
    }

    protected function replacePlaceholders(string $content, array $extra = []): string
    {
        $singular = Str::singular($this->moduleName);

        $replacements = [
            '{{ModuleName}}' => $this->moduleName,
            '{{moduleName}}' => lcfirst($this->moduleName),
            '{{module-slug}}' => $this->moduleSlug,
            '{{module_slug}}' => Str::snake($this->moduleName),
            '{{SingularName}}' => $singular,
            '{{singularName}}' => lcfirst($singular),
            '{{PluralName}}' => $this->moduleName,
        ];

        foreach ($replacements as $key => $value) {
            $content = str_replace($key, $value, $content);
        }

        foreach ($extra as $key => $value) {
            $content = str_replace("{{{$key}}}", $value, $content);
        }

        return $content;
    }

    protected function generateDefaultStatuses(int $count): string
    {
        $statuses = [];
        $names = ['pendente', 'em_andamento', 'concluido', 'cancelado'];
        $labels = ['Pendente', 'Em Andamento', 'Concluído', 'Cancelado'];
        $colors = ['yellow', 'blue', 'green', 'red'];
        $icons = ['Clock', 'RefreshCw', 'CheckCircle', 'XCircle'];

        for ($i = 0; $i < min($count, 4); $i++) {
            $key = $i + 1;
            $statuses[] = "            {$key} => [
                'name' => '{$names[$i]}',
                'label' => '{$labels[$i]}',
                'color' => '{$colors[$i]}',
                'icon' => '{$icons[$i]}',
                'final' => " . ($i >= 2 ? 'true' : 'false') . ",
            ],";
        }

        return implode("\n", $statuses);
    }

    protected function generateDefaultTransitions(int $count): string
    {
        $transitions = [];

        for ($i = 1; $i <= min($count, 4); $i++) {
            $next = [];
            if ($i < $count)
                $next[] = $i + 1;
            if ($i == 1 && $count >= 4)
                $next[] = $count; // pendente -> cancelado
            if ($i == 2 && $count >= 4)
                $next[] = $count; // em_andamento -> cancelado

            $nextStr = empty($next) ? '' : implode(', ', $next);
            $transitions[] = "            {$i} => [{$nextStr}],";
        }

        return implode("\n", $transitions);
    }

    protected function getCreatedFiles(): array
    {
        $files = [
            ['Module Class', "app/Modules/{$this->moduleName}/{$this->moduleName}Module.php"],
            ['Routes', "app/Modules/{$this->moduleName}/routes.php"],
        ];

        $withAll = $this->option('with-all');

        if ($withAll || $this->option('with-controller')) {
            $files[] = ['Controller', "app/Http/Controllers/Api/V1/{$this->moduleName}Controller.php"];
        }

        if ($withAll || $this->option('with-model')) {
            $singular = Str::singular($this->moduleName);
            $files[] = ['Model', "app/Models/{$singular}.php"];
        }

        if ($withAll || $this->option('with-service')) {
            $singular = Str::singular($this->moduleName);
            $files[] = ['Service', "app/Services/{$singular}Service.php"];
        }

        if ($withAll || $this->option('with-migration')) {
            $files[] = ['Migration', 'database/migrations/..._create_' . Str::snake(Str::plural($this->moduleName)) . '_table.php'];
        }

        return $files;
    }

    // ========================================
    // STUBS
    // ========================================

    protected function getModuleStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace App\Modules\{{ModuleName}};

use App\Modules\BaseModule;

/**
 * Module: {{ModuleName}}
 *
 * Auto-generated by make:module command.
 */
class {{ModuleName}}Module extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = false;

    public function getId(): string
    {
        return '{{module-slug}}';
    }

    public function getName(): string
    {
        return '{{ModuleName}}';
    }

    public function getDescription(): string
    {
        return 'Gerenciamento de {{moduleName}}.';
    }

    public function getIcon(): string
    {
        return 'FileText';
    }

    public function getDependencies(): array
    {
        return [];
    }

    // ========================================
    // Statuses
    // ========================================

    public function getStatuses(): array
    {
        return [
{statuses}
        ];
    }

    // ========================================
    // Transitions
    // ========================================

    public function getTransitions(): array
    {
        return [
{transitions}
        ];
    }

    // ========================================
    // Permissions
    // ========================================

    public function getPermissions(): array
    {
        return [
            ['name' => '{{module_slug}}.view', 'display_name' => 'Ver {{ModuleName}}', 'type' => 'ability'],
            ['name' => '{{module_slug}}.create', 'display_name' => 'Criar {{SingularName}}', 'type' => 'ability'],
            ['name' => '{{module_slug}}.update', 'display_name' => 'Editar {{SingularName}}', 'type' => 'ability'],
            ['name' => '{{module_slug}}.delete', 'display_name' => 'Excluir {{SingularName}}', 'type' => 'ability'],
        ];
    }

    // ========================================
    // Actions
    // ========================================

    public function getActions(): array
    {
        return [
            'update_status' => [
                'label' => 'Atualizar Status',
                'icon' => 'RefreshCw',
                'permission' => '{{module_slug}}.update',
            ],
        ];
    }

    // ========================================
    // Texts
    // ========================================

    public function getTexts(): array
    {
        return [
            'menu_label' => '{{ModuleName}}',
            'menu_tooltip' => 'Gerenciar {{moduleName}}',
            'page_title' => '{{ModuleName}}',
            'page_description' => 'Gerencie os {{moduleName}} do sistema',
            'create_button' => 'Novo {{SingularName}}',
            'empty_state' => 'Nenhum {{singularName}} encontrado.',
        ];
    }
}
STUB;
    }

    protected function getControllerStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{{SingularName}};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group {{ModuleName}}
 *
 * API para gerenciamento de {{moduleName}}.
 */
class {{ModuleName}}Controller extends Controller
{
    /**
     * Listar {{moduleName}}.
     */
    public function index(Request $request): JsonResponse
    {
        $items = {{SingularName}}::query()
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($items);
    }

    /**
     * Criar {{singularName}}.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $item = {{SingularName}}::create($validated);

        return response()->json([
            'message' => '{{SingularName}} criado com sucesso.',
            'data' => $item,
        ], 201);
    }

    /**
     * Exibir {{singularName}}.
     */
    public function show({{SingularName}} ${{singularName}}): JsonResponse
    {
        return response()->json(['data' => ${{singularName}}]);
    }

    /**
     * Atualizar {{singularName}}.
     */
    public function update(Request $request, {{SingularName}} ${{singularName}}): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'integer'],
        ]);

        ${{singularName}}->update($validated);

        return response()->json([
            'message' => '{{SingularName}} atualizado.',
            'data' => ${{singularName}},
        ]);
    }

    /**
     * Excluir {{singularName}}.
     */
    public function destroy({{SingularName}} ${{singularName}}): JsonResponse
    {
        ${{singularName}}->delete();

        return response()->json([
            'message' => '{{SingularName}} excluído.',
        ]);
    }
}
STUB;
    }

    protected function getModelStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class {{ModelName}} extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = '{{tableName}}';

    protected $fillable = [
        'title',
        'description',
        'status',
        'created_by_id',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeByStatus($query, ?int $status)
    {
        if ($status !== null) {
            return $query->where('status', $status);
        }
        return $query;
    }
}
STUB;
    }

    protected function getServiceStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{{ModelName}};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class {{ModelName}}Service
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return {{ModelName}}::query()
            ->byStatus($filters['status'] ?? null)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function create(array $data): {{ModelName}}
    {
        return {{ModelName}}::create($data);
    }

    public function update({{ModelName}} $item, array $data): {{ModelName}}
    {
        $item->update($data);
        return $item->fresh();
    }

    public function delete({{ModelName}} $item): bool
    {
        return $item->delete();
    }

    public function updateStatus({{ModelName}} $item, int $status): {{ModelName}}
    {
        $item->update(['status' => $status]);
        return $item->fresh();
    }
}
STUB;
    }

    protected function getMigrationStub(): string
    {
        return <<<'STUB'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{tableName}}', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{tableName}}');
    }
};
STUB;
    }

    protected function getRoutesStub(): string
    {
        return <<<'STUB'
<?php

/**
 * Routes for {{ModuleName}} module.
 *
 * These routes should be included in the main api_v1.php file.
 *
 * Example:
 *   Route::prefix('{{module-slug}}')->name('{{module_slug}}.')->group(function () {
 *       require app_path('Modules/{{ModuleName}}/routes.php');
 *   });
 */

use App\Http\Controllers\Api\V1\{{ModuleName}}Controller;
use Illuminate\Support\Facades\Route;

Route::get('/', [{{ModuleName}}Controller::class, 'index'])->name('index');
Route::post('/', [{{ModuleName}}Controller::class, 'store'])->name('store');
Route::get('/{{{singularName}}}', [{{ModuleName}}Controller::class, 'show'])->name('show');
Route::patch('/{{{singularName}}}', [{{ModuleName}}Controller::class, 'update'])->name('update');
Route::delete('/{{{singularName}}}', [{{ModuleName}}Controller::class, 'destroy'])->name('destroy');
STUB;
    }
}
