# 📱 Catálogo de Aparelhos - Documentação Frontend

> **BREAKING CHANGE** - Os endpoints de Phone Brands e Phone Models foram migrados para um novo módulo.

---

## Migração de Endpoints

| Endpoint Antigo | Novo Endpoint |
|-----------------|---------------|
| `GET /api/v1/phone-brands` | `GET /api/v1/phone-catalog/brands` |
| `POST /api/v1/phone-brands` | `POST /api/v1/phone-catalog/brands` |
| `GET /api/v1/phone-brands/{id}` | `GET /api/v1/phone-catalog/brands/{id}` |
| `PUT /api/v1/phone-brands/{id}` | `PUT /api/v1/phone-catalog/brands/{id}` |
| `DELETE /api/v1/phone-brands/{id}` | `DELETE /api/v1/phone-catalog/brands/{id}` |
| `GET /api/v1/phone-models` | `GET /api/v1/phone-catalog/models` |
| `POST /api/v1/phone-models` | `POST /api/v1/phone-catalog/models` |
| `GET /api/v1/phone-models/{id}` | `GET /api/v1/phone-catalog/models/{id}` |
| `PUT /api/v1/phone-models/{id}` | `PUT /api/v1/phone-catalog/models/{id}` |
| `DELETE /api/v1/phone-models/{id}` | `DELETE /api/v1/phone-catalog/models/{id}` |

---

## Permissões Necessárias

| Permissão | Descrição | Endpoints |
|-----------|-----------|-----------|
| `phone_catalog.view` | Ver marcas e modelos | GET |
| `phone_catalog.create` | Criar marcas e modelos | POST |
| `phone_catalog.update` | Editar marcas e modelos | PUT, PATCH |
| `phone_catalog.delete` | Excluir marcas e modelos | DELETE |

---

## 📦 Brands (Marcas)

### Listar Marcas

```http
GET /api/v1/phone-catalog/brands
```

**Query Parameters:**
| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `search` | string | Buscar por nome |
| `slug` | string | Filtrar por slug |
| `sort` | string | Campo para ordenação (default: `brand_name`) |
| `direction` | string | `asc` ou `desc` (default: `asc`) |
| `per_page` | integer | Itens por página, máx 100 (default: 50) |

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "brand_name": "Apple",
      "brand_slug": "apple",
      "parent_company": null,
      "models_count": 15,
      "created_at": "2025-01-15T10:00:00+00:00",
      "updated_at": "2025-01-15T10:00:00+00:00"
    },
    {
      "id": 2,
      "brand_name": "Samsung",
      "brand_slug": "samsung",
      "parent_company": "Samsung Electronics",
      "models_count": 25,
      "created_at": "2025-01-15T10:00:00+00:00",
      "updated_at": "2025-01-15T10:00:00+00:00"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 50,
    "to": 50,
    "total": 120
  }
}
```

### Criar Marca

```http
POST /api/v1/phone-catalog/brands
```

**Request Body:**
```json
{
  "brand_name": "Motorola",
  "brand_slug": "motorola",
  "parent_company": "Lenovo"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `brand_name` | string | ✅ | Nome da marca (único) |
| `brand_slug` | string | ❌ | Slug (gerado automaticamente se não informado) |
| `parent_company` | string | ❌ | Empresa controladora |

**Response 201:**
```json
{
  "message": "Marca criada com sucesso.",
  "data": {
    "id": 3,
    "brand_name": "Motorola",
    "brand_slug": "motorola",
    "parent_company": "Lenovo",
    "created_at": "2025-01-16T10:00:00+00:00",
    "updated_at": "2025-01-16T10:00:00+00:00"
  }
}
```

### Detalhes da Marca

```http
GET /api/v1/phone-catalog/brands/{id}
```

**Response 200:**
```json
{
  "data": {
    "id": 1,
    "brand_name": "Apple",
    "brand_slug": "apple",
    "parent_company": null,
    "models_count": 15,
    "created_at": "2025-01-15T10:00:00+00:00",
    "updated_at": "2025-01-15T10:00:00+00:00"
  }
}
```

### Atualizar Marca

```http
PUT /api/v1/phone-catalog/brands/{id}
PATCH /api/v1/phone-catalog/brands/{id}
```

**Request Body:**
```json
{
  "brand_name": "Apple Inc."
}
```

### Excluir Marca

```http
DELETE /api/v1/phone-catalog/brands/{id}
```

> ⚠️ **Regra de negócio:** Marcas com modelos vinculados **não podem ser excluídas**.

**Response 422 (erro):**
```json
{
  "message": "Não é possível excluir marca com modelos vinculados."
}
```

---

## 📱 Models (Modelos)

### Listar Modelos

```http
GET /api/v1/phone-catalog/models
```

**Query Parameters:**
| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `search` | string | Buscar por nome |
| `brand_id` | integer | Filtrar por marca |
| `form_factor` | string | Filtrar por tipo (`smartphone`, `tablet`, `watch`, `feature_phone`) |
| `release_year` | integer | Filtrar por ano de lançamento |
| `sort` | string | Campo para ordenação (default: `marketing_name`) |
| `direction` | string | `asc` ou `desc` (default: `asc`) |
| `per_page` | integer | Itens por página, máx 100 (default: 50) |

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "marketing_name": "iPhone 15 Pro",
      "release_year": 2023,
      "form_factor": "smartphone",
      "form_factor_label": "Smartphone",
      "full_name": "Apple iPhone 15 Pro",
      "brand": {
        "id": 1,
        "brand_name": "Apple",
        "brand_slug": "apple"
      },
      "created_at": "2025-01-15T10:00:00+00:00",
      "updated_at": "2025-01-15T10:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 100
  }
}
```

### Criar Modelo

```http
POST /api/v1/phone-catalog/models
```

**Request Body:**
```json
{
  "brand_id": 2,
  "marketing_name": "Galaxy S24 Ultra",
  "release_year": 2024,
  "form_factor": "smartphone"
}
```

| Campo | Tipo | Obrigatório | Valores aceitos |
|-------|------|-------------|-----------------|
| `brand_id` | integer | ✅ | ID de marca existente |
| `marketing_name` | string | ✅ | Nome comercial |
| `release_year` | integer | ❌ | 1990-2100 |
| `form_factor` | string | ❌ | `smartphone`, `tablet`, `watch`, `feature_phone` |

### Excluir Modelo

```http
DELETE /api/v1/phone-catalog/models/{id}
```

> ⚠️ **Regra de negócio:** Modelos com dispositivos de clientes vinculados **não podem ser excluídos**.

---

## 🔧 TypeScript Types

```typescript
// Types
export interface PhoneBrand {
  id: number;
  brand_name: string;
  brand_slug: string;
  parent_company: string | null;
  models_count?: number;
  created_at: string;
  updated_at: string;
}

