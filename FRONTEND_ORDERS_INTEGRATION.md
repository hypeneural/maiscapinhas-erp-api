# Frontend Integration Guide: Customers, Pedidos & Capas Personalizadas

Guia completo para integração do frontend com as APIs de clientes, catálogo de aparelhos, pedidos e capas personalizadas.

---

## Princípios de Consumo

### Autenticação
Todas as requisições requerem **Bearer Token** (Sanctum):
```typescript
const headers = {
  'Authorization': `Bearer ${token}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json',
};
```

### Base URL
```
/api/v1
```

### Controle de Acesso
| Role | Clientes | Pedidos/Capas | Catálogo |
|------|----------|---------------|----------|
| **Super Admin** | Full | Full | Full |
| **Admin** | Full | Full | Full |
| **Vendedor** | Próprios + vinculados | Apenas próprios | Leitura |

> [!IMPORTANT]
> Vendedores só veem registros onde `user_id` = usuário logado ou clientes que criaram.

---

## Modelos Básicos (TypeScript)

### Customer
```typescript
interface Customer {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  zip_code: string | null;
  street: string | null;
  number: string | null;
  complement: string | null;
  neighborhood: string | null;
  city: string | null;
  state: string | null; // 2 chars: 'SC', 'SP', etc
  birth_date: string | null; // 'YYYY-MM-DD'
  devices?: CustomerDevice[];
  created_by?: { id: number; name: string };
  created_at: string;
  updated_at: string;
}
```

### PhoneBrand & PhoneModel
```typescript
interface PhoneBrand {
  id: number;
  brand_name: string;
  brand_slug: string;
  parent_company: string | null;
  models_count?: number;
}

interface PhoneModel {
  id: number;
  marketing_name: string;
  release_year: number | null;
  form_factor: 'smartphone' | 'tablet' | 'watch' | 'feature_phone';
  form_factor_label: string;
  full_name: string; // "Samsung Galaxy S24"
  brand?: { id: number; brand_name: string; brand_slug: string };
  brand_id: number;
}
```

### CustomerDevice
```typescript
interface CustomerDevice {
  id: number;
  customer_id: number;
  nickname: string | null;
  is_primary: boolean;
  display_name: string;
  phone_model?: {
    id: number;
    marketing_name: string;
    release_year: number | null;
    form_factor: string;
    full_name: string;
    brand?: { id: number; brand_name: string };
  };
}
```

### Pedido
```typescript
interface Pedido {
  id: number;
  selected_product: string;
  obs: string | null;
  status: PedidoStatus;
  status_label: string;
  status_color: string;
  store?: { id: number; name: string; city: string };
  store_id: number;
  user?: { id: number; name: string };
  user_id: number;
  customer?: { id: number; name: string; email: string; phone: string };
  customer_id: number;
  customer_device?: CustomerDevice;
  customer_device_id: number | null;
  status_history?: PedidoStatusHistory[];
  created_at: string;
  updated_at: string;
}

type PedidoStatus = 1 | 2 | 3 | 4 | 5;
// 1 = Solicitado
// 2 = Produto Indisponível
// 3 = Disponível na Loja
// 4 = Venda Realizada
// 5 = Cancelado

interface PedidoStatusHistory {
  id: number;
  old_status: PedidoStatus | null;
  old_status_label: string | null;
  new_status: PedidoStatus;
  new_status_label: string;
  changed_by: { id: number; name: string };
  changed_at: string;
  source: 'api' | 'bulk' | 'system' | null;
  reason: string | null;
}
```

### CapaPersonalizada
```typescript
interface CapaPersonalizada {
  id: number;
  selected_product: string;
  product_reference: string | null;
  obs: string | null;
  photo_path: string | null;
  photo_url: string | null;
  qty: number;
  price: number | null;
  total: number | null;
  payed: boolean;
  payday: string | null; // 'YYYY-MM-DD'
  received_by?: { id: number; name: string };
  received_by_id: number | null;
  sended_to_production_at: string | null; // 'YYYY-MM-DD'
  status: CapaStatus;
  status_label: string;
  status_color: string;
  store?: { id: number; name: string; city: string };
  user?: { id: number; name: string };
  customer?: { id: number; name: string; email: string; phone: string };
  customer_device?: CustomerDevice;
  created_at: string;
  updated_at: string;
}

