# 📬 Respostas do Backend - Formatos de API

> **De:** Backend  
> **Para:** Time Frontend  
> **Data:** 16/01/2026  
> **Status:** ✅ TODAS AS PERGUNTAS RESPONDIDAS

---

## 📋 Índice

1. [GET /admin/permissions/grouped](#1-get-adminpermissionsgrouped)
2. [GET /admin/modules/{id}](#2-get-adminmodulesid)
3. [GET /admin/modules/{id}/stores](#3-get-adminmoduleidstores)
4. [GET /admin/roles/{id}](#4-get-adminrolesid)
5. [Graph API](#5-graph-api)
6. [TypeScript Interfaces](#6-typescript-interfaces)
7. [Sugestões UX/UI](#7-sugestões-uxui)

---

## 1. GET /admin/permissions/grouped

### ✅ Resposta Real

```typescript
// Wrapper: { "data": Array<ModuleGroup> }
interface PermissionsGroupedResponse {
  data: ModuleGroup[];
}

interface ModuleGroup {
  module: string;               // "pedidos", "capas", "admin"
  module_display: string;       // "Pedidos", "Capas Personalizadas"
  abilities: Permission[];      // Permissões de ação
  screens: Permission[];        // Permissões de tela
  features: Permission[];       // Features especiais
}

interface Permission {
  id: number;
  name: string;                 // "pedidos.view"
  display_name: string;         // "Ver pedidos"
  type: "ability" | "screen" | "feature";
  type_display: string;         // "Ação", "Tela", "Feature"
  module: string;
  module_display: string;
  description: string | null;
  sort_order: number;
}
```

### 📦 Exemplo de Resposta

```json
{
  "data": [
    {
      "module": "pedidos",
      "module_display": "Pedidos",
      "abilities": [
        {
          "id": 1,
          "name": "pedidos.view",
          "display_name": "Ver pedidos (próprios)",
          "type": "ability",
          "type_display": "Ação",
          "module": "pedidos",
          "module_display": "Pedidos",
          "description": null,
          "sort_order": 1
        }
      ],
      "screens": [
        {
          "id": 15,
          "name": "screen.pedidos",
          "display_name": "Menu Pedidos",
          "type": "screen",
          "type_display": "Tela",
          "module": "pedidos",
          "module_display": "Pedidos",
          "description": null,
          "sort_order": 15
        }
      ],
      "features": []
    }
  ]
}
```

### 🎯 Como Usar no Frontend

```typescript
// ❌ ERRADO - Formato antigo
const permissions = response as Record<string, Permission[]>;
permissions["pedidos"].filter(...);

// ✅ CORRETO - Formato atual
const { data } = response as PermissionsGroupedResponse;
const pedidosGroup = data.find(g => g.module === "pedidos");
const allPermissions = [...pedidosGroup.abilities, ...pedidosGroup.screens];
```

---

## 2. GET /admin/modules/{id}

### ✅ Resposta Real (Formato B - Objetos)

```typescript
interface ModuleResponse {
  data: ModuleDetail;
}

interface ModuleDetail {
  id: string;
  name: string;
  description: string;
  version: string;
  icon: string;                 // Lucide icon name
  is_core: boolean;
  dependencies: string[];
  statuses: Record<string, StatusConfig>;
  transitions: Record<string, number[]>;
  transition_matrix: TransitionMatrix;
  permissions: ModulePermission[];   // ← Array de OBJETOS, não strings!
  screens: ModuleScreen[];           // ← Array de OBJETOS com path!
  texts: ModuleTexts;
  actions: Record<string, ActionConfig>;
  permission_groups: Record<string, PermissionGroup>;
  conditional_fields: Record<string, FieldConfig>;
  automations: Automation[];
}

interface ModulePermission {
  name: string;           // "pedidos.view"
  display_name: string;   // "Ver pedidos (próprios)"
  type: "ability";
}

interface ModuleScreen {
  name: string;           // "screen.pedidos"
  display_name: string;   // "Menu Pedidos"
  path: string;           // "/pedidos"
}
```

### 📦 Exemplo de Resposta

```json
{
  "data": {
    "id": "pedidos-simples",
    "name": "Pedidos Simples",
    "description": "Gerenciamento de pedidos de encomenda",
    "version": "1.0.0",
    "icon": "FileCheck",
    "permissions": [
      { "name": "pedidos.view", "display_name": "Ver pedidos (próprios)", "type": "ability" },
      { "name": "pedidos.create", "display_name": "Criar pedido", "type": "ability" }
    ],
    "screens": [
      { "name": "screen.pedidos", "display_name": "Menu Pedidos", "path": "/pedidos" },
      { "name": "screen.pedidos.list", "display_name": "Lista de Pedidos", "path": "/pedidos" }
    ],
    "statuses": {
      "1": { "name": "solicitado", "label": "Solicitado", "color": "blue", "icon": "clipboard-list" },
      "3": { "name": "disponivel", "label": "Disponível na Loja", "color": "yellow", "icon": "store" }
    }
  }
}
```

### 🎯 Como Usar no Frontend

```typescript
// ❌ ERRADO - Esperando strings
const hasPermission = module.permissions.includes("pedidos.view");

// ✅ CORRETO - É array de objetos
const hasPermission = module.permissions.some(p => p.name === "pedidos.view");
const permissionNames = module.permissions.map(p => p.name);
```

---

## 3. GET /admin/modules/{id}/stores

### ✅ ENDPOINT CRIADO E FUNCIONANDO!

```
GET /api/v1/admin/modules/{moduleId}/stores
```

### 📦 Resposta Real

```json
{
  "module_id": "pedidos-simples",
  "module_name": "Pedidos Simples",
  "stores": [
    {
      "store_id": 1,
      "store_name": "Komprão Centro",
      "city": "Tijucas",
      "is_active": false,
      "activated_at": null,
      "config": []
    },
    {
      "store_id": 2,
      "store_name": "Komprão Morretes",
      "city": "Itapema",
      "is_active": true,
      "activated_at": "2026-01-15T10:00:00Z",
      "config": { "max_items": 50 }
    }
  ],
  "total": 14,
  "active_count": 1
}
```

### TypeScript

```typescript
interface ModuleStoresResponse {
  module_id: string;
  module_name: string;
  stores: ModuleStoreStatus[];
  total: number;
  active_count: number;
}

interface ModuleStoreStatus {
  store_id: number;
  store_name: string;
  city: string;
  is_active: boolean;
  activated_at: string | null;
  config: Record<string, unknown>;
}
```

---

## 4. GET /admin/roles/{id}

### ✅ Resposta Real

```typescript
interface RoleResponse {
  data: RoleDetail;
}

interface RoleDetail {
  id: number;
  name: string;                    // "super_admin"
  display_name: string;            // "Super Administrador"
  description: string;
  level: number;                   // 100 = super_admin, 40 = vendedor
  is_system: boolean;
  permissions: RolePermission[];   // ← Array de OBJETOS!
  created_at: string;
  updated_at: string;
}

interface RolePermission {
  id: number;
  name: string;
  display_name: string;
  type: "ability" | "screen" | "feature";
  module: string;
  // NÃO tem pivot!
}
```

### 📦 Exemplo de Resposta

```json
{
  "data": {
    "id": 2,
    "name": "super_admin",
    "display_name": "Super Administrador",
    "description": "Acesso total ao sistema.",
    "level": 100,
    "is_system": true,
    "permissions": [
      { "id": 1, "name": "pedidos.view", "display_name": "Ver pedidos", "type": "ability", "module": "pedidos" },
      { "id": 15, "name": "screen.pedidos", "display_name": "Menu Pedidos", "type": "screen", "module": "pedidos" }
    ],
    "created_at": "2026-01-16T11:47:56+00:00"
  }
}
```

### 🎯 Como Usar no Frontend

```typescript
// ✅ Extrair nomes das permissões
const permissionNames = role.permissions.map(p => p.name);

// ✅ Agrupar por módulo
const byModule = role.permissions.reduce((acc, p) => {
  if (!acc[p.module]) acc[p.module] = [];
  acc[p.module].push(p);
  return acc;
}, {} as Record<string, RolePermission[]>);
```

---

## 5. Graph API

### ✅ TODOS OS 5 ENDPOINTS FUNCIONANDO!

| Endpoint | Status | Descrição |
|----------|--------|-----------|
| `GET /admin/graph/overview` | ✅ OK | Hierarquia geral |
| `GET /admin/graph/role/{name}` | ✅ OK | Grafo de um cargo |
| `GET /admin/graph/user/{id}` | ✅ OK | Grafo de um usuário |
| `GET /admin/graph/store/{id}` | ✅ OK | Grafo de uma loja |
| `GET /admin/graph/module/{id}` | ✅ OK | Grafo de um módulo |

### 📦 Estrutura Confirmada

```typescript
interface GraphResponse {
  nodes: GraphNode[];
  edges: GraphEdge[];
  root?: string;
  summary: {
    total_nodes: number;
    total_edges: number;
    by_type: Record<string, number>;
  };
}

interface GraphNode {
  id: string;                           // "role-vendedor"
  type: "role" | "module" | "permission" | "screen" | "user" | "store";
  data: {
    label: string;
    icon: string;                       // Lucide icon name
    [key: string]: unknown;
  };
  position: { x: 0; y: 0 };            // ✅ SIM, presente!
}

interface GraphEdge {
  id: string;
  source: string;
  target: string;
  type: string;
  label?: string;
  animated?: boolean;
}
```

### 📦 Exemplo Real - /graph/overview

```json
{
  "nodes": [
    {
      "id": "role-super_admin",
      "type": "role",
      "data": {
        "label": "Super Administrador",
        "icon": "Shield",
        "level": 100,
        "is_system": true,
        "permissions_count": 114,
        "users_count": 0
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "module-pedidos-simples",
      "type": "module",
      "data": {
        "label": "Pedidos Simples",
        "icon": "FileCheck",
        "is_active": true,
        "status_count": 6,
        "permission_count": 11
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "store-1",
      "type": "store",
      "data": {
        "label": "Komprão Centro",
        "icon": "Store",
        "city": "Tijucas",
        "users_count": 0
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "role-super_admin", "target": "role-admin", "type": "hierarchy" },
    { "id": "e2", "source": "role-admin", "target": "module-pedidos-simples", "type": "has_access" }
  ],
  "summary": {
    "total_nodes": 23,
    "total_edges": 30,
    "by_type": { "role": 7, "module": 2, "store": 14 }
  }
}
```

---

## 6. TypeScript Interfaces

### Arquivo: `src/types/api/permissions.ts`

```typescript
// ================================
// Permissions API
// ================================

export interface Permission {
  id: number;
  name: string;
  display_name: string;
  type: 'ability' | 'screen' | 'feature';
  type_display: string;
  module: string;
  module_display: string;
  description: string | null;
  sort_order: number;
}

export interface ModuleGroup {
  module: string;
  module_display: string;
  abilities: Permission[];
  screens: Permission[];
  features: Permission[];
}

export interface PermissionsGroupedResponse {
  data: ModuleGroup[];
}

// ================================
// Modules API
// ================================

export interface ModulePermission {
  name: string;
  display_name: string;
  type: 'ability';
}

export interface ModuleScreen {
  name: string;
  display_name: string;
  path: string;
}

export interface StatusConfig {
  name: string;
  label: string;
  description: string;
  color: string;
  icon: string;
  badge_variant: string;
  can_edit: boolean;
  final: boolean;
}

export interface ModuleDetail {
  id: string;
  name: string;
  description: string;
  version: string;
  icon: string;
  is_core: boolean;
  dependencies: string[];
  statuses: Record<string, StatusConfig>;
  transitions: Record<string, number[]>;
  transition_matrix: Record<string, Record<string, string[]>>;
  permissions: ModulePermission[];
  screens: ModuleScreen[];
  texts: Record<string, string>;
  actions: Record<string, ActionConfig>;
  automations: Automation[];
}

export interface ModuleStoreStatus {
  store_id: number;
  store_name: string;
  city: string;
  is_active: boolean;
  activated_at: string | null;
  config: Record<string, unknown>;
}

export interface ModuleStoresResponse {
  module_id: string;
  module_name: string;
  stores: ModuleStoreStatus[];
  total: number;
  active_count: number;
}

// ================================
// Roles API
// ================================

export interface RolePermission {
  id: number;
  name: string;
  display_name: string;
  type: 'ability' | 'screen' | 'feature';
  module: string;
}

export interface Role {
  id: number;
  name: string;
  display_name: string;
  description: string;
  level: number;
  is_system: boolean;
  permissions: RolePermission[];
  created_at: string;
  updated_at: string;
}

// ================================
// Graph API
// ================================

export type NodeType = 'role' | 'module' | 'permission' | 'screen' | 'user' | 'store';

export interface GraphNode {
  id: string;
  type: NodeType;
  data: {
    label: string;
    icon: string;
    [key: string]: unknown;
  };
  position: { x: number; y: number };
}

export interface GraphEdge {
  id: string;
  source: string;
  target: string;
  type: string;
  label?: string;
  animated?: boolean;
}

export interface GraphResponse {
  nodes: GraphNode[];
  edges: GraphEdge[];
  root?: string;
  summary: {
    total_nodes: number;
    total_edges: number;
    by_type: Record<NodeType, number>;
  };
}
```

---

## 7. Sugestões UX/UI

### 🎨 Permissões - Interface Sugerida

```
┌─────────────────────────────────────────────────────────────┐
│ Gerenciar Permissões                                        │
├─────────────────────────────────────────────────────────────┤
│ 🔍 Buscar permissão...                    [Filtrar por tipo]│
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 📦 Pedidos (6 permissões)                               [▼] │
│ ├── ⚡ Ações                                                │
│ │   ├── ☑ pedidos.view       Ver pedidos                   │
│ │   ├── ☑ pedidos.create     Criar pedido                  │
│ │   └── ☐ pedidos.delete     Deletar pedido                │
│ └── 🖥️ Telas                                               │
│     ├── ☑ screen.pedidos     Menu Pedidos                  │
│     └── ☑ screen.pedidos.list Lista de Pedidos             │
│                                                             │
│ 📦 Capas Personalizadas (8 permissões)                  [▶] │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 🎨 Graph - Cores por Tipo

```typescript
const nodeColors: Record<NodeType, string> = {
  role: '#3B82F6',       // Blue
  module: '#F97316',     // Orange
  permission: '#6B7280', // Gray
  screen: '#8B5CF6',     // Purple
  user: '#EAB308',       // Yellow
  store: '#22C55E',      // Green
};
```

### 🎨 Módulos por Loja - Toggle List

```
┌─────────────────────────────────────────────────────────────┐
│ Pedidos Simples - Ativação por Loja                         │
├─────────────────────────────────────────────────────────────┤
│                                                  Ativo | Config │
│ 🏪 Komprão Centro (Tijucas)                      [ON]  | ⚙️   │
│ 🏪 Komprão Morretes (Itapema)                    [OFF] | ⚙️   │
│ 🏪 PB Outlet (Porto Belo)                        [ON]  | ⚙️   │
├─────────────────────────────────────────────────────────────┤
│ Ativas: 2/14                        [Ativar Todas] [Salvar] │
└─────────────────────────────────────────────────────────────┘
```

---

## ✅ Resumo das Respostas

| Pergunta | Resposta |
|----------|----------|
| Formato de `/permissions/grouped`? | Array de `ModuleGroup[]` com abilities/screens/features |
| Wrapper `{ data: ... }`? | ✅ Sim, sempre |
| `permissions` em módulos são strings? | ❌ Não, são objetos com `name`, `display_name`, `type` |
| `/modules/{id}/stores` existe? | ✅ Sim, criado agora! |
| Graph API pronta? | ✅ Sim, 5 endpoints funcionando |
| `position: {x: 0, y: 0}` nos nodes? | ✅ Sim, presente |

---

*Backend Team - MaisCapinhas - 16/01/2026*