export interface PhoneModel {
  id: number;
  marketing_name: string;
  release_year: number | null;
  form_factor: 'smartphone' | 'tablet' | 'watch' | 'feature_phone' | null;
  form_factor_label: string | null;
  full_name: string;
  brand?: {
    id: number;
    brand_name: string;
    brand_slug: string;
  };
  brand_id?: number;
  created_at: string;
  updated_at: string;
}

// Request Types
export interface CreatePhoneBrandRequest {
  brand_name: string;
  brand_slug?: string;
  parent_company?: string;
}

export interface UpdatePhoneBrandRequest {
  brand_name?: string;
  brand_slug?: string;
  parent_company?: string;
}

export interface CreatePhoneModelRequest {
  brand_id: number;
  marketing_name: string;
  release_year?: number;
  form_factor?: 'smartphone' | 'tablet' | 'watch' | 'feature_phone';
}

export interface UpdatePhoneModelRequest {
  brand_id?: number;
  marketing_name?: string;
  release_year?: number;
  form_factor?: 'smartphone' | 'tablet' | 'watch' | 'feature_phone';
}

// Query Params
export interface PhoneBrandQueryParams {
  search?: string;
  slug?: string;
  sort?: 'brand_name' | 'created_at';
  direction?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface PhoneModelQueryParams {
  search?: string;
  brand_id?: number;
  form_factor?: 'smartphone' | 'tablet' | 'watch' | 'feature_phone';
  release_year?: number;
  sort?: 'marketing_name' | 'release_year' | 'created_at';
  direction?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}
```

---

## 🚀 Sugestões de Consumo (React Query)

### API Service

```typescript
// src/services/phone-catalog.service.ts
import api from '@/lib/axios';
import type { 
  PhoneBrand, 
  PhoneModel, 
  CreatePhoneBrandRequest,
  PhoneBrandQueryParams,
  PhoneModelQueryParams
} from '@/types/phone-catalog.types';
import type { PaginatedResponse } from '@/types/api.types';

const BASE_URL = '/phone-catalog';

export const phoneCatalogService = {
  // Brands
  getBrands: (params?: PhoneBrandQueryParams) =>
    api.get<PaginatedResponse<PhoneBrand>>(`${BASE_URL}/brands`, { params }),

  getBrand: (id: number) =>
    api.get<{ data: PhoneBrand }>(`${BASE_URL}/brands/${id}`),

  createBrand: (data: CreatePhoneBrandRequest) =>
    api.post<{ message: string; data: PhoneBrand }>(`${BASE_URL}/brands`, data),

  updateBrand: (id: number, data: UpdatePhoneBrandRequest) =>
    api.patch<{ message: string; data: PhoneBrand }>(`${BASE_URL}/brands/${id}`, data),

  deleteBrand: (id: number) =>
    api.delete<{ message: string }>(`${BASE_URL}/brands/${id}`),

  // Models
  getModels: (params?: PhoneModelQueryParams) =>
    api.get<PaginatedResponse<PhoneModel>>(`${BASE_URL}/models`, { params }),

  getModel: (id: number) =>
    api.get<{ data: PhoneModel }>(`${BASE_URL}/models/${id}`),

  createModel: (data: CreatePhoneModelRequest) =>
    api.post<{ message: string; data: PhoneModel }>(`${BASE_URL}/models`, data),

  updateModel: (id: number, data: UpdatePhoneModelRequest) =>
    api.patch<{ message: string; data: PhoneModel }>(`${BASE_URL}/models/${id}`, data),

  deleteModel: (id: number) =>
    api.delete<{ message: string }>(`${BASE_URL}/models/${id}`),
};
```

### React Query Hooks

```typescript
// src/hooks/use-phone-catalog.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { phoneCatalogService } from '@/services/phone-catalog.service';
import { toast } from 'sonner';

// Query Keys
export const phoneCatalogKeys = {
  brands: ['phone-catalog', 'brands'] as const,
  brandsList: (params?: PhoneBrandQueryParams) => 
    [...phoneCatalogKeys.brands, 'list', params] as const,
  brand: (id: number) => [...phoneCatalogKeys.brands, id] as const,
  
  models: ['phone-catalog', 'models'] as const,
  modelsList: (params?: PhoneModelQueryParams) => 
    [...phoneCatalogKeys.models, 'list', params] as const,
  model: (id: number) => [...phoneCatalogKeys.models, id] as const,
};

// Hooks - Brands
export function usePhoneBrands(params?: PhoneBrandQueryParams) {
  return useQuery({
    queryKey: phoneCatalogKeys.brandsList(params),
    queryFn: () => phoneCatalogService.getBrands(params),
  });
}

export function usePhoneBrand(id: number) {
  return useQuery({
    queryKey: phoneCatalogKeys.brand(id),
    queryFn: () => phoneCatalogService.getBrand(id),
    enabled: !!id,
  });
}

export function useCreateBrand() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: phoneCatalogService.createBrand,
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: phoneCatalogKeys.brands });
      toast.success(response.data.message);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao criar marca');
    },
  });
}

