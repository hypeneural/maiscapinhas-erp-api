# API Admin - Gerenciamento de Usuários e Lojas

Documentação completa dos endpoints administrativos para CRUD de Usuários e Lojas.

> [!IMPORTANT]
> **Permissão:** Apenas usuários com role `admin` ou `is_super_admin = true` podem acessar estes endpoints.

---

## Autenticação

Todas as requisições devem incluir:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

---

# 1. CRUD de Usuários

**Base URL:** `/api/v1/admin/users`

## 1.1 Listar Usuários

```http
GET /api/v1/admin/users
```

### Query Parameters

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `search` | string | Busca por nome ou email |
| `active` | boolean | Filtrar por status (true/false) |
| `store_id` | integer | Filtrar por loja |
| `per_page` | integer | Itens por página (1-100, default: 25) |

### Response Schema

```typescript
interface UsersListResponse {
  data: User[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;
  birth_date: string | null;     // "YYYY-MM-DD"
  hire_date: string | null;      // "YYYY-MM-DD"
  whatsapp: string | null;
  avatar_url: string | null;
  instagram: string | null;
  cpf: string | null;            // Apenas para admin
  pix_key: string | null;        // Apenas para admin
  created_at: string;
  updated_at: string;
  stores: UserStore[];
}

interface UserStore {
  store_id: number;
  store_name: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}
```

---

## 1.2 Criar Usuário

```http
POST /api/v1/admin/users
```

### Request Body Schema

```typescript
interface CreateUserRequest {
  // Campos obrigatórios
  name: string;              // max: 255
  email: string;             // unique, formato email
  password: string;          // min: 8 caracteres
  
  // Campos opcionais
  active?: boolean;          // default: true
  is_super_admin?: boolean;  // default: false
  birth_date?: string;       // formato: "YYYY-MM-DD", deve ser anterior a hoje
  hire_date?: string;        // formato: "YYYY-MM-DD", deve ser até hoje
  whatsapp?: string;         // max: 20
  instagram?: string;        // max: 50, ex: "@usuario"
  cpf?: string;              // max: 14, único, ex: "123.456.789-00"
  pix_key?: string;          // max: 255
  
  // Vínculos com lojas (opcional)
  stores?: StoreBinding[];
}

interface StoreBinding {
  store_id: number;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}
```

### Exemplo de Request

```javascript
const response = await fetch('/api/v1/admin/users', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    name: 'João Silva Santos',
    email: 'joao.silva@maiscapinhas.com.br',
    password: 'senha123456',
    birth_date: '1990-05-15',
    hire_date: '2024-01-10',
    whatsapp: '47999999999',
    instagram: '@joaosilva',
    cpf: '123.456.789-00',
    pix_key: 'joao@pix.com',
    stores: [
      { store_id: 1, role: 'vendedor' }
    ]
  })
});
```

### Response (201 Created)

```json
{
  "data": {
    "id": 11,
    "name": "João Silva Santos",
    "email": "joao.silva@maiscapinhas.com.br",
    "active": true,
    "is_super_admin": false,
    "birth_date": "1990-05-15",
    "hire_date": "2024-01-10",
    "whatsapp": "47999999999",
    "instagram": "@joaosilva",
    "cpf": "123.456.789-00",
    "pix_key": "joao@pix.com",
    "stores": [
      { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "vendedor" }
    ]
  }
}
```

