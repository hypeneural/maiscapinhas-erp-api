# 📋 Análise e Melhorias - Sistema de Permissões

> **Data**: 2026-01-16  
> **De**: Backend → Frontend  
> **Assunto**: Review do documento de implementação frontend + Melhorias propostas

---

## ✅ Pontos Aprovados

A proposta do frontend está **excelente**! Aprovamos:

| Item | Status | Comentário |
|------|--------|------------|
| Store de autenticação (Zustand) | ✅ | Estrutura correta |
| Helpers `can()`, `canAccessScreen()` | ✅ | Implementação correta |
| Componente `<Can>` | ✅ | Padrão recomendado |
| `PermissionGuard` para rotas | ✅ | Abordagem correta |
| Menu pré-filtrado pelo backend | ✅ | Nossa recomendação |
| Suporte multi-loja | ✅ | Essencial |

---

## 🔧 Melhorias Propostas

### 1. Adicionar `denied` no Response do `/me`

**Problema**: Atualmente só retornamos permissões concedidas. Mas pode haver casos onde queremos **mostrar um botão desabilitado** com tooltip explicando por quê.

**Proposta - Novo formato**:

```json
{
  "permissions": {
    "global": {
      "granted": ["pedidos.view", "pedidos.create"],
      "denied": []
    },
    "by_store": {
      "5": {
        "granted": ["reports.view"],
        "denied": ["pedidos.delete"]
      }
    }
  }
}
```

**Uso no frontend**:
```tsx
// Botão desabilitado com tooltip explicando
<Can 
  permission="pedidos.delete" 
  fallback={
    <Tooltip content="Você não pode excluir pedidos nesta loja">
      <Button disabled>Excluir</Button>
    </Tooltip>
  }
>
  <Button onClick={handleDelete}>Excluir</Button>
</Can>
```

---

### 2. Adicionar `reason` nas Permissões Negadas

Para UX ainda melhor, podemos explicar **por quê** a permissão foi negada:

```json
{
  "permissions": {
    "by_store": {
      "5": {
        "denied": [
          {
            "permission": "pedidos.delete",
            "reason": "Política da loja Centro não permite exclusão de pedidos"
          }
        ]
      }
    }
  }
}
```

---

### 3. Adicionar `expires_at` para Permissões Temporárias

```json
{
  "permissions": {
    "global": {
      "granted": [
        "pedidos.view",
        {
          "permission": "admin.users.view",
          "expires_at": "2026-02-01T00:00:00Z",
          "reason": "Acesso temporário para projeto X"
        }
      ]
    }
  }
}
```

**Uso no frontend**:
```tsx
// Mostrar badge de "temporário"
<Can permission="admin.users.view">
  {(meta) => (
    <div>
      <Button>Ver Usuários</Button>
      {meta?.expires_at && (
        <Badge>Expira em {formatDate(meta.expires_at)}</Badge>
      )}
    </div>
  )}
</Can>
```

---

### 4. Hierarquia de Permissões com Wildcards

**Proposta**: Suportar wildcards para simplificar verificações.

```typescript
// Backend retorna:
permissions.granted = ["pedidos.*", "capas.view"]

// Frontend verifica:
can("pedidos.create")  // true (matches pedidos.*)
can("pedidos.delete")  // true (matches pedidos.*)
can("capas.create")    // false (só tem capas.view)
```

**Implementação no store**:

```typescript
can: (permission, storeId) => {
  const state = get();
  
  if (state.user?.is_super_admin) return true;
  
  const allPerms = [
    ...state.permissions.global.granted,
    ...(state.permissions.by_store[storeId]?.granted ?? [])
  ];
  
  // Verificar exato
  if (allPerms.includes(permission)) return true;
  
  // Verificar wildcard (ex: "pedidos.*" matches "pedidos.create")
  const [module] = permission.split('.');
  if (allPerms.includes(`${module}.*`)) return true;
  
  // Verificar super wildcard
  if (allPerms.includes('*')) return true;
  
  return false;
}
```

---

### 5. Contexto de Permissão Detalhado

Adicionar mais informações sobre **de onde** veio a permissão:

```json
{
  "permissions_detail": [
    {
      "permission": "pedidos.create",
      "granted": true,
      "source": "role",           // role | store | user
      "source_name": "vendedor",  // nome do role/loja
      "scope": "global"           // global | store:5
    },
    {
      "permission": "reports.view",
      "granted": true,
      "source": "store",
      "source_name": "Loja Centro",
      "scope": "store:5"
    },
    {
      "permission": "admin.users.view",
      "granted": true,
      "source": "user",
      "source_name": "Override manual",
      "scope": "global",
      "granted_by": "Admin João",
      "reason": "Projeto especial",
      "expires_at": "2026-02-01"
    }
  ]
}
```

