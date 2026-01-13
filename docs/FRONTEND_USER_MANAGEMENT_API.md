# API de Gestão de Usuários - Documentação Completa

> **Versão**: 2.0
> **Data**: 2026-01-13
> **Equipe**: Backend → Frontend

---

## Índice

1. [Visão Geral](#visão-geral)
2. [Tipos TypeScript](#tipos-typescript)
3. [Endpoints](#endpoints)
4. [Filtros e Paginação](#filtros-e-paginação)
5. [Exemplos de Uso no Frontend](#exemplos-de-uso-no-frontend)
6. [Tratamento de Erros](#tratamento-de-erros)
7. [Boas Práticas](#boas-práticas)

---

## Visão Geral

### Estrutura de Permissões

```
┌─────────────────────────────────────────────────────────┐
│                       USUÁRIO                            │
├─────────────────────────────────────────────────────────┤
│  is_super_admin: boolean    → Acesso TOTAL ao sistema   │
│  roles: string[]            → Roles globais (fabrica)   │
│  stores: StoreBinding[]     → Vínculos com lojas        │
└─────────────────────────────────────────────────────────┘

StoreBinding = { store_id, store_name, role }
       onde role = "admin" | "gerente" | "conferente" | "vendedor"
```

### Hierarquia de Permissões

| Nível | Condição | Acesso |
|-------|----------|--------|
| 1 | `is_super_admin = true` | Tudo |
| 2 | `is_global_admin = true` | Admin em alguma loja |
| 3 | `has_fabrica_access = true` | Portal da fábrica |
| 4 | `stores[].role = "gerente"` | Gestão da loja específica |
| 5 | `stores[].role = "conferente"` | Conferência de caixa |
| 6 | `stores[].role = "vendedor"` | Vendas na loja |

---

## Tipos TypeScript

```typescript
// ============================================
// ENUMS
// ============================================

/** Roles globais gerenciadas pelo Spatie */
type GlobalRole = 'fabrica';

/** Roles por loja (store_users) */
type StoreRole = 'admin' | 'gerente' | 'conferente' | 'vendedor';

// ============================================
// INTERFACES BASE
// ============================================

interface StoreBinding {
  store_id: number;
  store_name: string;
  role: StoreRole;
}

interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  
  // Permissões
  is_super_admin: boolean;
  is_global_admin: boolean;      // super_admin OU admin em alguma loja
  has_fabrica_access: boolean;   // Tem role 'fabrica'
  roles: GlobalRole[];           // Roles globais do Spatie
  
  // Endereço
  zip_code: string | null;
  street: string | null;
  number: string | null;
  complement: string | null;
  neighborhood: string | null;
  city: string | null;
  state: string | null;
  
  // Perfil
  birth_date: string | null;     // "YYYY-MM-DD"
  hire_date: string | null;      // "YYYY-MM-DD"
  whatsapp: string | null;
  avatar_url: string | null;
  instagram: string | null;
  cpf: string | null;            // Apenas para admins
  pix_key: string | null;        // Apenas para admins
  
  // Timestamps
  created_at: string;            // ISO8601
  updated_at: string;            // ISO8601
  
  // Vínculos com lojas
  stores: StoreBinding[];
}

// ============================================
// REQUESTS
// ============================================

/** POST /admin/users */
interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  active?: boolean;                           // default: true
  is_super_admin?: boolean;                   // default: false
  roles?: GlobalRole[];                       // Ex: ["fabrica"]
  stores?: Array<{
    store_id: number;
    role: StoreRole;
  }>;
  // Campos opcionais de perfil
  zip_code?: string;
  street?: string;
  number?: string;
  complement?: string;
  neighborhood?: string;
  city?: string;
  state?: string;
  birth_date?: string;
  hire_date?: string;
  whatsapp?: string;
  instagram?: string;
  cpf?: string;
  pix_key?: string;
}

/** PATCH /admin/users/{id} */
interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  active?: boolean;
  is_super_admin?: boolean;
  roles?: GlobalRole[];
  // ... demais campos opcionais
}

/** POST /admin/users/{id}/stores/bulk */
interface BulkAddStoresRequest {
  stores: Array<{
    store_id: number;
    role: StoreRole;
  }>;
}

/** PATCH /admin/users/{id}/stores/bulk */
interface BulkUpdateStoresRequest {
  role: StoreRole;
  store_ids: number[];
}

/** DELETE /admin/users/{id}/stores/bulk */
interface BulkRemoveStoresRequest {
  store_ids: number[];
}

/** PUT /admin/users/{id}/stores */
interface SyncStoresRequest {
  stores: Array<{
    store_id: number;
    role: StoreRole;
  }>;
}

// ============================================
// RESPONSES
// ============================================

interface ApiResponse<T> {
  data: T;
  meta?: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

interface BulkAddResponse {
  message: string;
  created: number[];
  skipped: number[];
}

interface BulkUpdateResponse {
  message: string;
  updated_count: number;
}

interface BulkRemoveResponse {
  message: string;
  deleted_count: number;
}

interface SyncResponse {
  message: string;
  user: User;
}

interface ValidationError {
  message: string;
  errors: Record<string, string[]>;
}
```

---

## Endpoints

### 1. Listar Usuários

```http
GET /api/v1/admin/users
Authorization: Bearer {token}
```

#### Query Parameters

| Param | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `search` | string | ❌ | Busca por nome ou email |
| `active` | boolean | ❌ | Filtrar por status ativo |
| `store_id` | number | ❌ | Filtrar por loja específica |
| `has_stores` | boolean | ❌ | `true` = com lojas, `false` = sem lojas |
| `role` | string | ❌ | Filtrar por role global (ex: `fabrica`) |
| `is_global_admin` | boolean | ❌ | `true` = super_admin ou admin em loja |
| `per_page` | number | ❌ | Itens por página (1-100, default: 25) |
| `page` | number | ❌ | Página atual |

#### Response 200

```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@empresa.com",
      "active": true,
      "is_super_admin": true,
      "is_global_admin": true,
      "has_fabrica_access": false,
      "roles": [],
      "stores": [
        { "store_id": 1, "store_name": "Loja Centro", "role": "admin" }
      ],
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-10T12:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 42,
    "last_page": 2
  }
}
```

#### Response 403

```json
{
  "message": "Apenas administradores podem acessar este recurso."
}
```

---

### 2. Criar Usuário

```http
POST /api/v1/admin/users
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body

```json
{
  "name": "João Silva",
  "email": "joao.silva@empresa.com",
  "password": "senha123456",
  "active": true,
  "is_super_admin": false,
  "roles": ["fabrica"],
  "stores": [
    { "store_id": 1, "role": "vendedor" },
    { "store_id": 2, "role": "vendedor" }
  ],
  "whatsapp": "47999999999",
  "birth_date": "1990-05-15",
  "hire_date": "2026-01-10"
}
```

#### Response 201

```json
{
  "data": {
    "id": 43,
    "name": "João Silva",
    "email": "joao.silva@empresa.com",
    "active": true,
    "is_super_admin": false,
    "is_global_admin": false,
    "has_fabrica_access": true,
    "roles": ["fabrica"],
    "stores": [
      { "store_id": 1, "store_name": "Loja Centro", "role": "vendedor" },
      { "store_id": 2, "store_name": "Loja Shopping", "role": "vendedor" }
    ],
    "created_at": "2026-01-13T11:00:00+00:00"
  }
}
```

#### Response 422 - Validação

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Este email já está em uso."],
    "password": ["A senha deve ter pelo menos 8 caracteres."],
    "stores.0.store_id": ["Uma das lojas informadas não existe."]
  }
}
```

---

### 3. Atualizar Usuário

```http
PATCH /api/v1/admin/users/{id}
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body (campos opcionais)

```json
{
  "name": "João Silva Santos",
  "roles": ["fabrica"],
  "active": true
}
```

#### Response 200

```json
{
  "data": {
    "id": 43,
    "name": "João Silva Santos",
    "email": "joao.silva@empresa.com",
    "is_global_admin": false,
    "has_fabrica_access": true,
    "roles": ["fabrica"],
    "stores": [...]
  }
}
```

---

### 4. Adicionar a Múltiplas Lojas (Bulk)

```http
POST /api/v1/admin/users/{id}/stores/bulk
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body

```json
{
  "stores": [
    { "store_id": 1, "role": "vendedor" },
    { "store_id": 2, "role": "vendedor" },
    { "store_id": 3, "role": "gerente" }
  ]
}
```

#### Response 200

```json
{
  "data": {
    "message": "3 vínculo(s) criado(s), 0 ignorado(s).",
    "created": [1, 2, 3],
    "skipped": []
  }
}
```

> **Nota:** Se o usuário já estiver vinculado a uma loja, ela é ignorada (não atualiza).

---

### 5. Alterar Role em Múltiplas Lojas (Bulk)

```http
PATCH /api/v1/admin/users/{id}/stores/bulk
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body

```json
{
  "role": "gerente",
  "store_ids": [1, 2, 3]
}
```

#### Response 200

```json
{
  "data": {
    "message": "3 vínculo(s) atualizado(s).",
    "updated_count": 3
  }
}
```

> **Caso de uso:** Promover vendedor para gerente em todas as lojas.

---

### 6. Remover de Múltiplas Lojas (Bulk)

```http
DELETE /api/v1/admin/users/{id}/stores/bulk
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body

```json
{
  "store_ids": [1, 2, 3]
}
```

#### Response 200

```json
{
  "data": {
    "message": "3 vínculo(s) removido(s).",
    "deleted_count": 3
  }
}
```

#### Response 403

```json
{
  "message": "Você não pode remover seus próprios vínculos."
}
```

---

### 7. Sincronizar Lojas (Replace All)

```http
PUT /api/v1/admin/users/{id}/stores
Authorization: Bearer {token}
Content-Type: application/json
```

#### Request Body

```json
{
  "stores": [
    { "store_id": 1, "role": "vendedor" },
    { "store_id": 2, "role": "gerente" }
  ]
}
```

#### Response 200

```json
{
  "data": {
    "message": "2 vínculo(s) sincronizado(s).",
    "user": {
      "id": 43,
      "stores": [
        { "store_id": 1, "store_name": "Loja Centro", "role": "vendedor" },
        { "store_id": 2, "store_name": "Loja Shopping", "role": "gerente" }
      ]
    }
  }
}
```

> ⚠️ **Atenção:** Este endpoint REMOVE todos os vínculos existentes e cria apenas os listados.

---

## Filtros e Paginação

### Padrão de Uso

```typescript
// api/users.ts
import { api } from '@/lib/api';

interface UserFilters {
  search?: string;
  active?: boolean;
  store_id?: number;
  has_stores?: boolean;
  role?: GlobalRole;
  is_global_admin?: boolean;
  per_page?: number;
  page?: number;
}

export async function getUsers(filters: UserFilters = {}) {
  const params = new URLSearchParams();
  
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      params.append(key, String(value));
    }
  });

  return api.get<ApiResponse<User[]>>(`/admin/users?${params}`);
}
```

### Exemplos de Filtros Combinados

```typescript
// Usuários da fábrica sem loja
getUsers({ role: 'fabrica', has_stores: false });

// Admins inativos
getUsers({ is_global_admin: true, active: false });

// Vendedores de uma loja específica
getUsers({ store_id: 1, search: 'joão' });

// Usuários órfãos (sem nenhuma loja)
getUsers({ has_stores: false });
```

---

## Exemplos de Uso no Frontend

### 1. Hook para Gestão de Usuários (React Query)

```typescript
// hooks/useUsers.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export function useUsers(filters: UserFilters = {}) {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => getUsers(filters),
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: CreateUserRequest) => 
      api.post<ApiResponse<User>>('/admin/users', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

export function useBulkAddStores(userId: number) {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: BulkAddStoresRequest) =>
      api.post<ApiResponse<BulkAddResponse>>(
        `/admin/users/${userId}/stores/bulk`,
        data
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      queryClient.invalidateQueries({ queryKey: ['user', userId] });
    },
  });
}
```

### 2. Formulário de Criação de Usuário

```typescript
// components/UserForm.tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const userSchema = z.object({
  name: z.string().min(1, 'Nome é obrigatório'),
  email: z.string().email('Email inválido'),
  password: z.string().min(8, 'Mínimo 8 caracteres'),
  roles: z.array(z.enum(['fabrica'])).optional(),
  stores: z.array(z.object({
    store_id: z.number(),
    role: z.enum(['admin', 'gerente', 'conferente', 'vendedor']),
  })).optional(),
});

export function UserForm() {
  const { mutate: createUser, isPending } = useCreateUser();
  
  const form = useForm<CreateUserRequest>({
    resolver: zodResolver(userSchema),
    defaultValues: {
      active: true,
      is_super_admin: false,
      roles: [],
      stores: [],
    },
  });

  const onSubmit = (data: CreateUserRequest) => {
    createUser(data, {
      onSuccess: () => {
        toast.success('Usuário criado com sucesso!');
        router.push('/admin/users');
      },
      onError: (error) => {
        if (error.response?.status === 422) {
          const errors = error.response.data.errors;
          Object.entries(errors).forEach(([field, messages]) => {
            form.setError(field as any, { message: messages[0] });
          });
        }
      },
    });
  };

  return (
    <form onSubmit={form.handleSubmit(onSubmit)}>
      {/* ... campos do formulário */}
    </form>
  );
}
```

### 3. Componente de Seleção de Lojas em Massa

```typescript
// components/BulkStoreSelector.tsx
interface Props {
  userId: number;
  stores: Store[];
  currentStores: StoreBinding[];
}

export function BulkStoreSelector({ userId, stores, currentStores }: Props) {
  const [selectedStores, setSelectedStores] = useState<number[]>([]);
  const [selectedRole, setSelectedRole] = useState<StoreRole>('vendedor');
  
  const { mutate: bulkAdd, isPending } = useBulkAddStores(userId);
  
  const availableStores = stores.filter(
    s => !currentStores.some(cs => cs.store_id === s.id)
  );

  const handleAdd = () => {
    bulkAdd({
      stores: selectedStores.map(store_id => ({
        store_id,
        role: selectedRole,
      })),
    }, {
      onSuccess: (response) => {
        const { created, skipped } = response.data;
        toast.success(`${created.length} loja(s) adicionada(s)`);
        if (skipped.length > 0) {
          toast.info(`${skipped.length} já estavam vinculadas`);
        }
        setSelectedStores([]);
      },
    });
  };

  return (
    <div>
      <MultiSelect
        options={availableStores.map(s => ({ 
          value: s.id, 
          label: s.name 
        }))}
        value={selectedStores}
        onChange={setSelectedStores}
        placeholder="Selecione as lojas..."
      />
      
      <Select
        value={selectedRole}
        onChange={setSelectedRole}
        options={[
          { value: 'vendedor', label: 'Vendedor' },
          { value: 'conferente', label: 'Conferente' },
          { value: 'gerente', label: 'Gerente' },
          { value: 'admin', label: 'Admin' },
        ]}
      />
      
      <Button 
        onClick={handleAdd} 
        disabled={isPending || selectedStores.length === 0}
      >
        Adicionar a {selectedStores.length} loja(s)
      </Button>
    </div>
  );
}
```

### 4. Decisão de Menu/Permissões

```typescript
// hooks/usePermissions.ts
import { useMemo } from 'react';

export function usePermissions(user: User | null) {
  return useMemo(() => ({
    // Acesso total
    isSuperAdmin: user?.is_super_admin ?? false,
    
    // Admin em pelo menos uma loja
    isGlobalAdmin: user?.is_global_admin ?? false,
    
    // Fábrica
    canAccessFabrica: user?.has_fabrica_access || user?.is_global_admin,
    
    // Produção (carrinho)
    canAccessProducao: user?.is_global_admin ?? false,
    
    // Gestão de usuários
    canManageUsers: user?.is_global_admin ?? false,
    
    // Verifica se é admin em loja específica
    isAdminOf: (storeId: number) => 
      user?.stores?.some(s => s.store_id === storeId && s.role === 'admin'),
    
    // Verifica qualquer acesso a loja
    hasAccessTo: (storeId: number) =>
      user?.stores?.some(s => s.store_id === storeId),
    
    // Lista de lojas onde é admin
    adminStores: user?.stores?.filter(s => s.role === 'admin') ?? [],
    
  }), [user]);
}

// Uso no componente
function Sidebar() {
  const { data: me } = useMe();
  const perms = usePermissions(me?.user);
  
  return (
    <nav>
      {perms.canManageUsers && (
        <NavLink to="/admin/users">Usuários</NavLink>
      )}
      {perms.canAccessFabrica && (
        <NavLink to="/fabrica">Portal Fábrica</NavLink>
      )}
      {perms.canAccessProducao && (
        <NavLink to="/producao">Produção</NavLink>
      )}
    </nav>
  );
}
```

---

## Tratamento de Erros

### Códigos HTTP

| Código | Significado | Ação no Frontend |
|--------|-------------|------------------|
| 200 | Sucesso | Atualizar dados |
| 201 | Criado | Redirect + toast success |
| 400 | Bad Request | Mostrar mensagem |
| 403 | Sem permissão | Mostrar mensagem + redirect |
| 404 | Não encontrado | Mostrar mensagem |
| 422 | Validação | Mostrar erros por campo |
| 500 | Erro servidor | Toast genérico |

### Handler Global de Erros

```typescript
// lib/api.ts
import axios from 'axios';
import { toast } from 'sonner';

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const message = error.response?.data?.message;

    switch (status) {
      case 401:
        // Token expirado - redirecionar para login
        window.location.href = '/login';
        break;
      case 403:
        toast.error(message || 'Sem permissão para esta ação');
        break;
      case 404:
        toast.error('Recurso não encontrado');
        break;
      case 422:
        // Não mostrar toast - erros serão exibidos no form
        break;
      case 500:
        toast.error('Erro interno do servidor');
        break;
    }

    return Promise.reject(error);
  }
);
```

---

## Boas Práticas

### 1. Sempre Validar no Frontend

```typescript
// Validar antes de enviar
const validateStores = (stores: StoreBinding[]) => {
  const storeIds = stores.map(s => s.store_id);
  const hasDuplicates = new Set(storeIds).size !== storeIds.length;
  
  if (hasDuplicates) {
    throw new Error('Não é permitido duplicar lojas');
  }
};
```

### 2. Usar Optimistic Updates

```typescript
useMutation({
  mutationFn: updateUser,
  onMutate: async (newData) => {
    await queryClient.cancelQueries({ queryKey: ['user', userId] });
    const previous = queryClient.getQueryData(['user', userId]);
    queryClient.setQueryData(['user', userId], newData);
    return { previous };
  },
  onError: (err, newData, context) => {
    queryClient.setQueryData(['user', userId], context?.previous);
  },
  onSettled: () => {
    queryClient.invalidateQueries({ queryKey: ['user', userId] });
  },
});
```

### 3. Cache Inteligente

```typescript
// Invalidar queries relacionadas
const invalidateUserQueries = (userId: number) => {
  queryClient.invalidateQueries({ queryKey: ['users'] });
  queryClient.invalidateQueries({ queryKey: ['user', userId] });
  queryClient.invalidateQueries({ queryKey: ['me'] }); // Se for o próprio usuário
};
```

### 4. Loading States por Operação

```typescript
const [loadingOperation, setLoadingOperation] = useState<string | null>(null);

const handleBulkAdd = async () => {
  setLoadingOperation('bulk-add');
  try {
    await bulkAddStores(data);
  } finally {
    setLoadingOperation(null);
  }
};

// No botão
<Button 
  loading={loadingOperation === 'bulk-add'}
  disabled={loadingOperation !== null}
>
  Adicionar
</Button>
```

---

## Resumo de Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/users` | Listar com filtros |
| POST | `/admin/users` | Criar com roles e stores |
| GET | `/admin/users/{id}` | Detalhes |
| PATCH | `/admin/users/{id}` | Atualizar |
| DELETE | `/admin/users/{id}` | Desativar |
| PUT | `/admin/users/{id}/stores` | Sync lojas |
| POST | `/admin/users/{id}/stores/bulk` | Bulk adicionar |
| PATCH | `/admin/users/{id}/stores/bulk` | Bulk atualizar role |
| DELETE | `/admin/users/{id}/stores/bulk` | Bulk remover |

---

Qualquer dúvida, estamos à disposição! 🚀