type CapaStatus = 1 | 2 | 3 | 4 | 5 | 6;
// 1 = Encomenda Solicitada
// 2 = Produto Indisponível
// 3 = Disponível na Loja
// 4 = Venda Realizada
// 5 = Cancelada
// 6 = Enviado para Produção
```

### Constantes de Status (usar no frontend)
```typescript
export const PEDIDO_STATUS = {
  SOLICITADO: 1,
  PRODUTO_INDISPONIVEL: 2,
  DISPONIVEL_LOJA: 3,
  VENDA_REALIZADA: 4,
  CANCELADO: 5,
} as const;

export const PEDIDO_STATUS_LABELS: Record<number, string> = {
  1: 'Solicitado',
  2: 'Produto Indisponível',
  3: 'Disponível na Loja',
  4: 'Venda Realizada',
  5: 'Cancelado',
};

export const PEDIDO_STATUS_COLORS: Record<number, string> = {
  1: 'blue',
  2: 'red',
  3: 'yellow',
  4: 'green',
  5: 'gray',
};

export const CAPA_STATUS = {
  ENCOMENDA_SOLICITADA: 1,
  PRODUTO_INDISPONIVEL: 2,
  DISPONIVEL_LOJA: 3,
  VENDA_REALIZADA: 4,
  CANCELADA: 5,
  ENVIADO_PRODUCAO: 6,
} as const;

export const CAPA_STATUS_LABELS: Record<number, string> = {
  1: 'Encomenda Solicitada',
  2: 'Produto Indisponível',
  3: 'Disponível na Loja',
  4: 'Venda Realizada',
  5: 'Cancelada',
  6: 'Enviado para Produção',
};

