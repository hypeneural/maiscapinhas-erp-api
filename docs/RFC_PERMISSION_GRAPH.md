# 📊 Graph API - Documentação para Frontend

> **Status:** ✅ IMPLEMENTADO  
> **Data:** 16/01/2026

---

## 🚀 Endpoints Prontos

| Endpoint | Prioridade | Descrição |
|----------|------------|-----------|
| `GET /admin/graph/overview` | ✅ P1 | Hierarquia geral do sistema |
| `GET /admin/graph/role/{name}` | ✅ P2 | Grafo de um cargo |
| `GET /admin/graph/user/{id}` | ✅ P3 | Grafo de um usuário |
| `GET /admin/graph/store/{id}` | ✅ P4 | Grafo de uma loja |
| `GET /admin/graph/module/{id}` | ✅ P5 | Grafo de um módulo |

---

## 📦 Formato de Resposta (React Flow)

```typescript
interface GraphResponse {
  nodes: GraphNode[];
  edges: GraphEdge[];
  root?: string; // ID do nó raiz
  summary: {
    total_nodes: number;
    total_edges: number;
    by_type: Record<string, number>;
    depth?: number;
  };
  metadata?: {
    generated_at: string;
    filters: Record<string, unknown>;
  };
}

interface GraphNode {
  id: string;
  type: 'role' | 'module' | 'permission' | 'screen' | 'user' | 'store';
  data: {
    label: string;
    icon: string; // Lucide icon name
    [key: string]: unknown;
  };
  position: { x: 0; y: 0 }; // Vocês calculam com dagre
}

interface GraphEdge {
  id: string;
  source: string;
  target: string;
  type: 'hierarchy' | 'has_access' | 'contains' | 'has_user' | 'has_role' | 'works_at' | 'override_grant' | 'override_deny';
  label?: string;
  animated?: boolean;
}
```

---

## 🎨 Tipos de Nós

| Type | Ícone | Cor Sugerida | Descrição |
|------|-------|--------------|-----------|
| `role` | Shield | 🔵 Blue | Cargo/Role |
| `module` | Package | 🟠 Orange | Módulo |
| `permission` | Key | ⚪ Gray | Permissão |
| `screen` | Monitor | 🟣 Purple | Tela |
| `user` | User | 🟡 Yellow | Usuário |
| `store` | Store | 🟢 Green | Loja |

---

## 📋 Exemplos de Uso

### 1. Overview do Sistema

```
GET /admin/graph/overview?depth=2&include_users=false
```

```json
{
  "nodes": [
    { "id": "role-admin", "type": "role", "data": { "label": "Admin", "level": 90, "users_count": 3 }, "position": { "x": 0, "y": 0 } },
    { "id": "role-vendedor", "type": "role", "data": { "label": "Vendedor", "level": 40, "users_count": 25 }, "position": { "x": 0, "y": 0 } },
    { "id": "module-pedidos", "type": "module", "data": { "label": "Pedidos", "icon": "ShoppingCart", "is_active": true }, "position": { "x": 0, "y": 0 } }
  ],
  "edges": [
    { "id": "e1", "source": "role-admin", "target": "role-vendedor", "type": "hierarchy" },
    { "id": "e2", "source": "role-vendedor", "target": "module-pedidos", "type": "has_access" }
  ],
  "summary": {
    "total_nodes": 15,
    "total_edges": 20,
    "by_type": { "role": 6, "module": 2, "store": 5 }
  }
}
```

### 2. Grafo de um Cargo

```
GET /admin/graph/role/vendedor?include_users=true&include_permissions=true
```

```json
{
  "nodes": [
    { "id": "role-vendedor", "type": "role", "data": { "label": "Vendedor", "level": 40, "permissions_count": 12 } },
    { "id": "module-pedidos", "type": "module", "data": { "label": "Pedidos" } },
    { "id": "perm-pedidos.view", "type": "permission", "data": { "label": "Ver Pedidos", "granted": true } },
    { "id": "perm-pedidos.create", "type": "permission", "data": { "label": "Criar Pedidos", "granted": true } },
    { "id": "user-1", "type": "user", "data": { "label": "João Silva", "email": "joao@..." } }
  ],
  "edges": [
    { "id": "e1", "source": "role-vendedor", "target": "module-pedidos", "type": "has_access" },
    { "id": "e2", "source": "module-pedidos", "target": "perm-pedidos.view", "type": "contains" },
    { "id": "e3", "source": "role-vendedor", "target": "user-1", "type": "has_user" }
  ],
  "root": "role-vendedor",
  "summary": {
    "modules": 2,
    "permissions": 12,
    "users": 25
  }
}
```

### 3. Grafo de um Usuário

```
GET /admin/graph/user/1?include_inherited=true
```

```json
{
  "nodes": [
    { "id": "user-1", "type": "user", "data": { "label": "João Silva", "roles": ["vendedor"] } },
    { "id": "role-vendedor", "type": "role", "data": { "label": "Vendedor" } },
    { "id": "store-5", "type": "store", "data": { "label": "Loja Tijucas", "role_in_store": "vendedor" } },
    { "id": "override-123", "type": "permission", "data": { "label": "capas.view-global", "is_override": true, "is_temporary": true, "expires_at": "2026-02-01" } }
  ],
  "edges": [
    { "id": "e1", "source": "user-1", "target": "role-vendedor", "type": "has_role" },
    { "id": "e2", "source": "user-1", "target": "store-5", "type": "works_at", "label": "vendedor" },
    { "id": "e3", "source": "user-1", "target": "override-123", "type": "override_grant", "animated": true }
  ],
  "root": "user-1",
  "effective_permissions": [
    { "name": "pedidos.view", "source": "role", "source_name": "vendedor" },
    { "name": "capas.view-global", "source": "override", "expires_at": "2026-02-01" }
  ]
}
```

---

## 🔧 Query Params

| Endpoint | Param | Tipo | Default | Descrição |
|----------|-------|------|---------|-----------|
| overview | `depth` | int | 3 | Profundidade máxima |
| overview | `include_users` | bool | false | Incluir nós de usuários |
| role | `include_users` | bool | false | Incluir usuários do cargo |
| role | `include_permissions` | bool | true | Incluir permissões individuais |
| user | `include_inherited` | bool | true | Mostrar origem das permissões |
| store | `include_users` | bool | true | Incluir usuários da loja |

---

## 🎯 Integração com React Flow

```tsx
import ReactFlow, { Background, Controls, MiniMap } from 'reactflow';
import dagre from 'dagre';

// 1. Fetch data
const { data } = await api.get('/admin/graph/overview');

// 2. Calculate layout com dagre
const layoutedNodes = calculateLayout(data.nodes, data.edges);

// 3. Render
<ReactFlow
  nodes={layoutedNodes}
  edges={data.edges}
  fitView
>
  <Background />
  <Controls />
  <MiniMap />
</ReactFlow>
```

---

## ✅ Pronto para Integração!

Todos os endpoints estão funcionando. Podem começar!

---

*Backend Team - MaisCapinhas*