### Erros de Validação (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Este email já está em uso."],
    "cpf": ["Este CPF já está cadastrado."],
    "birth_date": ["A data de nascimento deve ser anterior a hoje."]
  }
}
```

---

## 1.3 Ver Usuário

```http
GET /api/v1/admin/users/{id}
```

### Response (200 OK)

Retorna o objeto `User` completo (mesmo schema da listagem).

---

## 1.4 Atualizar Usuário

```http
PUT /api/v1/admin/users/{id}
```

### Request Body Schema

Todos os campos são **opcionais**. Envie apenas os que deseja alterar.

```typescript
interface UpdateUserRequest {
  name?: string;
  email?: string;             // unique (ignora o próprio usuário)
  password?: string;          // min: 8
  active?: boolean;
  is_super_admin?: boolean;
  birth_date?: string | null;
  hire_date?: string | null;
  whatsapp?: string | null;
  instagram?: string | null;
  cpf?: string | null;        // unique (ignora o próprio usuário)
  pix_key?: string | null;
}
```

### Exemplo

```javascript
await fetch(`/api/v1/admin/users/${userId}`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    whatsapp: '47988887777',
    pix_key: 'novopix@email.com'
  })
});
```

---

## 1.5 Desativar Usuário

```http
DELETE /api/v1/admin/users/{id}
```

> **Nota:** Não exclui o usuário, apenas define `active = false` e revoga todos os tokens.

### Response (200 OK)

```json
{
  "data": { "message": "Usuário desativado com sucesso." }
}
```

### Erro (403 Forbidden)

```json
{
  "message": "Você não pode desativar seu próprio usuário."
}
```

---

# 2. CRUD de Lojas

**Base URL:** `/api/v1/admin/stores`

## 2.1 Listar Lojas

```http
GET /api/v1/admin/stores
```

### Query Parameters

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `search` | string | Busca por nome ou cidade |
| `active` | boolean | Filtrar por status |
| `per_page` | integer | Itens por página (1-100, default: 25) |

### Response Schema

```typescript
interface StoresListResponse {
  data: Store[];
  meta: PaginationMeta;
}

interface Store {
  id: number;
  name: string;
  codigo: string | null;
  city: string;
  active: boolean;
  troco_padrao: number | null;
  
  // Imagem
  photo_url: string | null;
  
  // Endereço
  address: string | null;
  neighborhood: string | null;
  state: string | null;          // UF (2 chars)
  zip_code: string | null;
  full_address: string | null;   // Calculado pelo backend
  
  // GPS
  latitude: number | null;
  longitude: number | null;
  
  // Contato
  phone: string | null;
  whatsapp: string | null;
  instagram: string | null;
  
  // Horários
  opening_hours: Record<string, string> | null;
  
  // Business
  cnpj: string | null;
  
  created_at: string;
  updated_at: string;
  
  // Relacionamentos (quando carregados)
  users_count?: number;
  users?: StoreUser[];
}

interface StoreUser {
  user_id: number;
  user_name: string;
  user_email: string;
  role: string;
}
```

---

## 2.2 Criar Loja

```http
POST /api/v1/admin/stores
```

### Request Body Schema

```typescript
interface CreateStoreRequest {
  // Campos obrigatórios
  name: string;               // max: 255
  city: string;               // max: 255
  
  // Campos opcionais
  active?: boolean;           // default: true
  codigo?: string;            // max: 20
  
  // Endereço
  address?: string;           // max: 255
  neighborhood?: string;      // max: 100
  state?: string;             // max: 2 (UF)
  zip_code?: string;          // max: 10
  
  // Geolocalização
  latitude?: number;          // range: -90 a 90
  longitude?: number;         // range: -180 a 180
  
  // Contato
  phone?: string;             // max: 20
  whatsapp?: string;          // max: 20
  instagram?: string;         // max: 50
  