Isso é útil para:
- Debugging
- Tela de "Minhas Permissões" para o usuário
- Auditoria

---

### 6. Sistema de Níveis de Acesso nas Telas

Algumas telas podem ter **níveis de acesso** diferentes:

```
screen.pedidos.list      → Ver lista
screen.pedidos.list:full → Ver lista com todas as colunas
screen.pedidos.list:own  → Ver apenas seus próprios pedidos
```

**Response do /me**:
```json
{
  "screens": {
    "global": [
      "screen.pedidos.list:own",
      "screen.capas.list"
    ],
    "by_store": {
      "5": [
        "screen.pedidos.list:full"
      ]
    }
  }
}
```

**Frontend**:
```typescript
// Helper para verificar nível
function getScreenLevel(screen: string): string | null {
  const { screens, currentStoreId } = useAuthStore.getState();
  
  // Verificar global
  const globalMatch = screens.global.find(s => s.startsWith(screen));
  if (globalMatch) {
    const [, level] = globalMatch.split(':');
    return level ?? 'full';
  }
  
  // Verificar por loja
  if (currentStoreId) {
    const storeMatch = screens.by_store[currentStoreId]?.find(s => s.startsWith(screen));
    if (storeMatch) {
      const [, level] = storeMatch.split(':');
      return level ?? 'full';
    }
  }
  
  return null;
}

// Uso
const level = getScreenLevel('screen.pedidos.list');
if (level === 'own') {
  // Filtrar apenas pedidos do usuário
}
```

---

### 7. Melhorar Estrutura do Menu

Adicionar mais metadados ao menu:

```json
{
  "menu": [
    {
      "id": "pedidos",
      "label": "Pedidos",
      "icon": "shopping-bag",
      "route": "/pedidos",
      "screen": "screen.pedidos",
      "badge": {
        "type": "count",
        "value": 5,
        "color": "red"
      },
      "permissions_required": ["pedidos.view"],
      "children": [
        {
          "id": "pedidos-list",
          "label": "Lista",
          "route": "/pedidos",
          "screen": "screen.pedidos.list",
          "description": "Ver todos os pedidos"
        },
        {
          "id": "pedidos-new",
          "label": "Novo Pedido",
          "route": "/pedidos/new",
          "screen": "screen.pedidos.create",
          "permissions_required": ["pedidos.create"],
          "highlight": true
        }
      ]
    }
  ]
}
```

**Benefícios**:
- `badge`: Notificações no menu
- `permissions_required`: Frontend sabe quais permissions são necessárias
- `description`: Tooltips
- `highlight`: Destacar itens importantes

---

### 8. Evento de Mudança de Permissões

Quando as permissões do usuário mudam (ex: admin alterou), o frontend precisa saber:

**Backend**: Adicionar header na resposta quando permissões mudaram:

```
X-Permissions-Updated: true
X-Permissions-Updated-At: 2026-01-16T10:30:00Z
```

**Frontend**: Interceptor para detectar mudança:

```typescript
// api/interceptors.ts
api.interceptors.response.use((response) => {
  if (response.headers['x-permissions-updated'] === 'true') {
    // Recarregar permissões
    queryClient.invalidateQueries({ queryKey: ['me'] });
    
    // Notificar usuário
    toast.info('Suas permissões foram atualizadas');
  }
  return response;
});
```

---

### 9. Adicionar Verificação Offline

Para PWA ou offline-first:

```typescript
// stores/auth.store.ts
{
  // Timestamp de quando as permissões foram carregadas
  permissionsLoadedAt: Date | null,
  
  // Verificar se precisa atualizar
  needsPermissionRefresh: () => {
    const state = get();
    if (!state.permissionsLoadedAt) return true;
    
    const hoursSinceLastLoad = 
      (Date.now() - state.permissionsLoadedAt.getTime()) / (1000 * 60 * 60);
    
    return hoursSinceLastLoad > 1; // Atualizar a cada 1 hora
  }
}
```

---

## 📊 Matriz de Permissões Atualizada

### Roles do Sistema (Níveis)

