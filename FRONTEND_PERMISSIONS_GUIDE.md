# 🔒 Guia de Permissões e Menus - Frontend React

> Documentação completa do sistema de roles, permissões e organização de menus.

**Stack:** React 18 + TypeScript + TanStack Query + React Router DOM

---

## 📋 Índice

1. [Sistema de Roles](#sistema-de-roles)
2. [Endpoint /me - Dados do Usuário](#endpoint-me)
3. [Schemas TypeScript](#schemas-typescript)
4. [Hooks de Permissão](#hooks-de-permissão)
5. [Componente RoleGuard](#componente-roleguard)
6. [Organização de Menus](#organização-de-menus)
7. [Mapeamento Tela ↔ Role ↔ Endpoint](#mapeamento-completo)
8. [Exemplos de Uso](#exemplos-de-uso)

---

## 🎭 Sistema de Roles

### Hierarquia de Roles

Os roles são **por loja** (store-scoped). Um usuário pode ter roles diferentes em lojas diferentes.

```
┌─────────────────────────────────────────────────────────────┐
│                         ADMIN                                │
│  • Acesso total ao sistema                                   │
│  • Gerencia usuários e lojas                                 │
│  • Vê todas as lojas                                         │
├─────────────────────────────────────────────────────────────┤
│                        GERENTE                               │
│  • Gerencia sua loja                                         │
│  • Aprova/rejeita fechamentos                                │
│  • Configura metas e regras                                  │
├─────────────────────────────────────────────────────────────┤
│                       CONFERENTE                             │
│  • Confere fechamentos de caixa                              │
│  • Aprova/rejeita divergências                               │
│  • Visualiza relatórios de caixa                             │
├─────────────────────────────────────────────────────────────┤
│                        VENDEDOR                              │
│  • Registra vendas                                           │
│  • Lança fechamento de turno                                 │
│  • Visualiza seus bônus e comissões                          │
└─────────────────────────────────────────────────────────────┘
```

### Tabela de Permissões

| Ação | Admin | Gerente | Conferente | Vendedor |
|------|:-----:|:-------:|:----------:|:--------:|
| Ver Dashboard próprio | ✅ | ✅ | ✅ | ✅ |
| Ver todas as lojas | ✅ | ❌ | ❌ | ❌ |
| Gerenciar usuários | ✅ | ❌ | ❌ | ❌ |
| Gerenciar lojas | ✅ | ❌ | ❌ | ❌ |
| Configurar metas | ✅ | ✅ | ❌ | ❌ |
| Configurar bônus | ✅ | ✅ | ❌ | ❌ |
| Configurar comissão | ✅ | ✅ | ❌ | ❌ |
| Ver ranking | ✅ | ✅ | ❌ | ❌ |
| Ver desempenho lojas | ✅ | ✅ | ❌ | ❌ |
| Ver quebra de caixa | ✅ | ✅ | ✅ | ❌ |
| Aprovar fechamento | ✅ | ✅ | ✅ | ❌ |
| Lançar turno | ❌ | ❌ | ✅ | ✅ |
| Ver divergências | ✅ | ✅ | ✅ | ❌ |
| Registrar vendas | ❌ | ❌ | ❌ | ✅ |
| Ver meus bônus | ❌ | ❌ | ❌ | ✅ |
| Ver minhas comissões | ❌ | ❌ | ❌ | ✅ |

---

## 📡 Endpoint /me

### Requisição

```http
GET /api/v1/me
Authorization: Bearer {token}
```

### Response

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "joao@maiscapinhas.com.br",
      "active": true,
      "avatar_url": "https://...",
      "created_at": "2026-01-01T00:00:00Z"
    },
    "stores": [
      {
        "id": 1,
        "name": "Mais Capinhas Tijucas",
        "city": "Tijucas",
        "role": "admin"
      },
      {
        "id": 2,
        "name": "Mais Capinhas Itapema",
        "city": "Itapema",
        "role": "gerente"
      }
    ]
  },
  "meta": {
    "request_id": "uuid-abc-123",
    "timestamp": "2026-01-08T10:00:00Z"
  }
}
```

### Quando Chamar

1. **Após login** - Para obter dados completos do usuário
2. **No mount do app** - Para restaurar sessão
3. **Ao trocar de loja** - Para verificar permissões

---

## 📐 Schemas TypeScript

```typescript
// src/lib/schemas/permissions.ts
import { z } from 'zod';

// ============================================
// ROLES
// ============================================

export const roleSchema = z.enum(['admin', 'gerente', 'conferente', 'vendedor']);
export type Role = z.infer<typeof roleSchema>;

// Ordem de hierarquia (maior = mais permissões)
export const ROLE_HIERARCHY: Record<Role, number> = {
  admin: 4,
  gerente: 3,
  conferente: 2,
  vendedor: 1,
};

// ============================================
// STORE WITH ROLE
// ============================================

export const storeWithRoleSchema = z.object({
  id: z.number(),
  name: z.string(),
  city: z.string().optional(),
  role: roleSchema,
});

export type StoreWithRole = z.infer<typeof storeWithRoleSchema>;

// ============================================
// USER PROFILE
// ============================================

export const userProfileSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string().email(),
  active: z.boolean(),
  avatar_url: z.string().url().nullable().optional(),
  birth_date: z.string().nullable().optional(),
  whatsapp: z.string().nullable().optional(),
  created_at: z.string(),
});

export type UserProfile = z.infer<typeof userProfileSchema>;

// ============================================
// ME RESPONSE
// ============================================

export const meResponseSchema = z.object({
  data: z.object({
    user: userProfileSchema,
    stores: z.array(storeWithRoleSchema),
  }),
  meta: z.object({
    request_id: z.string(),
    timestamp: z.string(),
  }),
});

export type MeResponse = z.infer<typeof meResponseSchema>;

// ============================================
// PERMISSION CONTEXT
// ============================================

export interface PermissionContext {
  user: UserProfile | null;
  stores: StoreWithRole[];
  currentStore: StoreWithRole | null;
  isLoading: boolean;
}

// ============================================
// PERMISSIONS MAP
// ============================================

export type Permission =
  | 'dashboard:view'
  | 'sales:create'
  | 'sales:view'
  | 'sales:edit'
  | 'sales:delete'
  | 'bonus:view_own'
  | 'bonus:view_all'
  | 'commission:view_own'
  | 'commission:view_all'
  | 'shift:create'
  | 'shift:view'
  | 'closing:submit'
  | 'closing:approve'
  | 'closing:reject'
  | 'divergence:view'
  | 'goals:view'
  | 'goals:manage'
  | 'rules:view'
  | 'rules:manage'
  | 'ranking:view'
  | 'reports:store_performance'
  | 'reports:cash_integrity'
  | 'reports:consolidated'
  | 'users:view'
  | 'users:manage'
  | 'stores:view'
  | 'stores:manage'
  | 'audit:view';

export const ROLE_PERMISSIONS: Record<Role, Permission[]> = {
  admin: [
    'dashboard:view',
    'sales:view',
    'sales:edit',
    'sales:delete',
    'bonus:view_all',
    'commission:view_all',
    'shift:view',
    'closing:approve',
    'closing:reject',
    'divergence:view',
    'goals:view',
    'goals:manage',
    'rules:view',
    'rules:manage',
    'ranking:view',
    'reports:store_performance',
    'reports:cash_integrity',
    'reports:consolidated',
    'users:view',
    'users:manage',
    'stores:view',
    'stores:manage',
    'audit:view',
  ],
  gerente: [
    'dashboard:view',
    'sales:view',
    'sales:edit',
    'bonus:view_all',
    'commission:view_all',
    'shift:view',
    'closing:approve',
    'closing:reject',
    'divergence:view',
    'goals:view',
    'goals:manage',
    'rules:view',
    'rules:manage',
    'ranking:view',
    'reports:store_performance',
    'reports:cash_integrity',
  ],
  conferente: [
    'dashboard:view',
    'shift:create',
    'shift:view',
    'closing:submit',
    'closing:approve',
    'closing:reject',
    'divergence:view',
    'reports:cash_integrity',
  ],
  vendedor: [
    'dashboard:view',
    'sales:create',
    'sales:view',
    'bonus:view_own',
    'commission:view_own',
    'shift:create',
    'closing:submit',
  ],
};
```

---

## 🎣 Hooks de Permissão

```typescript
// src/lib/hooks/usePermissions.ts
import { useMemo, useCallback } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import {
  Role,
  Permission,
  StoreWithRole,
  ROLE_PERMISSIONS,
  ROLE_HIERARCHY,
} from '@/lib/schemas/permissions';

interface UsePermissionsReturn {
  // Estado
  user: UserProfile | null;
  stores: StoreWithRole[];
  currentStore: StoreWithRole | null;
  currentRole: Role | null;
  isLoading: boolean;
  
  // Verificações
  hasPermission: (permission: Permission) => boolean;
  hasAnyPermission: (permissions: Permission[]) => boolean;
  hasAllPermissions: (permissions: Permission[]) => boolean;
  hasRole: (role: Role) => boolean;
  hasMinRole: (minRole: Role) => boolean;
  isAdmin: () => boolean;
  isGerente: () => boolean;
  isConferente: () => boolean;
  isVendedor: () => boolean;
  
  // Ações
  setCurrentStore: (storeId: number) => void;
  getHighestRole: () => Role | null;
}

export function usePermissions(): UsePermissionsReturn {
  const { user, stores, currentStoreId, setCurrentStoreId, isLoading } = useAuth();

  // Loja atual
  const currentStore = useMemo(() => {
    if (!currentStoreId || !stores.length) return null;
    return stores.find(s => s.id === currentStoreId) ?? stores[0];
  }, [currentStoreId, stores]);

  // Role na loja atual
  const currentRole = useMemo(() => {
    return currentStore?.role ?? null;
  }, [currentStore]);

  // Verificar permissão
  const hasPermission = useCallback((permission: Permission): boolean => {
    if (!currentRole) return false;
    return ROLE_PERMISSIONS[currentRole].includes(permission);
  }, [currentRole]);

  // Verificar qualquer permissão
  const hasAnyPermission = useCallback((permissions: Permission[]): boolean => {
    return permissions.some(hasPermission);
  }, [hasPermission]);

  // Verificar todas permissões
  const hasAllPermissions = useCallback((permissions: Permission[]): boolean => {
    return permissions.every(hasPermission);
  }, [hasPermission]);

  // Verificar role exata
  const hasRole = useCallback((role: Role): boolean => {
    return currentRole === role;
  }, [currentRole]);

  // Verificar role mínima (hierarquia)
  const hasMinRole = useCallback((minRole: Role): boolean => {
    if (!currentRole) return false;
    return ROLE_HIERARCHY[currentRole] >= ROLE_HIERARCHY[minRole];
  }, [currentRole]);

  // Shortcuts
  const isAdmin = useCallback(() => hasRole('admin'), [hasRole]);
  const isGerente = useCallback(() => hasRole('gerente'), [hasRole]);
  const isConferente = useCallback(() => hasRole('conferente'), [hasRole]);
  const isVendedor = useCallback(() => hasRole('vendedor'), [hasRole]);

  // Maior role do usuário (em todas as lojas)
  const getHighestRole = useCallback((): Role | null => {
    if (!stores.length) return null;
    return stores.reduce((highest, store) => {
      if (!highest) return store.role;
      return ROLE_HIERARCHY[store.role] > ROLE_HIERARCHY[highest]
        ? store.role
        : highest;
    }, null as Role | null);
  }, [stores]);

  // Trocar loja
  const setCurrentStore = useCallback((storeId: number) => {
    setCurrentStoreId(storeId);
  }, [setCurrentStoreId]);

  return {
    user,
    stores,
    currentStore,
    currentRole,
    isLoading,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    hasRole,
    hasMinRole,
    isAdmin,
    isGerente,
    isConferente,
    isVendedor,
    setCurrentStore,
    getHighestRole,
  };
}

// ============================================
// HOOK PARA ROLE ESPECÍFICA
// ============================================

export function useRequireRole(requiredRole: Role) {
  const { currentRole, hasMinRole, isLoading } = usePermissions();
  
  return {
    isAllowed: hasMinRole(requiredRole),
    isLoading,
    currentRole,
  };
}

// ============================================
// HOOK PARA PERMISSÃO ESPECÍFICA
// ============================================

export function useRequirePermission(permission: Permission) {
  const { hasPermission, isLoading, currentRole } = usePermissions();
  
  return {
    isAllowed: hasPermission(permission),
    isLoading,
    currentRole,
  };
}
```

---

## 🛡️ Componente RoleGuard

```typescript
// src/components/RoleGuard.tsx
import { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { usePermissions } from '@/lib/hooks/usePermissions';
import { Role, Permission } from '@/lib/schemas/permissions';

interface RoleGuardProps {
  children: ReactNode;
  
  // Opção 1: Verificar roles
  roles?: Role[];
  minRole?: Role;
  
  // Opção 2: Verificar permissões
  permissions?: Permission[];
  requireAll?: boolean; // default: false (any)
  
  // Fallbacks
  fallback?: ReactNode;
  redirectTo?: string;
}

export function RoleGuard({
  children,
  roles,
  minRole,
  permissions,
  requireAll = false,
  fallback,
  redirectTo = '/unauthorized',
}: RoleGuardProps) {
  const {
    currentRole,
    hasRole,
    hasMinRole,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    isLoading,
  } = usePermissions();

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[200px]">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // Verificar autorização
  let isAuthorized = true;

  // Verificar roles específicas
  if (roles?.length) {
    isAuthorized = roles.some(hasRole);
  }

  // Verificar role mínima
  if (minRole && isAuthorized) {
    isAuthorized = hasMinRole(minRole);
  }

  // Verificar permissões
  if (permissions?.length && isAuthorized) {
    isAuthorized = requireAll
      ? hasAllPermissions(permissions)
      : hasAnyPermission(permissions);
  }

  // Não autorizado
  if (!isAuthorized) {
    if (fallback) return <>{fallback}</>;
    if (redirectTo) return <Navigate to={redirectTo} replace />;
    return null;
  }

  return <>{children}</>;
}

// ============================================
// VARIANTES ESPECÍFICAS
// ============================================

export function AdminOnly({ children, fallback }: { children: ReactNode; fallback?: ReactNode }) {
  return (
    <RoleGuard roles={['admin']} fallback={fallback}>
      {children}
    </RoleGuard>
  );
}

export function ManagerOnly({ children, fallback }: { children: ReactNode; fallback?: ReactNode }) {
  return (
    <RoleGuard minRole="gerente" fallback={fallback}>
      {children}
    </RoleGuard>
  );
}

export function CanApprove({ children, fallback }: { children: ReactNode; fallback?: ReactNode }) {
  return (
    <RoleGuard permissions={['closing:approve']} fallback={fallback}>
      {children}
    </RoleGuard>
  );
}
```

---

## 📱 Organização de Menus

### Estrutura do Menu por Role

```typescript
// src/lib/config/menuConfig.ts
import {
  LayoutDashboard,
  ShoppingCart,
  Gift,
  Percent,
  ClipboardCheck,
  AlertTriangle,
  FileText,
  Trophy,
  Store,
  TrendingUp,
  AlertCircle,
  Target,
  Settings,
  Users,
  Building2,
  ScrollText,
} from 'lucide-react';
import { Role, Permission } from '@/lib/schemas/permissions';

export interface MenuItem {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  path: string;
  permissions?: Permission[];
  roles?: Role[];
  minRole?: Role;
  badge?: string | number;
  children?: MenuItem[];
}

export interface MenuSection {
  id: string;
  title: string;
  items: MenuItem[];
  permissions?: Permission[];
  roles?: Role[];
  minRole?: Role;
}

// ============================================
// MENU COMPLETO
// ============================================

export const menuSections: MenuSection[] = [
  // Dashboard - Todos
  {
    id: 'principal',
    title: 'Principal',
    items: [
      {
        id: 'dashboard',
        label: 'Dashboard',
        icon: LayoutDashboard,
        path: '/dashboard',
        permissions: ['dashboard:view'],
      },
    ],
  },

  // Faturamento - Vendedor
  {
    id: 'faturamento',
    title: 'Faturamento',
    roles: ['vendedor'],
    items: [
      {
        id: 'extrato-vendas',
        label: 'Extrato de Vendas',
        icon: ShoppingCart,
        path: '/faturamento/extrato-vendas',
        permissions: ['sales:view'],
      },
      {
        id: 'meus-bonus',
        label: 'Meus Bônus',
        icon: Gift,
        path: '/faturamento/meus-bonus',
        permissions: ['bonus:view_own'],
      },
      {
        id: 'minhas-comissoes',
        label: 'Minhas Comissões',
        icon: Percent,
        path: '/faturamento/minhas-comissoes',
        permissions: ['commission:view_own'],
      },
    ],
  },

  // Conferência - Conferente/Vendedor
  {
    id: 'conferencia',
    title: 'Conferência',
    roles: ['conferente', 'vendedor'],
    items: [
      {
        id: 'lancar-turno',
        label: 'Lançar Turno',
        icon: ClipboardCheck,
        path: '/conferencia/lancar-turno',
        permissions: ['shift:create', 'closing:submit'],
      },
      {
        id: 'divergencias',
        label: 'Divergências',
        icon: AlertTriangle,
        path: '/conferencia/divergencias',
        permissions: ['divergence:view'],
        roles: ['conferente', 'gerente', 'admin'],
      },
      {
        id: 'historico-envelopes',
        label: 'Histórico de Envelopes',
        icon: FileText,
        path: '/conferencia/historico-envelopes',
        permissions: ['shift:view'],
        roles: ['conferente', 'gerente', 'admin'],
      },
    ],
  },

  // Gestão - Gerente/Admin
  {
    id: 'gestao',
    title: 'Gestão',
    minRole: 'gerente',
    items: [
      {
        id: 'ranking-vendas',
        label: 'Ranking de Vendas',
        icon: Trophy,
        path: '/gestao/ranking-vendas',
        permissions: ['ranking:view'],
      },
      {
        id: 'desempenho-lojas',
        label: 'Desempenho por Loja',
        icon: Store,
        path: '/gestao/desempenho-lojas',
        permissions: ['reports:store_performance'],
      },
      {
        id: 'quebra-caixa',
        label: 'Quebra de Caixa',
        icon: AlertCircle,
        path: '/gestao/quebra-caixa',
        permissions: ['reports:cash_integrity'],
      },
    ],
  },

  // Configurações - Admin/Gerente
  {
    id: 'configuracoes',
    title: 'Configurações',
    minRole: 'gerente',
    items: [
      {
        id: 'metas-mensais',
        label: 'Metas Mensais',
        icon: Target,
        path: '/config/metas-mensais',
        permissions: ['goals:manage'],
      },
      {
        id: 'tabela-bonus',
        label: 'Tabela de Bônus',
        icon: Gift,
        path: '/config/tabela-bonus',
        permissions: ['rules:manage'],
      },
      {
        id: 'regras-comissao',
        label: 'Regras de Comissão',
        icon: Percent,
        path: '/config/regras-comissao',
        permissions: ['rules:manage'],
      },
    ],
  },

  // Admin - Apenas Admin
  {
    id: 'admin',
    title: 'Administração',
    roles: ['admin'],
    items: [
      {
        id: 'usuarios-lojas',
        label: 'Usuários & Lojas',
        icon: Users,
        path: '/config/usuarios-lojas',
        permissions: ['users:manage', 'stores:manage'],
      },
      {
        id: 'audit-logs',
        label: 'Logs de Auditoria',
        icon: ScrollText,
        path: '/admin/audit-logs',
        permissions: ['audit:view'],
      },
    ],
  },
];

// ============================================
// FUNÇÃO PARA FILTRAR MENU
// ============================================

export function getFilteredMenu(
  sections: MenuSection[],
  checkPermission: (p: Permission) => boolean,
  checkRole: (r: Role) => boolean,
  checkMinRole: (r: Role) => boolean
): MenuSection[] {
  return sections
    .filter(section => {
      // Verificar roles da seção
      if (section.roles?.length) {
        if (!section.roles.some(checkRole)) return false;
      }
      
      // Verificar minRole da seção
      if (section.minRole) {
        if (!checkMinRole(section.minRole)) return false;
      }
      
      // Verificar permissões da seção
      if (section.permissions?.length) {
        if (!section.permissions.some(checkPermission)) return false;
      }
      
      return true;
    })
    .map(section => ({
      ...section,
      items: section.items.filter(item => {
        // Verificar roles do item
        if (item.roles?.length) {
          if (!item.roles.some(checkRole)) return false;
        }
        
        // Verificar minRole do item
        if (item.minRole) {
          if (!checkMinRole(item.minRole)) return false;
        }
        
        // Verificar permissões do item
        if (item.permissions?.length) {
          if (!item.permissions.some(checkPermission)) return false;
        }
        
        return true;
      }),
    }))
    .filter(section => section.items.length > 0);
}
```

### Hook para Menu Filtrado

```typescript
// src/lib/hooks/useFilteredMenu.ts
import { useMemo } from 'react';
import { usePermissions } from './usePermissions';
import { menuSections, getFilteredMenu } from '@/lib/config/menuConfig';

export function useFilteredMenu() {
  const { hasPermission, hasRole, hasMinRole, isLoading } = usePermissions();

  const filteredMenu = useMemo(() => {
    if (isLoading) return [];
    return getFilteredMenu(menuSections, hasPermission, hasRole, hasMinRole);
  }, [hasPermission, hasRole, hasMinRole, isLoading]);

  return { menu: filteredMenu, isLoading };
}
```

### Componente AppSidebar

```typescript
// src/components/AppSidebar.tsx
import { Link, useLocation } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { useFilteredMenu } from '@/lib/hooks/useFilteredMenu';
import { usePermissions } from '@/lib/hooks/usePermissions';
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarGroupContent,
  SidebarMenu,
  SidebarMenuItem,
  SidebarMenuButton,
} from '@/components/ui/sidebar';
import { StoreSwitcher } from './StoreSwitcher';
import { UserNav } from './UserNav';

export function AppSidebar() {
  const location = useLocation();
  const { menu, isLoading } = useFilteredMenu();
  const { user, currentStore, stores } = usePermissions();

  if (isLoading) {
    return <SidebarSkeleton />;
  }

  return (
    <Sidebar>
      <SidebarContent>
        {/* Header com logo e seletor de loja */}
        <div className="p-4 border-b">
          <h1 className="text-lg font-bold text-primary">MaisCapinhas</h1>
          {stores.length > 1 && (
            <StoreSwitcher
              stores={stores}
              currentStore={currentStore}
            />
          )}
        </div>

        {/* Menu sections */}
        {menu.map(section => (
          <SidebarGroup key={section.id}>
            <SidebarGroupLabel>{section.title}</SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu>
                {section.items.map(item => {
                  const Icon = item.icon;
                  const isActive = location.pathname === item.path;
                  
                  return (
                    <SidebarMenuItem key={item.id}>
                      <SidebarMenuButton asChild isActive={isActive}>
                        <Link to={item.path}>
                          <Icon className="h-4 w-4" />
                          <span>{item.label}</span>
                          {item.badge && (
                            <span className="ml-auto text-xs bg-destructive text-destructive-foreground px-1.5 py-0.5 rounded-full">
                              {item.badge}
                            </span>
                          )}
                        </Link>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  );
                })}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        ))}

        {/* User nav no footer */}
        <div className="mt-auto p-4 border-t">
          <UserNav user={user} />
        </div>
      </SidebarContent>
    </Sidebar>
  );
}
```

---

## 🗺️ Mapeamento Completo

### Tela ↔ Role ↔ Endpoint

| Tela | Path | Roles | Endpoint Principal | Descrição |
|------|------|-------|-------------------|-----------|
| **DASHBOARDS** |
| Dashboard Vendedor | `/dashboard` | vendedor | `GET /dashboard/seller` | Gauge, bônus, meta |
| Dashboard Conferente | `/dashboard` | conferente | `GET /dashboard/store` + `GET /cash/shifts/pending` | Pendências, divergências |
| Dashboard Admin | `/dashboard` | admin, gerente | `GET /dashboard/admin` | Visão geral lojas |
| **FATURAMENTO** |
| Extrato de Vendas | `/faturamento/extrato-vendas` | vendedor | `GET /sales?seller_id={me}` | Histórico vendas |
| Meus Bônus | `/faturamento/meus-bonus` | vendedor | `GET /finance/bonus/seller/{me}` | Bônus diários |
| Minhas Comissões | `/faturamento/minhas-comissoes` | vendedor | `GET /finance/commission/seller/{me}` + `GET /finance/commission/projection/{me}` | Comissões e projeção |
| **CONFERÊNCIA** |
| Lançar Turno | `/conferencia/lancar-turno` | vendedor, conferente | `GET /cash/shifts` + `POST /cash/closings/{shift}/submit` | Fechamento de caixa |
| Divergências | `/conferencia/divergencias` | conferente, gerente, admin | `GET /cash/shifts/divergent` + `POST /cash/closings/{id}/approve` | Aprovar/rejeitar |
| Histórico Envelopes | `/conferencia/historico` | conferente, gerente, admin | `GET /cash/closings?store_id={store}` | Consulta fechamentos |
| **GESTÃO** |
| Ranking de Vendas | `/gestao/ranking` | gerente, admin | `GET /reports/ranking` | Top vendedores |
| Desempenho Lojas | `/gestao/desempenho` | gerente, admin | `GET /reports/store-performance` + `GET /reports/consolidated` | Performance lojas |
| Quebra de Caixa | `/gestao/quebra-caixa` | conferente, gerente, admin | `GET /reports/cash-integrity` | Divergências acumuladas |
| **CONFIGURAÇÕES** |
| Metas Mensais | `/config/metas` | gerente, admin | `GET /goals/monthly` + `POST /goals/monthly` | CRUD metas |
| Tabela de Bônus | `/config/bonus` | gerente, admin | `GET /rules/bonus` + `POST /rules/bonus` | CRUD regras bônus |
| Regras Comissão | `/config/comissao` | gerente, admin | `GET /rules/commission` + `POST /rules/commission` | CRUD regras comissão |
| Usuários & Lojas | `/config/usuarios` | admin | `GET /admin/users` + `GET /admin/stores` | CRUD usuários/lojas |
| **ADMIN** |
| Logs de Auditoria | `/admin/audit-logs` | admin | `GET /admin/audit-logs` | Logs do sistema |

---

## 📡 Endpoints de Suporte

### Lojas do Usuário

```http
GET /stores
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Mais Capinhas Tijucas",
      "city": "Tijucas",
      "code": "TJC",
      "active": true
    }
  ]
}
```

### Vendedores da Loja

```http
GET /stores/{store_id}/sellers
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 5,
      "name": "João Silva",
      "role": "vendedor",
      "avatar_url": "https://..."
    }
  ]
}
```

---

## 💡 Exemplos de Uso

### Renderizar Dashboard por Role

```typescript
// src/pages/Dashboard.tsx
import { usePermissions } from '@/lib/hooks/usePermissions';
import { DashboardVendedor } from '@/components/dashboards/DashboardVendedor';
import { DashboardConferente } from '@/components/dashboards/DashboardConferente';
import { DashboardAdmin } from '@/components/dashboards/DashboardAdmin';