export const CAPA_STATUS_COLORS: Record<number, string> = {
  1: 'blue',
  2: 'red',
  3: 'yellow',
  4: 'green',
  5: 'gray',
  6: 'purple',
};
```

---

## Endpoints - Customers

### Listar Clientes
```http
GET /api/v1/customers
```

**Query Params (filtros):**
| Param | Tipo | Descrição |
|-------|------|-----------|
| `name` | string | Busca por nome (like) |
| `email` | string | Busca por email (like) |
| `phone` | string | Busca por telefone (like) |
| `city` | string | Filtro por cidade |
| `state` | string | Filtro por estado (2 chars) |
| `has_device` | 0\|1 | Clientes com/sem aparelhos |
| `brand_id` | number | Filtro por marca do aparelho |
| `model_id` | number | Filtro por modelo do aparelho |
| `page` | number | Página (default: 1) |
| `per_page` | number | Itens por página (max: 100) |
| `sort` | string | Campo para ordenação |
| `direction` | asc\|desc | Direção da ordenação |

**Response:**
```json
{
  "data": [Customer, ...],
  "links": { "first", "last", "prev", "next" },
  "meta": { "current_page", "last_page", "per_page", "total" }
}
```

### Criar Cliente
```http
POST /api/v1/customers
```

**Body:**
```json
{
  "name": "João Silva",           // required
  "email": "joao@email.com",      // required, unique
  "phone": "47999999999",         // optional
  "zip_code": "88220000",         // optional
  "street": "Rua das Flores",     // optional
  "number": "123",                // optional
  "complement": "Apto 101",       // optional
  "neighborhood": "Centro",       // optional
  "city": "Itapema",              // optional
  "state": "SC",                  // optional, 2 chars
  "birth_date": "1990-05-15"      // optional, YYYY-MM-DD
}
```

### Ver Cliente
```http
GET /api/v1/customers/{id}
```

### Atualizar Cliente
```http
PATCH /api/v1/customers/{id}
```
Body: campos a atualizar (partial update)

### Excluir Cliente
```http
DELETE /api/v1/customers/{id}
```

---

## Endpoints - Devices do Cliente

### Listar Aparelhos
```http
GET /api/v1/customers/{id}/devices
```

### Vincular Aparelho
```http
POST /api/v1/customers/{id}/devices
```

**Body:**
```json
{
  "phone_model_id": 123,    // required, ID do modelo
  "nickname": "Cel Principal", // optional
  "is_primary": true           // optional, default false
}
```

### Atualizar Aparelho
```http
PATCH /api/v1/customers/{id}/devices/{device_id}
```

### Remover Aparelho
```http
DELETE /api/v1/customers/{id}/devices/{device_id}
```

---

## Endpoints - Catálogo de Aparelhos

### Marcas
```http
GET /api/v1/phone-brands
GET /api/v1/phone-brands/{id}
POST /api/v1/phone-brands        # Admin only
PATCH /api/v1/phone-brands/{id}  # Admin only
DELETE /api/v1/phone-brands/{id} # Admin only
```

**Filtros GET:**
- `search` - Busca por nome/slug
- `slug` - Filtro exato por slug

### Modelos
```http
GET /api/v1/phone-models
GET /api/v1/phone-models/{id}
POST /api/v1/phone-models        # Admin only
PATCH /api/v1/phone-models/{id}  # Admin only
DELETE /api/v1/phone-models/{id} # Admin only
```

**Filtros GET:**
- `search` - Busca por marketing_name
- `brand_id` - Filtro por marca
- `form_factor` - smartphone|tablet|watch|feature_phone
- `release_year` - Ano de lançamento

> [!TIP]
> **Cache recomendado**: Brands e Models mudam pouco. Cache por 5-10 minutos.

---

## Endpoints - Pedidos

### Listar Pedidos
```http
GET /api/v1/pedidos
```

**Query Params:**
| Param | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | number | Filtro por loja (admin) |
| `user_id` | number | Filtro por vendedor (admin) |
| `status` | number | Status: 1-5 |
| `customer_id` | number | Filtro por cliente |
| `initial_date` | YYYY-MM-DD | Data inicial |
| `final_date` | YYYY-MM-DD | Data final |
| `brand_id` | number | Marca do aparelho |
| `model_id` | number | Modelo do aparelho |
| `keyword` | string | Busca em produto/obs/cliente |
| `page`, `per_page`, `sort`, `direction` | - | Paginação |

### Criar Pedido
```http
POST /api/v1/pedidos
```

**Body:**
```json
{
  "customer_id": 1,              // required
  "selected_product": "Capa iPhone 15", // required
  "store_id": 1,                 // optional (admin), auto-preenche
  "user_id": 5,                  // optional (admin), auto-preenche
  "customer_device_id": 10,      // optional
  "obs": "Cliente quer azul",    // optional
  "status": 1                    // optional, default 1
}
```

> [!NOTE]
> `customer_device_id` deve pertencer ao `customer_id` informado.

### Ver Pedido
```http
GET /api/v1/pedidos/{id}
```
Retorna com `status_history` para timeline.

### Atualizar Pedido
```http
PATCH /api/v1/pedidos/{id}
```

### Excluir Pedido
```http
DELETE /api/v1/pedidos/{id}
```

### Alterar Status (unitário)
```http
PATCH /api/v1/pedidos/{id}/status
```

**Body:**
```json
{
  "status": 3,
  "reason": "Produto chegou" // optional
}
```

### Alterar Status em Lote (Admin)
```http
POST /api/v1/pedidos/bulk-status
```

**Body:**
```json
{
  "ids": [1, 2, 3],
  "status": 3
}
```

**Response:**
```json
{
  "message": "Atualização em lote concluída. 3 atualizados, 0 ignorados.",
  "data": {
    "updated": 3,
    "skipped": 0,
    "errors": []
  }
}
```

---

## Endpoints - Capas Personalizadas

### Listar Capas
```http
GET /api/v1/capas-personalizadas
```

**Query Params (adicionais):**
| Param | Tipo | Descrição |
|-------|------|-----------|
| `payed` | 0\|1 | Filtro pago/não pago |
| `payday` | YYYY-MM-DD | Data de pagamento |
| `received_by_id` | number | Quem recebeu pagamento |
| + todos os filtros de pedidos | - | - |

### Criar Capa
```http
POST /api/v1/capas-personalizadas
```

**Body:**
```json
{
  "customer_id": 1,                // required
  "selected_product": "Capa Personalizada", // required
  "product_reference": "REF-001",  // optional
  "customer_device_id": 10,        // optional
  "obs": "Foto do cachorro",       // optional
  "qty": 2,                        // optional, default 1
  "price": 49.90,                  // optional
  "payed": false,                  // optional, default false
  "payday": "2026-01-15",          // required if payed=true
  "received_by_id": 5,             // required if payed=true
  "status": 1                      // optional, default 1
}
```

### Ver Capa
```http
GET /api/v1/capas-personalizadas/{id}
```

### Atualizar Capa
```http
PATCH /api/v1/capas-personalizadas/{id}
```

### Excluir Capa
```http
DELETE /api/v1/capas-personalizadas/{id}
```

### Alterar Status
```http
PATCH /api/v1/capas-personalizadas/{id}/status
```

**Body:**
```json
{ "status": 6 }
```

### Alterar Status em Lote (Admin)
```http
POST /api/v1/capas-personalizadas/bulk-status
```

### Enviar para Produção em Lote (Admin)
```http
POST /api/v1/capas-personalizadas/send-to-production
```

**Body:**
```json
{
  "ids": [1, 2, 3],
  "sended_to_production_at": "2026-01-15"
}
```

> [!IMPORTANT]
> Automaticamente atualiza status para 6 (Enviado para Produção).

### Registrar Pagamento
```http
PATCH /api/v1/capas-personalizadas/{id}/payment
```

**Body:**
```json
{
  "payed": true,
  "payday": "2026-01-15",     // required if payed=true
  "received_by_id": 5         // required if payed=true
}
```

### Upload de Foto
```http
POST /api/v1/capas-personalizadas/{id}/photo
Content-Type: multipart/form-data
```

**Form Data:**
- `file`: imagem (jpg, jpeg, png, gif), max 10MB

**Response:**
```json
{
  "message": "Foto enviada com sucesso.",
  "data": {
    "photo_path": "capas-personalizadas/abc123.jpg",
    "photo_url": "http://localhost/storage/capas-personalizadas/abc123.jpg",
    "size": 512000,
    "mime": "image/jpeg"
  }
}
```

### Remover Foto
```http
DELETE /api/v1/capas-personalizadas/{id}/photo
```

---

## Padrão de Erros e Feedback

### Códigos HTTP
| Código | Situação | Ação no Frontend |
|--------|----------|------------------|
| `200` | Sucesso | Processar dados |
| `201` | Criado | Redirecionar/atualizar lista |
| `204` | Excluído | Remover da lista |
| `400` | Bad Request | Toast erro genérico |
| `401` | Não autenticado | Redirecionar para login |
| `403` | Sem permissão | Toast "Sem permissão" |
| `404` | Não encontrado | Voltar para lista |
| `422` | Validação | Exibir erros por campo |
| `500` | Erro servidor | Toast "Erro interno" |

### Estrutura de Erro 422
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Este email já está cadastrado."],
    "customer_device_id": ["O dispositivo não pertence ao cliente selecionado."]
  }
}
```

