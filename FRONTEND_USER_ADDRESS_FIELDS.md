# Guia de Integração: Campos de Endereço do Usuário

> **Data:** 2026-01-10  
> **Versão da API:** v1

## Resumo da Alteração

Foram adicionados **7 novos campos de endereço** ao modelo de usuário. Esses campos estão disponíveis nos endpoints de CRUD de usuários (`/api/v1/admin/users`).

---

## Novos Campos

| Campo | Tipo | Tamanho Máx. | Obrigatório | Descrição |
|-------|------|--------------|-------------|-----------|
| `zip_code` | string | 8 | Não | CEP (apenas números) |
| `street` | string | 255 | Não | Logradouro (rua, avenida, etc.) |
| `number` | string | 20 | Não | Número do endereço |
| `complement` | string | 255 | Não | Complemento (apto, bloco, etc.) |
| `neighborhood` | string | 100 | Não | Bairro |
| `city` | string | 255 | Não | Cidade |
| `state` | string | 2 | Não | UF do estado (ex: SC, SP, RJ) |

---

## Endpoints Afetados

### 1. Criar Usuário

```http
POST /api/v1/admin/users
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body (com campos de endereço):**

```json
{
  "name": "João Silva Santos",
  "email": "joao.silva@exemplo.com",
  "password": "senha123456",
  "active": true,
  "is_super_admin": false,
  
  "zip_code": "88220000",
  "street": "Rua das Flores",
  "number": "123",
  "complement": "Apto 45",
  "neighborhood": "Centro",
  "city": "Itapema",
  "state": "SC",
  
  "birth_date": "1990-05-15",
  "hire_date": "2025-01-01",
  "whatsapp": "47999999999",
  "instagram": "@joaosilva",
  "cpf": "123.456.789-00",
  "pix_key": "joao@email.com",
  
  "stores": [
    { "store_id": 1, "role": "vendedor" }
  ]
}
```

---

### 2. Atualizar Usuário

```http
PUT /api/v1/admin/users/{id}
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body (atualização parcial):**

```json
{
  "zip_code": "88220000",
  "street": "Rua Nova",
  "number": "456",
  "complement": "Casa",
  "neighborhood": "Meia Praia",
  "city": "Itapema",
  "state": "SC"
}
```

> **Nota:** Apenas os campos enviados serão atualizados. Os demais permanecem inalterados.

---

### 3. Resposta da API (GET/POST/PUT)

```json
{
  "data": {
    "id": 1,
    "name": "João Silva Santos",
    "email": "joao.silva@exemplo.com",
    "active": true,
    "is_super_admin": false,
    
    "zip_code": "88220000",
    "street": "Rua das Flores",
    "number": "123",
    "complement": "Apto 45",
    "neighborhood": "Centro",
    "city": "Itapema",
    "state": "SC",
    
    "birth_date": "1990-05-15",
    "hire_date": "2025-01-01",
    "whatsapp": "47999999999",
    "avatar_url": null,
    "instagram": "@joaosilva",
    "cpf": "123.456.789-00",
    "pix_key": "joao@email.com",
    
    "created_at": "2026-01-10T12:00:00+00:00",
    "updated_at": "2026-01-10T14:30:00+00:00",
    
    "stores": [
      {
        "store_id": 1,
        "store_name": "Mais Capinhas Tijucas",
        "role": "vendedor"
      }
    ]
  }
}
```

---

## TypeScript Interface

```typescript
interface UserAddress {
  zip_code: string | null;
  street: string | null;
  number: string | null;
  complement: string | null;
  neighborhood: string | null;
  city: string | null;
  state: string | null;
}

interface User extends UserAddress {
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
  stores?: UserStore[];
}

interface UserStore {
  store_id: number;
  store_name: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

// Para criar/atualizar usuário
interface UserFormData {
  name: string;
  email: string;
  password?: string;
  active?: boolean;
  is_super_admin?: boolean;
  
  // Address
  zip_code?: string;
  street?: string;
  number?: string;
  complement?: string;
  neighborhood?: string;
  city?: string;
  state?: string;
  
  // Profile
  birth_date?: string;
  hire_date?: string;
  whatsapp?: string;
  instagram?: string;
  cpf?: string;
  pix_key?: string;
  
  stores?: { store_id: number; role: string }[];
}
```

---

## Validações

| Campo | Regras |
|-------|--------|
| `zip_code` | Máximo 8 caracteres |
| `street` | Máximo 255 caracteres |
| `number` | Máximo 20 caracteres |
| `complement` | Máximo 255 caracteres |
| `neighborhood` | Máximo 100 caracteres |
| `city` | Máximo 255 caracteres |
| `state` | Exatamente 2 caracteres (UF) |

---

## Dica: Busca de CEP

Para melhorar a UX, recomendamos integrar com a API ViaCEP para auto-preencher o endereço:

```typescript
async function fetchAddressByCep(cep: string) {
  const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
  const data = await response.json();
  
  if (data.erro) {
    throw new Error('CEP não encontrado');
  }
  
  return {
    street: data.logradouro,
    neighborhood: data.bairro,
    city: data.localidade,
    state: data.uf,
  };
}
```

---

## Erros Possíveis

| Status | Mensagem | Causa |
|--------|----------|-------|
| 422 | `state must be 2 characters` | UF inválida |
| 422 | `zip_code may not be greater than 8 characters` | CEP muito longo |
| 403 | `Apenas administradores podem acessar este recurso` | Usuário não é admin |
