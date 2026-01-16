# 🚀 Enhanced Module System - Roadmap v2

> **Data:** 16/01/2026  
> **Objetivo:** Melhorar DX, UX Super Admin, e granularidade do sistema modular

---

## 1. O Que Já Temos ✅

| Item | Status |
|------|--------|
| `ModuleInterface` | ✅ Base funcional |
| `BaseModule` | ✅ Helpers implementados |
| 2 Módulos | ✅ Pedidos + Capas |
| Migrations | ✅ 3 tabelas |
| API Admin | ✅ CRUD + transitions |

---

## 2. Melhorias Propostas

### 2.1 📝 Textos e Descrições para Frontend

**Problema:** O frontend precisa de textos para menus, tooltips, placeholders.

**Solução:** Adicionar `getTexts()` no módulo:

```php
public function getTexts(): array
{
    return [
        'menu_label' => 'Pedidos',
        'menu_tooltip' => 'Gerenciar pedidos de encomenda',
        'page_title' => 'Pedidos de Encomenda',
        'page_description' => 'Acompanhe todos os pedidos de encomenda...',
        'create_button' => 'Novo Pedido',
        'empty_state' => 'Nenhum pedido encontrado.',
        'filters' => [
            'status' => 'Filtrar por status',
            'seller' => 'Filtrar por vendedor',
            'date_range' => 'Período',
        ],
    ];
}
```

---

### 2.2 🎯 Actions com Tooltips

**Problema:** Cada ação (botão) precisa de tooltip, confirmação, shortcut.

**Solução:** Adicionar `getActions()`:

```php
public function getActions(): array
{
    return [
        'create' => [
            'label' => 'Novo Pedido',
            'icon' => 'Plus',
            'tooltip' => 'Criar um novo pedido de encomenda',
            'shortcut' => 'N',
            'permission' => 'pedidos.create',
        ],
        'avisar_cliente' => [
            'label' => 'Avisar Cliente',
            'icon' => 'Bell',
            'tooltip' => 'Enviar notificação WhatsApp para o cliente',
            'confirm' => false, // Ação direta
            'permission' => 'pedidos.status.to-aguardando',
            'available_in_status' => [3], // disponivel
        ],
        'cancelar' => [
            'label' => 'Cancelar Pedido',
            'icon' => 'X',
            'tooltip' => 'Cancelar este pedido (requer justificativa)',
            'confirm' => true,
            'confirm_title' => 'Cancelar Pedido',
            'confirm_message' => 'Tem certeza? Esta ação não pode ser desfeita.',
            'permission' => 'pedidos.cancel',
            'requires_fields' => ['cancelation_reason'],
        ],
    ];
}
```

---

### 2.3 📊 Status com Descrições

**Problema:** Status precisam de descrição completa para tooltips.

**Melhoria nos status:**

```php
1 => [
    'name' => 'solicitado',
    'label' => 'Solicitado',
    'description' => 'Pedido criado, aguardando processamento pelo administrativo.',
    'color' => 'blue',
    'icon' => 'clipboard-list',
    'badge_variant' => 'secondary',
    'can_edit' => true,  // Pode editar dados do pedido neste status
    'final' => false,
],
```

---

### 2.4 📋 Documentação do Módulo

**Solução:** Adicionar `getDocumentation()`:

```php
public function getDocumentation(): array
{
    return [
        'overview' => 'O módulo de Pedidos Simples gerencia encomendas...',
        'workflow' => [
            'title' => 'Fluxo do Pedido',
            'steps' => [
                '1. Vendedor cria o pedido',
                '2. Admin marca como disponível quando produto chega',
                '3. Vendedor avisa o cliente via WhatsApp',
                '4. Cliente retira e vendedor finaliza a venda',
            ],
        ],
        'roles' => [
            'vendedor' => 'Cria pedidos, avisa cliente, finaliza venda',
            'admin' => 'Marca disponível, gerencia todos os pedidos',
            'gerente' => 'Igual ao admin, pode ver relatórios',
        ],
        'faq' => [
            'Como cancelar?' => 'Clique em Cancelar e informe o motivo.',
            'O que acontece após 20 dias?' => 'O pedido é cancelado automaticamente.',
        ],
    ];
}
```

---

### 2.5 🏗️ Templates para Novos Módulos

**Criar comando artisan:**

```bash
php artisan module:make NomeDoModulo
```

Gera automaticamente:
- Pasta `app/Modules/NomeDoModulo/`
- Arquivo `NomeDoModuloModule.php` com template
- Registra no `ModuleRegistry`

---

### 2.6 🔐 Permissions Agrupadas por Categoria