export function Dashboard() {
  const { currentRole, isLoading } = usePermissions();

  if (isLoading) return <DashboardSkeleton />;

  switch (currentRole) {
    case 'vendedor':
      return <DashboardVendedor />;
    case 'conferente':
      return <DashboardConferente />;
    case 'gerente':
    case 'admin':
      return <DashboardAdmin />;
    default:
      return <DashboardVendedor />;
  }
}
```

### Botão Condicional

```typescript
// Em qualquer componente
import { RoleGuard } from '@/components/RoleGuard';

function ActionButtons() {
  return (
    <div className="flex gap-2">
      {/* Todos veem */}
      <Button>Ver Detalhes</Button>
      
      {/* Só gerente+ */}
      <RoleGuard minRole="gerente">
        <Button variant="outline">Editar</Button>
      </RoleGuard>
      
      {/* Só admin */}
      <RoleGuard roles={['admin']}>
        <Button variant="destructive">Excluir</Button>
      </RoleGuard>
    </div>
  );
}
```

### Proteção de Rota

```typescript
// src/App.tsx
<Route
  path="/config/*"
  element={
    <ProtectedRoute>
      <RoleGuard minRole="gerente" redirectTo="/unauthorized">
        <ConfigLayout />
      </RoleGuard>
    </ProtectedRoute>
  }
/>
```

---

## 📁 Estrutura de Arquivos

```
src/
├── lib/
│   ├── config/
│   │   └── menuConfig.ts       # Configuração do menu
│   ├── hooks/
│   │   ├── usePermissions.ts   # Hook principal
│   │   └── useFilteredMenu.ts  # Hook do menu
│   └── schemas/
│       └── permissions.ts      # Tipos e constantes
├── components/
│   ├── RoleGuard.tsx           # Guard de permissão
│   ├── AppSidebar.tsx          # Sidebar com menu
│   ├── StoreSwitcher.tsx       # Seletor de loja
│   └── dashboards/
│       ├── DashboardVendedor.tsx
│       ├── DashboardConferente.tsx
│       └── DashboardAdmin.tsx
└── pages/
    └── Dashboard.tsx           # Renderiza por role
```

---

## ✅ Checklist de Implementação

- [ ] Schemas Zod para roles e permissões
- [ ] Hook `usePermissions` implementado
- [ ] Componente `RoleGuard` funcionando
- [ ] Menu configurado por role
- [ ] Dashboard dinâmico por role
- [ ] Rotas protegidas por permissão
- [ ] StoreSwitcher para multi-loja
- [ ] Testes de autorização

---

**Última atualização:** 2026-01-08