**Tratamento sugerido:**
```typescript
if (error.response?.status === 422) {
  const errors = error.response.data.errors;
  Object.entries(errors).forEach(([field, messages]) => {
    form.setError(field, { message: messages[0] });
  });
}
```

---

## Sugestões de Telas e Features

### Telas Principais

| Tela | Descrição | Componentes Sugeridos |
|------|-----------|----------------------|
| **Lista de Clientes** | Tabela paginada com filtros | DataTable, SearchInput, FilterPanel |
| **Detalhe do Cliente** | Dados + Tabs (Aparelhos, Histórico) | Card, Tabs, DeviceList |
| **Lista de Pedidos** | Grid com status badges + ações | DataTable, StatusBadge, ActionMenu |
| **Detalhe do Pedido** | Timeline de status + info | StatusTimeline, Card, CustomerCard |
| **Lista de Capas** | Grid + indicadores financeiros | DataTable, PaymentBadge, PhotoThumbnail |
| **Detalhe da Capa** | Foto + financeiro + status | ImageViewer, PaymentForm, StatusSelect |
| **Catálogo (Admin)** | CRUD de Brands/Models | DataTable, Modal, Form |

### Componentes Recomendados

```tsx
// StatusBadge - Componente de status com cores
<StatusBadge status={pedido.status} label={pedido.status_label} color={pedido.status_color} />

// CustomerSelect - Seletor de cliente com busca
<CustomerSelect 
  value={customerId}
  onChange={setCustomerId}
  allowCreate // permite criar inline
/>

// DeviceSelect - Seletor de aparelho do cliente
<DeviceSelect
  customerId={customerId}
  value={deviceId}
  onChange={setDeviceId}
/>

// StatusTimeline - Timeline de histórico
<StatusTimeline history={pedido.status_history} />

// BulkActionBar - Barra de ações em lote
<BulkActionBar
  selectedIds={selectedIds}
  onStatusChange={handleBulkStatus}
  loading={isLoading}
/>
```