| Role | Level | Descrição |
|------|-------|-----------|
| `super_admin` | 100 | Acesso total, bypass de todas as verificações |
| `admin` | 90 | Admin global, gerencia lojas e usuários |
| `fabrica` | 80 | Usuário da fábrica |
| `gerente` | 70 | Gerente de loja |
| `conferente` | 60 | Conferente de caixa |
| `estoquista` | 50 | Controle de estoque |
| `vendedor` | 40 | Vendedor padrão |

### Permissões por Módulo (Atualizado)

#### Formas de Pagamento
| Permission | Vendedor | Estoquista | Conferente | Gerente | Admin | Super |
|------------|----------|------------|------------|---------|-------|-------|
| `payment-methods.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `payment-methods.create` | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| `payment-methods.update` | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| `payment-methods.delete` | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |

#### Pedidos
| Permission | Vendedor | Estoquista | Conferente | Gerente | Admin | Super |
|------------|----------|------------|------------|---------|-------|-------|
| `pedidos.view` | 🏪 own | 🏪 | 🏪 | 🏪 | ✅ | ✅ |
| `pedidos.view-all` | ❌ | ❌ | 🏪 | 🏪 | ✅ | ✅ |
| `pedidos.create` | 🏪 | ❌ | 🏪 | 🏪 | ✅ | ✅ |
| `pedidos.update` | 🏪 own | ❌ | 🏪 | 🏪 | ✅ | ✅ |
| `pedidos.delete` | ❌ | ❌ | ❌ | 🏪 | ✅ | ✅ |
| `pedidos.status.update` | 🏪 own | ❌ | 🏪 | 🏪 | ✅ | ✅ |
| `pedidos.bulk-status` | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |

**Legenda**:
- ✅ = Acesso global
- 🏪 = Acesso limitado às suas lojas
- 🏪 own = Acesso apenas aos próprios registros na loja
- ❌ = Sem acesso

---

## 🔄 Contrato Backend ↔ Frontend

### Endpoint `/api/v1/me` - Response Atualizado

```typescript
interface MeResponse {
  data: {
    user: {
      id: number;
      name: string;
      email: string;
      avatar_url: string | null;
      is_super_admin: boolean;
      roles: Array<{
        id: number;
        name: string;
        display_name: string;
        level: number;
        store_id: number | null;
      }>;
    };
    
    stores: Array<{
      id: number;
      name: string;
      city: string;
      role: string;
    }>;
    
    permissions: {
      global: {
        granted: string[];
        denied: string[];
      };
      by_store: Record<string, {
        granted: string[];
        denied: string[];
      }>;
    };
    
    screens: {
      global: string[];           // ex: ["screen.dashboard", "screen.pedidos:own"]
      by_store: Record<string, string[]>;
    };
    
    features: string[];           // ex: ["feature.whatsapp-notifications"]
    
    menu: MenuItem[];
    
    // Metadados
    permissions_loaded_at: string;  // ISO timestamp
    permissions_version: number;    // Incrementa quando há mudanças
  };
}

interface MenuItem {
  id: string;
  label: string;
  icon: string;
  route: string;
  screen: string;
  badge?: {
    type: 'count' | 'dot';
    value?: number;
    color?: string;
  };
  description?: string;
  highlight?: boolean;
  children?: MenuItem[];
}
```

---

## 📝 Checklist de Implementação

### Backend
- [ ] Atualizar `/me` com novo formato de `permissions`
- [ ] Adicionar `granted` e `denied` separados
- [ ] Incluir `permissions_version` e `permissions_loaded_at`
- [ ] Suportar wildcards (`pedidos.*`)
- [ ] Adicionar header `X-Permissions-Updated`
- [ ] Criar endpoint `/api/v1/me/permissions` para debug detalhado

### Frontend
- [ ] Atualizar types do `MeResponse`
- [ ] Atualizar store para novo formato
- [ ] Adicionar suporte a wildcards no `can()`
- [ ] Implementar `canWithMeta()` para permissões temporárias
- [ ] Adicionar interceptor para `X-Permissions-Updated`
- [ ] Criar tela "Minhas Permissões"

---

## 🚀 Próximos Passos

1. **Fase 1**: Backend implementa migrations e models
2. **Fase 2**: Backend atualiza `/me` com novo formato
3. **Fase 3**: Frontend atualiza store e helpers
4. **Fase 4**: Migrar telas existentes
5. **Fase 5**: Criar CRUD de roles/permissions

**Dúvidas?** Chamar no grupo! 🤙

---

*Documento de alinhamento Backend ↔ Frontend para sistema de permissões granular.*
