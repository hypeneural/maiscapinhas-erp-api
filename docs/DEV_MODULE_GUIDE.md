# 🏗️ Guia de Desenvolvimento de Módulos

Este documento descreve como criar novos módulos para o ERP MaisCapinhas.

---

## Índice

1. [Arquitetura Modular](#arquitetura-modular)
2. [Criando um Novo Módulo](#criando-um-novo-módulo)
3. [Estrutura do Módulo](#estrutura-do-módulo)
4. [Métodos Obrigatórios](#métodos-obrigatórios)
5. [Métodos Opcionais](#métodos-opcionais)
6. [Workflow de Status](#workflow-de-status)
7. [Permissões](#permissões)
8. [Rotas](#rotas)
9. [Boas Práticas](#boas-práticas)
10. [Checklist de Desenvolvimento](#checklist-de-desenvolvimento)
11. [Exemplos](#exemplos)

---

## Arquitetura Modular

O sistema de módulos permite encapsular funcionalidades de forma isolada e reutilizável.

### Componentes Principais

```
app/Modules/
├── BaseModule.php           # Classe base abstrata
├── ModuleRegistry.php       # Singleton que gerencia todos os módulos
├── Contracts/
│   └── ModuleInterface.php  # Interface que todos os módulos implementam
├── Traits/                  # Traits compartilhados
└── NomeDoModulo/           # Pasta do módulo
    ├── NomeDoModuloModule.php  # Classe principal do módulo
    └── routes.php              # Rotas do módulo
```

### Fluxo de Carregamento

1. O `ModuleRegistry` é um singleton que inicializa durante o boot da aplicação
2. Os módulos listados em `registerCoreModules()` são instanciados automaticamente
3. Cada módulo expõe sua configuração via métodos como `getStatuses()`, `getPermissions()`, etc.

---

## Criando um Novo Módulo

### Comando Artisan

```bash
# Criar módulo apenas com a estrutura básica
php artisan make:module NomeDoModulo

# Criar módulo com todos os arquivos
php artisan make:module NomeDoModulo --with-all

# Opções individuais
php artisan make:module NomeDoModulo --with-controller
php artisan make:module NomeDoModulo --with-model
php artisan make:module NomeDoModulo --with-service
php artisan make:module NomeDoModulo --with-migration

# Definir número de statuses padrão
php artisan make:module NomeDoModulo --with-all --statuses=4
```

### Arquivos Gerados

| Opção | Arquivo Gerado | Localização |
|-------|----------------|-------------|
| *(sempre)* | Classe do Módulo | `app/Modules/NomeDoModulo/NomeDoModuloModule.php` |
| *(sempre)* | Rotas | `app/Modules/NomeDoModulo/routes.php` |
| `--with-controller` | Controller | `app/Http/Controllers/Api/V1/NomeDoModuloController.php` |
| `--with-model` | Model | `app/Models/NomeDoModulo.php` |
| `--with-service` | Service | `app/Services/NomeDoModuloService.php` |
| `--with-migration` | Migration | `database/migrations/yyyy_mm_dd_create_...` |

### Após Criar o Módulo

1. **Revisar** os arquivos gerados
2. **Customizar** statuses, permissões e actions
3. **Rodar migrations** se criadas: `php artisan migrate`
4. **Registrar rotas** no `routes/api_v1.php`
5. **Testar** instalação: `POST /api/v1/admin/modules/{module-slug}/install`

---

## Estrutura do Módulo

### Classe Principal do Módulo

```php
<?php

declare(strict_types=1);

namespace App\Modules\MeuModulo;

use App\Modules\BaseModule;

class MeuModuloModule extends BaseModule
{
    protected string $version = '1.0.0';
    protected bool $isCore = true; // false se for um módulo opcional

    // Métodos obrigatórios...
}
```

### Propriedades Configuráveis

| Propriedade | Tipo | Descrição |
|-------------|------|-----------|
| `$version` | string | Versão semver do módulo (ex: `'1.0.0'`) |
| `$isCore` | bool | Se `true`, módulo é essencial ao sistema |

---

## Métodos Obrigatórios

Estes métodos **devem** ser implementados em todo módulo:

### Identificação

```php
public function getId(): string
{
    return 'meu-modulo'; // Slug único (kebab-case)
}

public function getName(): string
{
    return 'Meu Módulo'; // Nome para exibição
}

public function getDescription(): string
{
    return 'Descrição do que o módulo faz.';
}

public function getIcon(): string
{
    return 'FileText'; // Nome do ícone Lucide
}
```

> 💡 **Ícones disponíveis**: Use ícones do [Lucide Icons](https://lucide.dev/icons)

### Statuses (Workflow)

```php
public function getStatuses(): array
{
    return [
        1 => [
            'name' => 'pendente',
            'label' => 'Pendente',
            'color' => 'yellow',
            'icon' => 'Clock',
            'final' => false,
        ],
        2 => [
            'name' => 'em_andamento',
            'label' => 'Em Andamento',
            'color' => 'blue',
            'icon' => 'RefreshCw',
            'final' => false,
        ],
        3 => [
            'name' => 'concluido',
            'label' => 'Concluído',
            'color' => 'green',
            'icon' => 'CheckCircle',
            'final' => true,  // Status final não permite transição
        ],
        4 => [
            'name' => 'cancelado',
            'label' => 'Cancelado',
            'color' => 'red',
            'icon' => 'XCircle',
            'final' => true,
        ],
    ];
}
```

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `name` | string | Identificador interno (snake_case) |
| `label` | string | Label para exibição ao usuário |
| `color` | string | Cor: `yellow`, `blue`, `green`, `red`, `gray`, `purple` |
| `icon` | string | Ícone Lucide |
| `final` | bool | Se `true`, não permite transições a partir dele |

### Transições

```php
public function getTransitions(): array
{
    return [
        1 => [2, 4],     // Pendente -> Em Andamento, Cancelado
        2 => [3, 4],     // Em Andamento -> Concluído, Cancelado
        3 => [],         // Concluído -> (nenhum)
        4 => [],         // Cancelado -> (nenhum)
    ];
}
```

### Matriz de Roles para Transições

```php
public function getTransitionRoleMatrix(): array
{
    return [
        1 => [
            2 => ['vendedor', 'admin', 'gerente'],  // Quem pode: Pendente -> Em Andamento
            4 => ['admin', 'gerente'],              // Quem pode: Pendente -> Cancelado
        ],
        2 => [
            3 => ['conferente', 'admin'],           // Quem pode: Em Andamento -> Concluído
            4 => ['admin', 'gerente'],              // Quem pode: Em Andamento -> Cancelado
        ],
    ];
}
```

> 🔐 **Super Admin**: Sempre pode realizar qualquer transição.

### Permissões

```php
public function getPermissions(): array
{
    return [
        ['name' => 'meu_modulo.view', 'display_name' => 'Ver Meu Módulo', 'type' => 'ability'],
        ['name' => 'meu_modulo.create', 'display_name' => 'Criar', 'type' => 'ability'],
        ['name' => 'meu_modulo.update', 'display_name' => 'Editar', 'type' => 'ability'],
        ['name' => 'meu_modulo.delete', 'display_name' => 'Excluir', 'type' => 'ability'],
        ['name' => 'meu_modulo.status.update', 'display_name' => 'Alterar Status', 'type' => 'ability'],
    ];
}
```

| Tipo | Descrição |
|------|-----------|
| `ability` | Permissão de ação (ex: criar, editar) |
| `feature` | Acesso a funcionalidade específica |
| `scope` | Escopo de dados (ex: ver apenas próprios) |

### Screens (Telas)

```php
public function getScreens(): array
{
    return [
        ['name' => 'meu_modulo.list', 'display_name' => 'Lista', 'path' => '/meu-modulo'],
        ['name' => 'meu_modulo.detail', 'display_name' => 'Detalhes', 'path' => '/meu-modulo/:id'],
        ['name' => 'meu_modulo.create', 'display_name' => 'Criar', 'path' => '/meu-modulo/new'],
    ];
}
```

---

## Métodos Opcionais

Estes métodos têm implementação padrão no `BaseModule`, mas podem ser sobrescritos:

### Actions (Ações da UI)

```php
public function getActions(): array
{
    return [
        'create' => [
            'label' => 'Novo Registro',
            'icon' => 'Plus',
            'permission' => 'meu_modulo.create',
            'shortcut' => 'N',
            'shortcut_modifier' => 'Ctrl',
        ],
        'export' => [
            'label' => 'Exportar',
            'icon' => 'Download',
            'permission' => 'meu_modulo.view',
            'confirm' => true,
            'confirm_title' => 'Exportar dados?',
            'confirm_message' => 'Os dados serão exportados para Excel.',
        ],
    ];
}
```

### Texts (Textos da UI)

```php
public function getTexts(): array
{
    return [
        'menu_label' => 'Meu Módulo',
        'menu_tooltip' => 'Gerenciar registros',
        'page_title' => 'Meu Módulo',
        'page_description' => 'Gerencie os registros do sistema',
        'create_button' => 'Novo Registro',
        'empty_state' => 'Nenhum registro encontrado.',
        'loading_title' => 'Carregando...',
        'error_title' => 'Erro ao carregar',
    ];
}
```

### Dependências

```php
public function getDependencies(): array
{
    return ['customers', 'payment-methods']; // IDs de módulos dependentes
}
```

### Lifecycle Hooks

```php
public function onInstall(): void
{
    // Executado quando o módulo é instalado
}

public function onActivate(int $storeId): void
{
    // Executado quando ativado para uma loja
}

public function onDeactivate(int $storeId): void
{
    // Executado quando desativado para uma loja
}
```

### Configuration Schema

```php
public function getConfigSchema(): array
{
    return [
        'sections' => [
            'notifications' => [
                'label' => 'Notificações',
                'icon' => 'Bell',
                'fields' => [
                    'notify_on_status_change' => [
                        'type' => 'switch',
                        'label' => 'Notificar ao mudar status',
                        'default' => false,
                    ],
                ],
            ],
        ],
    ];
}
```

---

## Workflow de Status

### Diagrama Conceitual

```
┌──────────────┐    ┌────────────────┐    ┌─────────────┐
│   Pendente   │───►│  Em Andamento  │───►│  Concluído  │
│     (1)      │    │       (2)      │    │     (3)     │
└──────┬───────┘    └───────┬────────┘    └─────────────┘
       │                    │
       │                    │
       ▼                    ▼
┌──────────────────────────────┐
│          Cancelado           │
│             (4)              │
└──────────────────────────────┘
```

### Regras Importantes

1. **Status Final**: Quando `final: true`, não pode haver transições a partir dele
2. **Transição Válida**: Checar com `canTransition($from, $to)`
3. **Role Matrix**: Checar permissão com `canUserTransition($from, $to, $userRoles)`
4. **Super Admin**: Sempre bypass na matriz de roles

---

## Permissões

### Convenção de Nomenclatura

```
{modulo}.{acao}
```

Exemplos:
- `pedidos.view` - Ver pedidos
- `pedidos.create` - Criar pedidos
- `pedidos.status.update` - Alterar status de pedidos
- `pedidos.bulk-status` - Alteração em lote

### Uso no Middleware de Rotas

```php
Route::get('/', [Controller::class, 'index'])
    ->name('index')
    ->middleware('permission:meu_modulo.view');

Route::post('/', [Controller::class, 'store'])
    ->name('store')
    ->middleware('permission:meu_modulo.create');
```

---

## Rotas

### Arquivo de Rotas do Módulo

```php
<?php
// app/Modules/MeuModulo/routes.php

use App\Http\Controllers\Api\V1\MeuModuloController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MeuModuloController::class, 'index'])
    ->name('index')
    ->middleware('permission:meu_modulo.view');

Route::post('/', [MeuModuloController::class, 'store'])
    ->name('store')
    ->middleware('permission:meu_modulo.create');

Route::get('/{item}', [MeuModuloController::class, 'show'])
    ->name('show')
    ->middleware('permission:meu_modulo.view');

Route::patch('/{item}', [MeuModuloController::class, 'update'])
    ->name('update')
    ->middleware('permission:meu_modulo.update');

Route::delete('/{item}', [MeuModuloController::class, 'destroy'])
    ->name('destroy')
    ->middleware('permission:meu_modulo.delete');

Route::patch('/{item}/status', [MeuModuloController::class, 'updateStatus'])
    ->name('status')
    ->middleware('permission:meu_modulo.status.update');
```

### Integração com api_v1.php

```php
// routes/api_v1.php

// ============================================
// Meu Módulo
// ============================================
Route::prefix('meu-modulo')->name('meu-modulo.')->group(function () {
    require app_path('Modules/MeuModulo/routes.php');
});
```

---

## Boas Práticas

### ✅ Faça

- **Use `declare(strict_types=1)`** em todos os arquivos PHP
- **Siga convenções de nomenclatura**:
  - Módulo: `PascalCase` (ex: `CapasPersonalizadas`)
  - ID: `kebab-case` (ex: `capas-personalizadas`)
  - Permissões: `snake_case` (ex: `capas_personalizadas.view`)
- **Documente o módulo** com PHPDoc na classe
- **Defina todos os status com cores e ícones**
- **Use middleware de permissão** em todas as rotas
- **Retorne arrays vazios** quando não há dados (não null)
- **Faça status finais realmente finais** (`'final' => true`)

### ❌ Evite

- IDs de módulos com acentos ou espaços
- Status sem cor ou ícone definidos
- Permissões sem `display_name` descritivo
- Transições circulares em status finais
- Controllers com lógica de autorização duplicada (use middleware)
- Models na pasta do módulo (mantenha em `app/Models/`)

### 📁 Onde Colocar Cada Arquivo

| Tipo | Localização |
|------|-------------|
| Módulo (classe) | `app/Modules/NomeDoModulo/` |
| Rotas do módulo | `app/Modules/NomeDoModulo/routes.php` |
| Controllers | `app/Http/Controllers/Api/V1/` |
| Models | `app/Models/` |
| Services | `app/Services/` |
| Requests | `app/Http/Requests/NomeDoModulo/` |
| Resources | `app/Http/Resources/` |
| Enums | `app/Enums/` |
| Migrations | `database/migrations/` |

---

## Checklist de Desenvolvimento

### Criação do Módulo

- [ ] Rodar `php artisan make:module NomeDoModulo --with-all`
- [ ] Customizar `getId()`, `getName()`, `getDescription()`, `getIcon()`
- [ ] Definir statuses em `getStatuses()`
- [ ] Definir transições em `getTransitions()`
- [ ] Definir matriz de roles em `getTransitionRoleMatrix()`
- [ ] Definir permissões em `getPermissions()`
- [ ] Definir screens em `getScreens()`
- [ ] Definir texts em `getTexts()` (opcional)
- [ ] Definir actions em `getActions()` (opcional)

### Integração

- [ ] Verificar registro no `ModuleRegistry.php`
- [ ] Adicionar rotas no `routes/api_v1.php`
- [ ] Rodar migrations se criadas
- [ ] Testar `php artisan route:list --path={modulo}`
- [ ] Testar `php artisan config:cache`

### Verificação

- [ ] Testar endpoints CRUD
- [ ] Testar transições de status
- [ ] Verificar permissões bloqueiam acesso
- [ ] Verificar módulo aparece na API: `GET /api/v1/admin/modules`

---

## Exemplos

### Módulo com Workflow (Pedidos, Capas)

Ver: `app/Modules/PedidosSimples/PedidosSimplesModule.php`

### Módulo CRUD Simples (Catálogo)

Ver: `app/Modules/CatalogoAparelhos/CatalogoAparelhosModule.php`

### Módulo sem Status

Para módulos que são apenas dados de referência (CRUD puro):

```php
public function getStatuses(): array
{
    return []; // Sem workflow
}

public function getTransitions(): array
{
    return [];
}

public function getTransitionRoleMatrix(): array
{
    return [];
}
```

---

## Comandos Úteis

```bash
# Criar módulo completo
php artisan make:module Devolucoes --with-all

# Verificar rotas do módulo
php artisan route:list --path=devolucoes

# Limpar caches
php artisan config:cache
php artisan route:cache

# Verificar se módulo foi registrado
php artisan tinker
>>> App\Modules\ModuleRegistry::getInstance()->boot();
>>> App\Modules\ModuleRegistry::getInstance()->get('devolucoes')?->toMinimalArray();
```

---

## Suporte

Dúvidas sobre desenvolvimento de módulos? Consulte:

1. Interface completa: `app/Modules/Contracts/ModuleInterface.php`
2. Implementação base: `app/Modules/BaseModule.php`
3. Comando de criação: `app/Console/Commands/MakeModuleCommand.php`