### Features Recomendadas

1. **Filtros persistidos na URL**
```typescript
// Usar query params para filtros
const [searchParams, setSearchParams] = useSearchParams();
const status = searchParams.get('status');
```

2. **Debounce para busca**
```typescript
const debouncedKeyword = useDebounce(keyword, 300);
```

3. **Cache de brands/models**
```typescript
const { data: brands } = useQuery({
  queryKey: ['phone-brands'],
  queryFn: fetchBrands,
  staleTime: 5 * 60 * 1000, // 5 minutos
});
```

4. **Criar cliente inline**
```typescript
// No modal de novo pedido/capa
<CustomerSelect
  allowCreate
  onCreateNew={async (name) => {
    const customer = await createCustomer({ name, email: '' });
    return customer.id;
  }}
/>
```

5. **Feedback visual para bulk actions**
```typescript
const handleBulkStatus = async (ids: number[], status: number) => {
  try {
    const { data } = await api.post('/pedidos/bulk-status', { ids, status });
    toast.success(`${data.data.updated} pedidos atualizados`);
    if (data.data.errors.length > 0) {
      toast.warning(`${data.data.errors.length} erros`);
    }
    refetch();
  } catch (error) {
    toast.error('Erro ao atualizar status');
  }
};
```

6. **Normalização de valores monetários**
```typescript
// Armazenar em centavos (backend já faz decimal)
// Frontend: formatar para exibição
const formatPrice = (price: number | null): string => {
  if (price === null) return '-';
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(price);
};
```

---

## Hooks Sugeridos

```typescript
// useCustomers.ts
export function useCustomers(filters: CustomerFilters) {
  return useQuery({
    queryKey: ['customers', filters],
    queryFn: () => fetchCustomers(filters),
  });
}

// usePedidos.ts
export function usePedidos(filters: PedidoFilters) {
  return useQuery({
    queryKey: ['pedidos', filters],
    queryFn: () => fetchPedidos(filters),
  });
}

// useCapasPersonalizadas.ts
export function useCapasPersonalizadas(filters: CapaFilters) {
  return useQuery({
    queryKey: ['capas-personalizadas', filters],
    queryFn: () => fetchCapas(filters),
  });
}

// useBulkStatusMutation.ts
export function useBulkStatusMutation(resource: 'pedidos' | 'capas-personalizadas') {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ ids, status }: { ids: number[]; status: number }) =>
      api.post(`/${resource}/bulk-status`, { ids, status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [resource] });
    },
  });
}
```

---

## Fluxos de Uso

### Criar Pedido (Vendedor)
1. Selecionar ou criar cliente
2. (Opcional) Selecionar aparelho do cliente
3. Preencher produto e observações
4. Confirmar → Status inicial: Solicitado

### Alterar Status em Lote (Admin)
1. Selecionar pedidos na lista (checkbox)
2. Clicar "Alterar Status"
3. Escolher novo status
4. Confirmar → Exibir resumo (sucesso/erros)

### Enviar Capas para Produção (Admin)
1. Selecionar capas na lista
2. Clicar "Enviar para Produção"
3. Informar data de envio
4. Confirmar → Status atualizado para 6

### Registrar Pagamento
1. Abrir capa personalizada
2. Clicar "Registrar Pagamento"
3. Informar data e quem recebeu
4. Confirmar → payed = true

---

## Validações Importantes

| Campo | Regra | Mensagem |
|-------|-------|----------|
| `customer.email` | Único | "Este email já está cadastrado" |
| `customer_device_id` | Deve pertencer ao customer_id | "Dispositivo não pertence ao cliente" |
| `payed=true` | Exige payday e received_by_id | "Data de pagamento obrigatória" |
| `qty` | Mínimo 1 | "Quantidade mínima é 1" |
| `price` | >= 0 | "Preço não pode ser negativo" |
| `state` | 2 caracteres | "Estado deve ter 2 caracteres" |