export function useDeleteBrand() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: phoneCatalogService.deleteBrand,
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: phoneCatalogKeys.brands });
      toast.success(response.data.message);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao excluir marca');
    },
  });
}

// Hooks - Models
export function usePhoneModels(params?: PhoneModelQueryParams) {
  return useQuery({
    queryKey: phoneCatalogKeys.modelsList(params),
    queryFn: () => phoneCatalogService.getModels(params),
  });
}

export function useCreateModel() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: phoneCatalogService.createModel,
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: phoneCatalogKeys.models });
      toast.success(response.data.message);
    },
  });
}
```

### Exemplo de Uso em Componente

```tsx
// Exemplo: Select de modelos filtrado por marca
function PhoneModelSelect({ brandId, onChange }) {
  const { data, isLoading } = usePhoneModels({ 
    brand_id: brandId,
    per_page: 100 
  });

  if (isLoading) return <Skeleton />;

  return (
    <Select onValueChange={onChange}>
      <SelectTrigger>
        <SelectValue placeholder="Selecione o modelo" />
      </SelectTrigger>
      <SelectContent>
        {data?.data.data.map((model) => (
          <SelectItem key={model.id} value={String(model.id)}>
            {model.full_name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
```

---

## ⚠️ Checklist de Migração

- [ ] Atualizar `BASE_URL` de `/phone-brands` para `/phone-catalog/brands`
- [ ] Atualizar `BASE_URL` de `/phone-models` para `/phone-catalog/models`
- [ ] Atualizar query keys para o novo padrão
- [ ] Verificar permissões no frontend (se aplicável)
- [ ] Testar listagem, criação, edição e exclusão