**Problema:** Permissions são lista flat, difícil de organizar na UI.

**Solução:** Adicionar `getPermissionGroups()`:

```php
public function getPermissionGroups(): array
{
    return [
        'visualizacao' => [
            'label' => 'Visualização',
            'icon' => 'Eye',
            'permissions' => ['pedidos.view', 'pedidos.view-all', 'pedidos.view-global'],
        ],
        'crud' => [
            'label' => 'Gerenciamento',
            'icon' => 'Edit',
            'permissions' => ['pedidos.create', 'pedidos.update', 'pedidos.cancel'],
        ],
        'status' => [
            'label' => 'Transições de Status',
            'icon' => 'ArrowRight',
            'permissions' => ['pedidos.status.to-disponivel', 'pedidos.status.to-aguardando', 'pedidos.status.to-concluido'],
        ],
        'notificacoes' => [
            'label' => 'Notificações',
            'icon' => 'Bell',
            'permissions' => ['pedidos.send-whatsapp'],
        ],
    ];
}
```

---

### 2.7 🖥️ API Completa para Frontend

**Novo endpoint:**

```
GET /api/v1/admin/modules/{id}/full
```

Retorna TUDO que o frontend precisa:

```json
{
  "id": "pedidos-simples",
  "name": "Pedidos Simples",
  "icon": "FileCheck",
  "texts": { ... },
  "actions": { ... },
  "statuses": { ... },
  "transitions": { ... },
  "role_matrix": { ... },
  "permissions": { ... },
  "permission_groups": { ... },
  "conditional_fields": { ... },
  "automations": { ... },
  "documentation": { ... }
}
```

---

## 3. Prioridades de Implementação

| # | Item | Prioridade | Esforço |
|---|------|------------|---------|
| 1 | `getTexts()` | 🔴 Alta | Baixo |
| 2 | `getActions()` | 🔴 Alta | Médio |
| 3 | Status com `description` | 🔴 Alta | Baixo |
| 4 | `getPermissionGroups()` | 🟡 Média | Baixo |
| 5 | `getDocumentation()` | 🟡 Média | Médio |
| 6 | Comando `module:make` | 🟡 Média | Médio |
| 7 | Endpoint `/full` | 🟢 Baixa | Baixo |

---

## 4. UI Super Admin Sugerida

### Tela de Módulos

```
┌─────────────────────────────────────────────────────────────────────────┐
│  GESTÃO DE MÓDULOS                                                      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  📦 Pedidos Simples       v1.0.0         [Configurar ▼]                 │
│  ├── 📊 6 status                                                        │
│  ├── 🔐 11 permissions (4 grupos)                                       │
│  ├── ⚡ 3 automações                                                    │
│  └── ✅ Ativo em 5 lojas                                                │
│                                                                         │
│  📦 Capas Personalizadas  v1.0.0         [Configurar ▼]                 │
│  ├── 📊 10 status                                                       │
│  ├── 🔐 16 permissions                                                  │
│  └── ✅ Ativo em 5 lojas                                                │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Tela de Configuração de Módulo

```
┌─────────────────────────────────────────────────────────────────────────┐
│  CONFIGURAR: Pedidos Simples                                            │
├─────────────────────────────────────────────────────────────────────────┤
│  [Geral] [Status] [Transições] [Permissions] [Ações] [Textos] [Docs]    │
│  ═══════════════════════════════════════════════════════════════════    │
│                                                                         │
│  Tab: TRANSIÇÕES                                                        │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ Solicitado → Disponível na Loja                                   │  │
│  │ Quem pode: [x] Admin [x] Gerente [ ] Vendedor [x] Super Admin     │  │
│  ├───────────────────────────────────────────────────────────────────│  │
│  │ Disponível na Loja → Aguardando Cliente                           │  │
│  │ Quem pode: [x] Admin [x] Gerente [x] Vendedor [x] Super Admin     │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  Tab: AÇÕES                                                             │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ Avisar Cliente                                                    │  │
│  │ Tooltip: [Enviar notificação WhatsApp para o cliente_____________]│  │
│  │ Confirmação: ( ) Sim (●) Não                                      │  │
│  │ Atalho: [N]                                                       │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 5. Resumo: Arquivos a Criar/Modificar

| Arquivo | Ação |
|---------|------|
| `ModuleInterface.php` | Adicionar novos métodos |
| `BaseModule.php` | Defaults para novos métodos |
| `PedidosSimplesModule.php` | Implementar novos métodos |
| `CapasPersonalizadasModule.php` | Implementar novos métodos |
| `ModuleController.php` | Endpoint `/full` |
| `MakeModuleCommand.php` | Novo comando artisan |
