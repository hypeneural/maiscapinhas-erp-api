# 📊 Guia Completo: Graph API + Módulos + Permissões

> **Versão:** 1.0  
> **Data:** 16/01/2026  
> **Audiência:** Time Frontend

---

## 📚 Índice

1. [Visão Geral do Sistema](#visão-geral-do-sistema)
2. [Hierarquia de Permissões](#hierarquia-de-permissões)
3. [Estrutura de Módulos](#estrutura-de-módulos)
4. [Graph API - Endpoints](#graph-api---endpoints)
5. [JSON Schemas](#json-schemas)
6. [Sugestões de UI/UX](#sugestões-de-uiux)
7. [Integração com React Flow](#integração-com-react-flow)

---

## 🏗️ Visão Geral do Sistema

### Conceitos Principais

```
┌─────────────────────────────────────────────────────────────┐
│                        SUPER ADMIN                          │
│  (Controle total do sistema - pode tudo)                    │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┴─────────────────────┐
        │                                           │
┌───────▼───────┐                           ┌───────▼───────┐
│     ROLES     │                           │    MODULES    │
│ (Cargos/Papéis)│                          │  (Funcional.) │
└───────┬───────┘                           └───────┬───────┘
        │                                           │
        │  ┌────────────────────────────────────────┤
        │  │                                        │
┌───────▼──▼────┐  ┌──────────────┐  ┌─────────────▼─────────────┐
│  PERMISSIONS  │  │    STORES    │  │        STATUSES           │
│ (O que pode   │  │   (Lojas)    │  │   (Estados do registro)   │
│   fazer)      │  └──────┬───────┘  └───────────────────────────┘
└───────────────┘         │
                          │
                  ┌───────▼───────┐
                  │     USERS     │
                  │  (Usuários)   │
                  └───────────────┘
```

---

## 🔐 Hierarquia de Permissões

### Níveis de Acesso (Roles)

| Role | Level | Descrição | Herda de |
|------|-------|-----------|----------|
| `super-admin` | 100 | Controle total | - |
| `admin` | 90 | Administrador | super-admin |
| `gerente` | 70 | Gerente de loja | admin |
| `fabrica` | 60 | Equipe de produção | admin |
| `conferente` | 50 | Confere fechamentos | gerente |
| `vendedor` | 40 | Vendedor | gerente |

### Fluxo de Resolução de Permissões

```
Quando verificamos se usuário X pode fazer ação Y:

1. É Super Admin? → ✅ Sim, pode tudo
                 ↓ Não
2. Tem Override DENY? → ❌ Negado explicitamente
                     ↓ Não
3. Tem Override GRANT? → ✅ Liberado explicitamente
                      ↓ Não
4. Alguma Role tem essa permissão? → ✅/❌
```

### Tipos de Permissões

```typescript
type PermissionType = 'ability' | 'screen' | 'feature';
```

| Tipo | Prefixo | Exemplo | Descrição |
|------|---------|---------|-----------|
| `ability` | `{módulo}.{ação}` | `pedidos.create` | Ações que o usuário pode executar |
| `screen` | `screen.{área}` | `screen.pedidos` | Telas que pode acessar no menu |
| `feature` | `feature.{nome}` | `feature.whatsapp` | Funcionalidades especiais |

---

## 📦 Estrutura de Módulos

### O que é um Módulo?

Um **módulo** é uma unidade funcional do sistema. Cada módulo define:

- **Statuses**: Estados pelos quais um registro pode passar
- **Transitions**: Quais transições são permitidas e por quem
- **Permissions**: Ações e telas relacionadas
- **UI Config**: Textos, ícones, filtros, colunas

### Módulos Disponíveis

| ID | Nome | Descrição | Ícone |
|----|------|-----------|-------|
| `pedidos-simples` | Pedidos Simples | Gestão de pedidos básicos | ShoppingCart |
| `capas-personalizadas` | Capas Personalizadas | Capas com foto do cliente | Image |

### Estrutura Completa de um Módulo

```json
{
  "id": "pedidos-simples",
  "name": "Pedidos Simples",
  "description": "Gestão de pedidos básicos",
  "version": "1.0.0",
  "icon": "ShoppingCart",
  "dependencies": [],
  
  "statuses": {
    "1": {
      "id": 1,
      "name": "Novo",
      "color": "#3B82F6",
      "icon": "Plus",
      "description": "Pedido recém criado"
    },
    "2": {
      "id": 2,
      "name": "Em Processamento",
      "color": "#F97316",
      "icon": "Clock"
    },
    "3": {
      "id": 3,
      "name": "Disponível",
      "color": "#22C55E",
      "icon": "Check"
    }
  },
  
  "transitions": {
    "1": [2, 6],
    "2": [3, 6],
    "3": [4, 5]
  },
  
  "transition_role_matrix": {
    "1": {
      "2": ["admin", "gerente", "vendedor"],
      "6": ["admin", "gerente"]
    }
  },
  
  "permissions": [
    { "name": "pedidos.view", "display_name": "Ver pedidos", "type": "ability" },
    { "name": "pedidos.create", "display_name": "Criar pedidos", "type": "ability" },
    { "name": "pedidos.delete", "display_name": "Deletar pedidos", "type": "ability" }
  ],
  
  "screens": [
    { "name": "screen.pedidos", "display_name": "Menu Pedidos" }
  ],
  
  "texts": {
    "page_title": "Pedidos",
    "page_description": "Gestão de pedidos simples",
    "create_button": "Novo Pedido",
    "empty_state": "Nenhum pedido encontrado",
    "loading_title": "Carregando pedidos...",
    "error_title": "Erro ao carregar"
  },
  
  "actions": {
    "create": {
      "label": "Novo Pedido",
      "icon": "Plus",
      "shortcut": "N",
      "permission": "pedidos.create"
    }
  },
  
  "filters": [
    { "id": "status", "type": "select", "label": "Status", "options": [...] },
    { "id": "date_range", "type": "daterange", "label": "Período" }
  ],
  
  "table_columns": {
    "default": [
      { "id": "id", "label": "#", "width": 80 },
      { "id": "customer", "label": "Cliente" },
      { "id": "status", "label": "Status" }
    ]
  }
}
```

---

## 🔗 Graph API - Endpoints

### 1. Overview do Sistema

```http
GET /api/v1/admin/graph/overview
```

**Query Params:**

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `depth` | int | 3 | Profundidade máxima do grafo |
| `include_users` | bool | false | Incluir nós de usuários |

**Lógica:**

1. Busca todas as Roles ordenadas por nível
2. Monta hierarquia (vendedor → gerente → admin → super-admin)
3. Busca todos os módulos ativos
4. Conecta roles aos módulos que eles têm acesso
5. Opcionalmente adiciona lojas e usuários

**Response:**

```json
{
  "nodes": [
    {
      "id": "role-super-admin",
      "type": "role",
      "data": {
        "label": "Super Admin",
        "icon": "Shield",
        "level": 100,
        "is_system": true,
        "permissions_count": 45,
        "users_count": 1
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "role-admin",
      "type": "role",
      "data": {
        "label": "Administrador",
        "icon": "Shield",
        "level": 90,
        "permissions_count": 40,
        "users_count": 3
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "module-pedidos-simples",
      "type": "module",
      "data": {
        "label": "Pedidos Simples",
        "icon": "ShoppingCart",
        "is_active": true,
        "status_count": 6,
        "permission_count": 12
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    {
      "id": "e1",
      "source": "role-super-admin",
      "target": "role-admin",
      "type": "hierarchy"
    },
    {
      "id": "e2",
      "source": "role-admin",
      "target": "module-pedidos-simples",
      "type": "has_access"
    }
  ],
  "summary": {
    "total_nodes": 15,
    "total_edges": 22,
    "by_type": {
      "role": 6,
      "module": 2,
      "store": 5
    },
    "depth": 3
  },
  "metadata": {
    "generated_at": "2026-01-16T13:00:00Z",
    "filters": {
      "depth": 3,
      "include_users": false
    }
  }
}
```

---

### 2. Grafo de um Cargo (Role)

```http
GET /api/v1/admin/graph/role/{roleName}
```

**Exemplo:** `GET /api/v1/admin/graph/role/vendedor`

**Query Params:**

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `include_users` | bool | false | Incluir usuários com esse cargo |
| `include_permissions` | bool | true | Incluir permissões individuais |

**Lógica:**

1. Busca a Role com suas permissões
2. Agrupa permissões por módulo
3. Cria nó para cada módulo
4. Cria nó para cada permissão (se solicitado)
5. Conecta usuários (se solicitado)

**Response:**

```json
{
  "nodes": [
    {
      "id": "role-vendedor",
      "type": "role",
      "data": {
        "label": "Vendedor",
        "icon": "Shield",
        "level": 40,
        "is_system": true,
        "permissions_count": 12,
        "users_count": 25,
        "description": "Vendedor de loja"
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "module-pedidos",
      "type": "module",
      "data": {
        "label": "Pedidos",
        "icon": "Package",
        "permissions_count": 5
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "perm-pedidos.view",
      "type": "permission",
      "data": {
        "label": "Ver Pedidos",
        "icon": "Key",
        "type": "ability",
        "granted": true
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "perm-pedidos.create",
      "type": "permission",
      "data": {
        "label": "Criar Pedidos",
        "icon": "Key",
        "type": "ability",
        "granted": true
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "screen-pedidos",
      "type": "screen",
      "data": {
        "label": "Menu Pedidos",
        "icon": "Monitor",
        "granted": true
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "role-vendedor", "target": "module-pedidos", "type": "has_access" },
    { "id": "e2", "source": "module-pedidos", "target": "perm-pedidos.view", "type": "contains" },
    { "id": "e3", "source": "module-pedidos", "target": "perm-pedidos.create", "type": "contains" },
    { "id": "e4", "source": "module-pedidos", "target": "screen-pedidos", "type": "contains" }
  ],
  "root": "role-vendedor",
  "summary": {
    "total_nodes": 8,
    "total_edges": 7,
    "by_type": {
      "role": 1,
      "module": 2,
      "permission": 4,
      "screen": 1
    },
    "modules": 2,
    "permissions": 12,
    "users": 25
  }
}
```

---

### 3. Grafo de um Usuário

```http
GET /api/v1/admin/graph/user/{userId}
```

**Exemplo:** `GET /api/v1/admin/graph/user/1`

**Query Params:**

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `include_inherited` | bool | true | Mostrar origem de cada permissão |

**Lógica:**

1. Busca usuário com roles, overrides e lojas
2. Cria nó central do usuário
3. Conecta às roles que ele possui
4. Conecta às lojas onde trabalha (com papel na loja)
5. Adiciona overrides como nós especiais (animados se temporários)
6. Calcula permissões efetivas

**Response:**

```json
{
  "nodes": [
    {
      "id": "user-1",
      "type": "user",
      "data": {
        "label": "João Silva",
        "icon": "User",
        "email": "joao@loja.com",
        "is_super_admin": false,
        "roles": ["vendedor"],
        "stores_count": 2
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "role-vendedor",
      "type": "role",
      "data": {
        "label": "Vendedor",
        "icon": "Shield",
        "level": 40,
        "permissions_count": 12
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "store-1",
      "type": "store",
      "data": {
        "label": "Loja Tijucas",
        "icon": "Store",
        "city": "Tijucas",
        "role_in_store": "vendedor"
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "store-2",
      "type": "store",
      "data": {
        "label": "Loja Itapema",
        "icon": "Store",
        "city": "Itapema",
        "role_in_store": "gerente"
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "override-123",
      "type": "permission",
      "data": {
        "label": "capas.view-global",
        "icon": "Key",
        "is_override": true,
        "is_temporary": true,
        "expires_at": "2026-02-01T23:59:59Z",
        "reason": "Cobertura de férias",
        "granted_by": "Admin Maria"
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "user-1", "target": "role-vendedor", "type": "has_role" },
    { "id": "e2", "source": "user-1", "target": "store-1", "type": "works_at", "label": "vendedor" },
    { "id": "e3", "source": "user-1", "target": "store-2", "type": "works_at", "label": "gerente" },
    { "id": "e4", "source": "user-1", "target": "override-123", "type": "override_grant", "animated": true }
  ],
  "root": "user-1",
  "summary": {
    "total_nodes": 5,
    "total_edges": 4,
    "by_type": {
      "user": 1,
      "role": 1,
      "store": 2,
      "permission": 1
    },
    "roles": 1,
    "stores": 2,
    "overrides": 1,
    "effective_permissions": 13
  },
  "effective_permissions": [
    { "name": "pedidos.view", "source": "role", "source_name": "vendedor" },
    { "name": "pedidos.create", "source": "role", "source_name": "vendedor" },
    { "name": "capas.view-global", "source": "override", "expires_at": "2026-02-01T23:59:59Z" }
  ]
}
```

---

### 4. Grafo de uma Loja

```http
GET /api/v1/admin/graph/store/{storeId}
```

**Query Params:**

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `include_users` | bool | true | Incluir usuários da loja |

**Response:**

```json
{
  "nodes": [
    {
      "id": "store-1",
      "type": "store",
      "data": {
        "label": "Loja Tijucas",
        "icon": "Store",
        "city": "Tijucas",
        "users_count": 5,
        "modules_count": 2
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "module-pedidos-simples",
      "type": "module",
      "data": {
        "label": "Pedidos Simples",
        "icon": "ShoppingCart"
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "user-1",
      "type": "user",
      "data": {
        "label": "João Silva",
        "email": "joao@...",
        "role_in_store": "vendedor"
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "store-1", "target": "module-pedidos-simples", "type": "has_module" },
    { "id": "e2", "source": "store-1", "target": "user-1", "type": "has_user", "label": "vendedor" }
  ],
  "root": "store-1",
  "summary": {
    "users": 5,
    "modules": 2
  }
}
```

---

### 5. Grafo de um Módulo

```http
GET /api/v1/admin/graph/module/{moduleId}
```

**Response:**

```json
{
  "nodes": [
    {
      "id": "module-pedidos-simples",
      "type": "module",
      "data": {
        "label": "Pedidos Simples",
        "icon": "ShoppingCart",
        "is_active": true,
        "version": "1.0.0"
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "perm-pedidos.view",
      "type": "permission",
      "data": { "label": "Ver Pedidos", "type": "ability" },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "screen-pedidos",
      "type": "screen",
      "data": { "label": "Menu Pedidos" },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "role-admin",
      "type": "role",
      "data": {
        "label": "Admin",
        "permissions_in_module": 8,
        "total_permissions": 8
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "role-vendedor",
      "type": "role",
      "data": {
        "label": "Vendedor",
        "permissions_in_module": 5,
        "total_permissions": 8
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "module-pedidos-simples", "target": "perm-pedidos.view", "type": "contains" },
    { "id": "e2", "source": "module-pedidos-simples", "target": "screen-pedidos", "type": "contains" },
    { "id": "e3", "source": "role-admin", "target": "module-pedidos-simples", "type": "has_access", "label": "8/8" },
    { "id": "e4", "source": "role-vendedor", "target": "module-pedidos-simples", "type": "has_access", "label": "5/8" }
  ],
  "root": "module-pedidos-simples",
  "summary": {
    "permissions": 8,
    "screens": 1
  }
}
```

---

## 📐 JSON Schemas

### GraphNode

```typescript
interface GraphNode {
  id: string;                           // Único, formato: "{type}-{identifier}"
  type: NodeType;                       // Tipo do nó
  data: {
    label: string;                      // Nome para exibir
    icon: string;                       // Nome do ícone Lucide
    [key: string]: unknown;             // Metadados específicos do tipo
  };
  position: { x: number; y: number };   // Sempre {0,0} - vocês calculam
}

type NodeType = 'role' | 'module' | 'permission' | 'screen' | 'user' | 'store';
```

### GraphEdge

```typescript
interface GraphEdge {
  id: string;                           // Único, formato: "e{number}"
  source: string;                       // ID do nó de origem
  target: string;                       // ID do nó de destino
  type: EdgeType;                       // Tipo da conexão
  label?: string;                       // Rótulo opcional na aresta
  animated?: boolean;                   // Animação para overrides temporários
}

type EdgeType = 
  | 'hierarchy'       // Role → Role (pai → filho)
  | 'has_access'      // Role → Module
  | 'contains'        // Module → Permission/Screen
  | 'has_user'        // Role/Store → User
  | 'has_role'        // User → Role
  | 'works_at'        // User → Store
  | 'has_module'      // Store → Module
  | 'override_grant'  // User → Permission (override positivo)
  | 'override_deny';  // User → Permission (override negativo)
```

### GraphResponse

```typescript
interface GraphResponse {
  nodes: GraphNode[];
  edges: GraphEdge[];
  root?: string;                        // ID do nó raiz (quando aplicável)
  summary: {
    total_nodes: number;
    total_edges: number;
    by_type: Record<NodeType, number>;
    [key: string]: unknown;             // Métricas específicas do endpoint
  };
  metadata?: {
    generated_at: string;
    filters: Record<string, unknown>;
  };
  effective_permissions?: EffectivePermission[]; // Apenas em /graph/user
}

interface EffectivePermission {
  name: string;
  source: 'role' | 'override';
  source_name?: string;                  // Nome da role se source='role'
  expires_at?: string;                   // ISO8601 se source='override' temporário
}
```

---

## 🎨 Sugestões de UI/UX

### 1. Cores por Tipo de Nó

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

### 2. Ícones Lucide por Tipo

```typescript
const nodeIcons: Record<NodeType, string> = {
  role: 'Shield',
  module: 'Package',
  permission: 'Key',
  screen: 'Monitor',
  user: 'User',
  store: 'Store',
};
```

### 3. Estilos de Aresta

```typescript
const edgeStyles: Record<EdgeType, React.CSSProperties> = {
  hierarchy: { stroke: '#94A3B8', strokeWidth: 2 },
  has_access: { stroke: '#22C55E', strokeWidth: 2 },
  contains: { stroke: '#D1D5DB', strokeWidth: 1, strokeDasharray: '5,5' },
  has_user: { stroke: '#EAB308', strokeWidth: 1 },
  has_role: { stroke: '#3B82F6', strokeWidth: 2 },
  works_at: { stroke: '#22C55E', strokeWidth: 1, strokeDasharray: '3,3' },
  override_grant: { stroke: '#22C55E', strokeWidth: 2 },
  override_deny: { stroke: '#EF4444', strokeWidth: 2 },
};
```

### 4. Nós Customizados

```tsx
// components/graph/RoleNode.tsx
import { Handle, Position } from 'reactflow';
import { Shield } from 'lucide-react';

export function RoleNode({ data }: { data: any }) {
  return (
    <div className="bg-blue-50 border-2 border-blue-500 rounded-lg p-3 min-w-[150px]">
      <Handle type="target" position={Position.Top} />
      
      <div className="flex items-center gap-2">
        <Shield className="w-5 h-5 text-blue-600" />
        <span className="font-semibold text-blue-900">{data.label}</span>
      </div>
      
      <div className="mt-2 text-xs text-gray-600">
        <div>Level: {data.level}</div>
        <div>{data.permissions_count} permissões</div>
        <div>{data.users_count} usuários</div>
      </div>
      
      <Handle type="source" position={Position.Bottom} />
    </div>
  );
}
```

### 5. Layout com Dagre

```typescript
import dagre from 'dagre';
import { Node, Edge } from 'reactflow';

export function calculateLayout(nodes: Node[], edges: Edge[]): Node[] {
  const g = new dagre.graphlib.Graph();
  
  g.setGraph({ 
    rankdir: 'TB',      // Top to Bottom
    nodesep: 80,        // Espaçamento horizontal
    ranksep: 100,       // Espaçamento vertical
    marginx: 40,
    marginy: 40,
  });
  
  g.setDefaultEdgeLabel(() => ({}));

  // Adiciona nós
  nodes.forEach((node) => {
    g.setNode(node.id, { 
      width: 180, 
      height: 100,
    });
  });

  // Adiciona arestas
  edges.forEach((edge) => {
    g.setEdge(edge.source, edge.target);
  });

  // Calcula layout
  dagre.layout(g);

  // Retorna nós com posições calculadas
  return nodes.map((node) => {
    const nodeWithPosition = g.node(node.id);
    return {
      ...node,
      position: {
        x: nodeWithPosition.x - 90,  // Centraliza
        y: nodeWithPosition.y - 50,
      },
    };
  });
}
```

### 6. Componente Principal

```tsx
// pages/admin/PermissionGraph.tsx
import { useCallback, useEffect, useState } from 'react';
import ReactFlow, {
  Background,
  Controls,
  MiniMap,
  Node,
  Edge,
  useNodesState,
  useEdgesState,
} from 'reactflow';
import 'reactflow/dist/style.css';

import { api } from '@/lib/api';
import { calculateLayout } from '@/lib/graph-layout';
import { RoleNode, ModuleNode, PermissionNode, UserNode, StoreNode } from './nodes';

const nodeTypes = {
  role: RoleNode,
  module: ModuleNode,
  permission: PermissionNode,
  screen: PermissionNode, // Reutiliza
  user: UserNode,
  store: StoreNode,
};

export function PermissionGraph() {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function fetchGraph() {
      const { data } = await api.get('/admin/graph/overview');
      
      // Calcula layout
      const layoutedNodes = calculateLayout(data.nodes, data.edges);
      
      setNodes(layoutedNodes);
      setEdges(data.edges);
      setLoading(false);
    }
    
    fetchGraph();
  }, []);

  if (loading) return <div>Carregando grafo...</div>;

  return (
    <div className="w-full h-[800px]">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        nodeTypes={nodeTypes}
        fitView
        attributionPosition="bottom-left"
      >
        <Background color="#E5E7EB" gap={16} />
        <Controls />
        <MiniMap 
          nodeColor={(node) => nodeColors[node.type as NodeType] || '#888'}
        />
      </ReactFlow>
    </div>
  );
}
```

---

## 🔄 Fluxos de Interação

### 1. Navegar Overview → Detalhes

```
1. Usuário abre /admin/permissions-graph
2. Carrega GET /admin/graph/overview
3. Renderiza grafo geral

4. Usuário clica em nó "role-vendedor"
5. Abre modal/sidebar com opções:
   - Ver detalhes completos
   - Ver usuários com essa role
   - Editar permissões

6. Se "Ver detalhes completos":
   - Carrega GET /admin/graph/role/vendedor
   - Renderiza novo grafo focado
```

### 2. Debug "Por que usuário X tem/não tem permissão Y?"

```
1. Admin vai em /admin/users/123/permissions (ou graph)
2. Carrega GET /admin/graph/user/123
3. Vê todas as fontes de permissões:
   - Quais vêm das roles
   - Quais são overrides
   - Quais estão expirando
4. Identifica a origem do problema
```

---

## 📋 Checklist de Implementação

### Frontend

- [ ] Instalar React Flow: `npm install reactflow`
- [ ] Instalar Dagre: `npm install dagre @types/dagre`
- [ ] Criar componentes de nós customizados
- [ ] Implementar cálculo de layout
- [ ] Criar página de visualização
- [ ] Adicionar filtros (depth, include_users, etc)
- [ ] Implementar navegação entre grafos
- [ ] Adicionar busca no grafo

### Backend (✅ Pronto)

- [x] GET /admin/graph/overview
- [x] GET /admin/graph/role/{name}
- [x] GET /admin/graph/user/{id}
- [x] GET /admin/graph/store/{id}
- [x] GET /admin/graph/module/{id}

---

*Backend Team - MaisCapinhas*