  // Negócio
  opening_hours?: Record<string, string>;  // ex: { "segunda": "09:00-18:00" }
  cnpj?: string;              // max: 18
  troco_padrao?: number;      // min: 0
}
```

### Exemplo de Request

```javascript
const response = await fetch('/api/v1/admin/stores', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    name: 'Mais Capinhas Shopping',
    city: 'Florianópolis',
    codigo: 'FLN01',
    address: 'Rua das Flores, 123',
    neighborhood: 'Centro',
    state: 'SC',
    zip_code: '88000-000',
    latitude: -27.5954,
    longitude: -48.5480,
    phone: '4833334444',
    whatsapp: '48999998888',
    instagram: '@maiscapinhasfln',
    cnpj: '12.345.678/0001-90',
    troco_padrao: 200.00,
    opening_hours: {
      'segunda': '09:00-18:00',
      'terca': '09:00-18:00',
      'quarta': '09:00-18:00',
      'quinta': '09:00-18:00',
      'sexta': '09:00-18:00',
      'sabado': '09:00-13:00',
      'domingo': 'Fechado'
    }
  })
});
```

### Response (201 Created)

```json
{
  "data": {
    "id": 4,
    "name": "Mais Capinhas Shopping",
    "codigo": "FLN01",
    "city": "Florianópolis",
    "active": true,
    "troco_padrao": 200.00,
    "address": "Rua das Flores, 123",
    "neighborhood": "Centro",
    "state": "SC",
    "zip_code": "88000-000",
    "full_address": "Rua das Flores, 123, Centro, Florianópolis, SC, 88000-000",
    "latitude": -27.5954,
    "longitude": -48.5480,
    "phone": "4833334444",
    "whatsapp": "48999998888",
    "instagram": "@maiscapinhasfln",
    "cnpj": "12.345.678/0001-90",
    "opening_hours": { ... },
    "created_at": "2026-01-10T14:00:00Z"
  }
}
```

---

## 2.3 Ver Loja

```http
GET /api/v1/admin/stores/{id}
```

Retorna a loja com a lista de usuários vinculados (`users`).

---

## 2.4 Atualizar Loja

```http
PUT /api/v1/admin/stores/{id}
```

Todos os campos são **opcionais**. Envie apenas o que deseja alterar.

```javascript
await fetch(`/api/v1/admin/stores/${storeId}`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    troco_padrao: 300.00,
    whatsapp: '48999997777'
  })
});
```

---

## 2.5 Desativar Loja

```http
DELETE /api/v1/admin/stores/{id}
```

Define `active = false`. Dados históricos são mantidos.

---

# 3. Tratamento de Erros

| Código | Descrição | Ação |
|--------|-----------|------|
| 401 | Token inválido/expirado | Redirecionar para login |
| 403 | Sem permissão (não é admin) | Mostrar mensagem de acesso negado |
| 404 | Recurso não encontrado | Mostrar mensagem |
| 422 | Validação falhou | Exibir erros nos campos |
| 500 | Erro interno | Mostrar mensagem genérica |

---

# 4. TypeScript Interfaces Completas

```typescript
// types/admin.ts

export interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;
  birth_date: string | null;
  hire_date: string | null;
  whatsapp: string | null;
  avatar_url: string | null;
  instagram: string | null;
  cpf: string | null;
  pix_key: string | null;
  created_at: string;
  updated_at: string;
  stores: UserStore[];
}

export interface CreateUserPayload {
  name: string;
  email: string;
  password: string;
  active?: boolean;
  is_super_admin?: boolean;
  birth_date?: string;
  hire_date?: string;
  whatsapp?: string;
  instagram?: string;
  cpf?: string;
  pix_key?: string;
  stores?: { store_id: number; role: string }[];
}

export interface UpdateUserPayload {
  name?: string;
  email?: string;
  password?: string;
  active?: boolean;
  is_super_admin?: boolean;
  birth_date?: string | null;
  hire_date?: string | null;
  whatsapp?: string | null;
  instagram?: string | null;
  cpf?: string | null;
  pix_key?: string | null;
}

export interface Store {
  id: number;
  name: string;
  codigo: string | null;
  city: string;
  active: boolean;
  troco_padrao: number | null;
  photo_url: string | null;
  address: string | null;
  neighborhood: string | null;
  state: string | null;
  zip_code: string | null;
  full_address: string | null;
  latitude: number | null;
  longitude: number | null;
  phone: string | null;
  whatsapp: string | null;
  instagram: string | null;
  opening_hours: Record<string, string> | null;
  cnpj: string | null;
  created_at: string;
  updated_at: string;
  users_count?: number;
  users?: StoreUser[];
}

export interface CreateStorePayload {
  name: string;
  city: string;
  active?: boolean;
  codigo?: string;
  address?: string;
  neighborhood?: string;
  state?: string;
  zip_code?: string;
  latitude?: number;
  longitude?: number;
  phone?: string;
  whatsapp?: string;
  instagram?: string;
  opening_hours?: Record<string, string>;
  cnpj?: string;
  troco_padrao?: number;
}

export interface UpdateStorePayload extends Partial<CreateStorePayload> {}
```

---

# 5. Hooks de Exemplo

```typescript
// hooks/useAdminUsers.ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { User, CreateUserPayload, UpdateUserPayload } from '@/types/admin';

export function useAdminUsers(params?: { search?: string; active?: boolean; store_id?: number }) {
  return useQuery({
    queryKey: ['admin-users', params],
    queryFn: () => api.get('/admin/users', { params }).then(r => r.data),
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: CreateUserPayload) => api.post('/admin/users', data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
  });
}

export function useUpdateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateUserPayload }) => 
      api.put(`/admin/users/${id}`, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
  });
}
```
