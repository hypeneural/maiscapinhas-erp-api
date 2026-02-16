# 🔌 Guia de Integração Frontend — Conexão ERP Hiper Gestão

**Data**: 2026-02-16  
**Módulo**: Hiper ERP Connection  
**Acesso**: Somente **Super Admin** (middleware `super-admin`)  
**Base URL**: `/api/v1/hiper`

---

## 📖 Conceito

O módulo permite conectar nosso sistema ao ERP **Hiper Gestão Online** (web) fazendo requests HTTP através do backend (proxy). No Hiper Gestão, a autenticação funciona por **cookies de sessão** (não por API key/token), então o fluxo é:

1. O admin faz login no Hiper no navegador
2. Copia os cookies da aba DevTools (TSV)
3. Cola no nosso sistema (textarea)
4. O backend armazena os cookies criptografados
5. A partir daí, o backend pode executar qualquer request catalogado no ERP

> ⚠️ **Os cookies expiram periodicamente** — o admin precisará refazer o passo 2-3 quando parem de funcionar.

---

## 🗂️ Endpoints da API

### Connections

| Método | Rota | Descrição |
|---|---|---|
| GET | `/hiper/connections` | Listar todas as conexões |
| GET | `/hiper/connections/{id}` | Ver conexão + resumo de cookies |
| POST | `/hiper/connections/upsert` | Criar ou atualizar conexão |
| POST | `/hiper/connections/{id}/import-tsv` | Importar cookies (TSV DevTools) |
| GET | `/hiper/connections/{id}/curl?url=...` | Gerar cookie + cURL |

### Endpoints (Catálogo)

| Método | Rota | Descrição |
|---|---|---|
| GET | `/hiper/endpoints` | Listar todos os endpoints cadastrados |
| GET | `/hiper/endpoints/{id}` | Ver detalhes de um endpoint |
| POST | `/hiper/endpoints/upsert` | Criar ou atualizar endpoint |
| DELETE | `/hiper/endpoints/{id}` | Deletar endpoint |

### Execute (Playground)

| Método | Rota | Descrição |
|---|---|---|
| POST | `/hiper/execute` | Executar request no ERP |

---

## 📡 Detalhes de cada endpoint

### GET /hiper/connections

Lista todas as conexões (sem cookies — por segurança).

**Response:**
```json
{
  "ok": true,
  "connections": [
    {
      "id": 1,
      "name": "Hiper - Maiscapinhas",
      "base_url": "https://maiscapinhas.hiper.com.br",
      "default_referer": "https://maiscapinhas.hiper.com.br/operacoes",
      "is_active": true,
      "last_used_at": "2026-02-16T14:30:00.000000Z",
      "created_at": "...",
      "updated_at": "..."
    }
  ]
}
```

---

### GET /hiper/connections/{id}

Retorna a conexão + resumo dos cookies (quantos domínios, quantos cookies, última importação).

**Response:**
```json
{
  "ok": true,
  "connection": {
    "id": 1,
    "name": "Hiper - Maiscapinhas",
    "base_url": "https://maiscapinhas.hiper.com.br",
    "default_referer": "https://maiscapinhas.hiper.com.br/operacoes",
    "default_headers": { "Accept": "...", "User-Agent": "..." },
    "is_active": true,
    "last_used_at": "2026-02-16T14:30:00.000000Z"
  },
  "cookie_summary": {
    "domains": ["maiscapinhas.hiper.com.br", ".hiper.com.br", "app.hiper.com.br"],
    "total_cookies": 15,
    "last_imported_at": "2026-02-16T14:05:00-03:00"
  }
}
```

> Se nunca importou cookies, `cookie_summary` será `null`.

---

### POST /hiper/connections/upsert

**Request (criar):**
```json
{
  "name": "Hiper - Maiscapinhas",
  "base_url": "https://maiscapinhas.hiper.com.br",
  "default_referer": "https://maiscapinhas.hiper.com.br/operacoes"
}
```

**Request (atualizar):**
```json
{
  "id": 1,
  "name": "Hiper - Maiscapinhas (Prod)",
  "base_url": "https://maiscapinhas.hiper.com.br",
  "default_referer": "https://maiscapinhas.hiper.com.br/operacoes"
}
```

Se `default_headers` for omitido, o backend usa headers padrão (Accept, User-Agent, etc).

---

### POST /hiper/connections/{id}/import-tsv

**Request:**
```json
{
  "tsv": "dominio_hiper\tmaiscapinhas\tmaiscapinhas.hiper.com.br\t/\t...\n..."
}
```

> O campo `tsv` é a string crua colada da aba `Application > Cookies` do DevTools.

**Response (200):**
```json
{
  "ok": true,
  "imported": 12,
  "total_cookies": 15,
  "domains": ["maiscapinhas.hiper.com.br", ".hiper.com.br", "app.hiper.com.br"],
  "last_imported_at": "2026-02-16T14:05:00-03:00"
}
```

**Response (422):**
```json
{ "ok": false, "error": "Não foi possível ler nenhum cookie do TSV fornecido." }
```

---

### GET /hiper/endpoints

**Response:**
```json
{
  "ok": true,
  "endpoints": [
    {
      "id": 1,
      "key": "operacoes.detalhes",
      "method": "GET",
      "path": "/operacoes/{id}/detalhes",
      "headers": null,
      "query_template": null,
      "body_template": null
    },
    {
      "id": 2,
      "key": "operacoes.listar",
      "method": "POST",
      "path": "/operacoes/ListarOperacoes",
      "headers": { "Content-Type": "application/json" },
      "query_template": null,
      "body_template": {
        "filtro": {
          "ApenasOperacoesMaquininhaStone": false,
          "CodigoPedidoVenda": null,
          "Lojas": []
        }
      }
    }
  ]
}
```

---

### POST /hiper/endpoints/upsert

**Request (criar):**
```json
{
  "key": "entidades.detalhes",
  "method": "GET",
  "path": "/entidades/{id}",
  "headers": null,
  "query_template": null,
  "body_template": null
}
```

**Request (atualizar):**
```json
{
  "id": 3,
  "key": "entidades.detalhes",
  "method": "GET",
  "path": "/entidades/{id}/detalhes",
  "headers": null,
  "query_template": null,
  "body_template": null
}
```

---

### DELETE /hiper/endpoints/{id}

**Response:**
```json
{ "ok": true, "deleted": "entidades.detalhes" }
```

---

### POST /hiper/execute — O Playground

Este é o endpoint central do playground. Ele combina uma **conexão** + um **endpoint** e faz o request real no ERP.

**Request:**
```json
{
  "connection_id": 1,
  "endpoint_key": "operacoes.detalhes",
  "params": { "id": "1c442bb6-4589-4d34-bf8e-bad62cb2b337" },
  "query": {},
  "body": {}
}
```

| Campo | Tipo | Quando usar |
|---|---|---|
| `connection_id` | `number` | Sempre — qual conexão usar |
| `endpoint_key` | `string` | Sempre — qual endpoint executar |
| `params` | `object?` | Quando o path tem `{placeholders}`. Ex: `{id}` |
| `query` | `object?` | Para GET ou POST com querystring. Faz merge com `query_template` |
| `body` | `object?` | Só para POST. Faz merge com `body_template` do endpoint |

**Response (sucesso):**
```json
{
  "ok": true,
  "status": 200,
  "url": "https://maiscapinhas.hiper.com.br/operacoes/1c442bb6-.../detalhes",
  "missing_cookies": [],
  "response": { "...dados do ERP..." }
}
```

**Response (erro de conexão):**
```json
{
  "ok": false,
  "error": "Connection timed out",
  "url": "https://...",
  "missing_cookies": ["TempDataProvider"]
}
```

---

## 🖥️ Sugestão de UI — Página Playground ERP Hiper

### Layout sugerido: 3 abas

```
┌─────────────────────────────────────────────────────┐
│  [ 🔌 Conexões ] [ 📋 Endpoints ] [ 🧪 Playground ] │
└─────────────────────────────────────────────────────┘
```

---

### Aba 1: 🔌 Conexões

Formulário de criação/edição + importação de cookies lado a lado.

```
┌──────────────────────────────────────────────────────────────┐
│  📌 Conexão Ativa: [dropdown: Hiper - Maiscapinhas ▾]       │
│                                                              │
│ ┌─ Configuração ─────────────┐ ┌─ Cookies ────────────────┐ │
│ │ Nome: [___________________]│ │ Status: ✅ 15 cookies     │ │
│ │ URL Base: [_______________]│ │ Domínios: 3              │ │
│ │ Referer: [________________]│ │ Última imp: 16/02 14:05  │ │
│ │                            │ │                          │ │
│ │ [💾 Salvar Conexão]        │ │ ┌─ Cole o TSV aqui ────┐ │ │
│ │                            │ │ │  <textarea>           │ │ │
│ └────────────────────────────┘ │ └───────────────────────┘ │ │
│                                │ [🔄 Importar Cookies]     │ │
│                                └──────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

**Fluxo:**
1. `GET /hiper/connections` → popula dropdown
2. Ao selecionar → `GET /hiper/connections/{id}` → preenche formulário + badge de cookies
3. `Salvar` → `POST /hiper/connections/upsert`
4. `Importar Cookies` → `POST /hiper/connections/{id}/import-tsv`

---

### Aba 2: 📋 Endpoints (Catálogo)

Tabela editável com os endpoints cadastrados + formulário para adicionar.

```
┌──────────────────────────────────────────────────────────────┐
│  📋 Endpoints Cadastrados                                    │
│                                                              │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ Key                        │ Método │ Path            │   │
│  │─────────────────────────────────────────────────────│   │
│  │ operacoes.detalhes         │  GET   │ /operacoes/{id} │   │
│  │ operacoes.listar           │  POST  │ /operacoes/List │   │
│  │ usuarios.perfis            │  GET   │ /usuarios/perfis│   │
│  │ entidades.listagem.func... │  GET   │ /entidades/list │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ Novo/Editar Endpoint ────────────────────────────────┐   │
│  │ Key: [operacoes.novo____]  Método: [GET ▾]            │   │
│  │ Path: [/operacoes/{id}/items_______________________]  │   │
│  │                                                       │   │
│  │ ▸ Headers (JSON)         → textarea colapsável        │   │
│  │ ▸ Query Template (JSON)  → textarea colapsável        │   │
│  │ ▸ Body Template (JSON)   → textarea colapsável        │   │
│  │                                                       │   │
│  │ [💾 Salvar]  [🗑️ Deletar]                             │   │
│  └───────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
```

**Fluxo:**
1. `GET /hiper/endpoints` → popula tabela
2. Clicar numa linha → preenche formulário de edição
3. `Salvar` → `POST /hiper/endpoints/upsert`
4. `Deletar` → `DELETE /hiper/endpoints/{id}`

---

### Aba 3: 🧪 Playground (o principal!)

Onde o admin monta e executa requests no ERP.

```
┌──────────────────────────────────────────────────────────────┐
│  🧪 Playground ERP                                           │
│                                                              │
│  Conexão: [Hiper - Maiscapinhas ▾]                           │
│  Endpoint: [operacoes.detalhes ▾]                            │
│                                                              │
│  ── Detalhes do Endpoint ──                                  │
│  Método: GET    Path: /operacoes/{id}/detalhes               │
│  URL Final: https://maiscapinhas.hiper.com.br/operacoes/     │
│             1c442bb6-.../detalhes                             │
│                                                              │
│  ┌─ Params (substitui {placeholders} no path) ────────────┐ │
│  │ { "id": "1c442bb6-4589-4d34-bf8e-bad62cb2b337" }       │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                              │
│  ┌─ Query (merge com query_template) ─────────────────────┐ │
│  │ {}                                                      │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                              │
│  ┌─ Body (merge com body_template — só POST) ─────────────┐ │
│  │ { "filtro": { "Lojas": ["abc-123"] } }                  │ │
│  │              (desabilitado se GET)                       │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                              │
│  [▶ Executar]   [📋 Copiar cURL]                             │
│                                                              │
│  ── Resultado ──                                             │
│  Status: 200 ✅  |  URL: https://maiscapinhas.hiper...       │
│  Cookies faltando: (nenhum)                                  │
│                                                              │
│  ┌─ Response JSON ────────────────────────────────────────┐ │
│  │ {                                                      │ │
│  │   "Id": "1c442bb6-...",                                │ │
│  │   "Numero": 12345,                                     │ │
│  │   "Status": "Finalizada",                              │ │
│  │   ...                                                  │ │
│  │ }                                                      │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

**Fluxo do Playground:**

1. `GET /hiper/connections` → popula dropdown "Conexão"
2. `GET /hiper/endpoints` → popula dropdown "Endpoint"
3. Ao selecionar um endpoint:
   - Mostra método, path, URL preview
   - Se `path` contém `{...}`, mostra textarea de **Params** pré-populado com `{}`
   - Se tem `query_template`, mostra textarea **Query** pré-populado com o template
   - Se método = `POST` e tem `body_template`, mostra textarea **Body** pré-populado com o template
   - Se método = `GET`, **desabilitar** textarea Body
4. **Executar** → `POST /hiper/execute` com os dados dos textareas
5. **Copiar cURL** → `GET /hiper/connections/{id}/curl?url=...`
6. Mostra resposta em textarea readonly com JSON pretty-printed

**Dicas de implementação:**
- Parse os `{placeholders}` do path com regex `/\{(\w+)\}/g` para gerar campos de input ou pré-popular o JSON de params
- Pré-popular os textareas Query/Body com `JSON.stringify(template, null, 2)` quando o endpoint tem templates
- Mostrar o badge de status colorido: `200-299` = verde, `4xx` = amarelo, `5xx` = vermelho
- Se `missing_cookies` não estiver vazio, mostrar alert vermelho

---

## 📊 TypeScript Schemas

```typescript
// ============ CONEXÃO ============
interface HiperConnection {
  id: number;
  name: string;
  base_url: string;
  default_referer: string | null;
  default_headers?: Record<string, string>;
  is_active: boolean;
  last_used_at: string | null;
  created_at: string;
  updated_at: string;
}

interface HiperConnectionDetail extends HiperConnection {
  cookie_summary: {
    domains: string[];
    total_cookies: number;
    last_imported_at: string | null;
  } | null;
}

// ============ ENDPOINT ============
interface HiperEndpoint {
  id: number;
  key: string;
  method: 'GET' | 'POST';
  path: string;
  headers: Record<string, string> | null;
  query_template: Record<string, any> | null;
  body_template: Record<string, any> | null;
  created_at: string;
  updated_at: string;
}

// ============ REQUESTS ============
interface UpsertConnectionRequest {
  id?: number | null;
  name: string;
  base_url: string;
  default_referer?: string;
  default_headers?: Record<string, string>;
}

interface ImportTsvRequest {
  tsv: string;
}

interface UpsertEndpointRequest {
  id?: number | null;
  key: string;
  method: 'GET' | 'POST';
  path: string;
  headers?: Record<string, string> | null;
  query_template?: Record<string, any> | null;
  body_template?: Record<string, any> | null;
}

interface ExecuteRequest {
  connection_id: number;
  endpoint_key: string;
  params?: Record<string, string>;
  query?: Record<string, any>;
  body?: Record<string, any>;
}

// ============ RESPONSES ============
interface ConnectionsListResponse {
  ok: boolean;
  connections: HiperConnection[];
}

interface ConnectionShowResponse {
  ok: boolean;
  connection: HiperConnection;
  cookie_summary: HiperConnectionDetail['cookie_summary'];
}

interface UpsertConnectionResponse {
  ok: boolean;
  connection: HiperConnection;
}

interface ImportTsvResponse {
  ok: boolean;
  imported: number;
  total_cookies: number;
  domains: string[];
  last_imported_at: string;
}

interface EndpointsListResponse {
  ok: boolean;
  endpoints: HiperEndpoint[];
}

interface UpsertEndpointResponse {
  ok: boolean;
  endpoint: HiperEndpoint;
}

interface CurlResponse {
  ok: boolean;
  cookie: string;
  missing: string[];
  curl: string;
}

interface ExecuteResponse {
  ok: boolean;
  status: number;
  url: string;
  missing_cookies: string[];
  response: any;
}
```

---

## 🔧 Service Completo

```typescript
// src/services/hiperErpService.ts
import api from '@/lib/api';

const BASE = '/hiper';

export const hiperErpService = {
  // ── Connections ──

  async listConnections() {
    const { data } = await api.get<ConnectionsListResponse>(`${BASE}/connections`);
    return data;
  },

  async showConnection(id: number) {
    const { data } = await api.get<ConnectionShowResponse>(`${BASE}/connections/${id}`);
    return data;
  },

  async upsertConnection(req: UpsertConnectionRequest) {
    const { data } = await api.post<UpsertConnectionResponse>(`${BASE}/connections/upsert`, req);
    return data;
  },

  async importTsv(connectionId: number, tsv: string) {
    const { data } = await api.post<ImportTsvResponse>(
      `${BASE}/connections/${connectionId}/import-tsv`,
      { tsv }
    );
    return data;
  },

  async getCurl(connectionId: number, url: string) {
    const { data } = await api.get<CurlResponse>(
      `${BASE}/connections/${connectionId}/curl`,
      { params: { url } }
    );
    return data;
  },

  // ── Endpoints ──

  async listEndpoints() {
    const { data } = await api.get<EndpointsListResponse>(`${BASE}/endpoints`);
    return data;
  },

  async showEndpoint(id: number) {
    const { data } = await api.get<{ ok: boolean; endpoint: HiperEndpoint }>(
      `${BASE}/endpoints/${id}`
    );
    return data;
  },

  async upsertEndpoint(req: UpsertEndpointRequest) {
    const { data } = await api.post<UpsertEndpointResponse>(`${BASE}/endpoints/upsert`, req);
    return data;
  },

  async deleteEndpoint(id: number) {
    const { data } = await api.delete<{ ok: boolean; deleted: string }>(
      `${BASE}/endpoints/${id}`
    );
    return data;
  },

  // ── Execute (Playground) ──

  async execute(req: ExecuteRequest) {
    const { data } = await api.post<ExecuteResponse>(`${BASE}/execute`, req);
    return data;
  },
};
```

---

## 📝 Como Copiar os Cookies do DevTools

Instruções para incluir como **tooltip ou ajuda** na UI:

1. Abra o Hiper Gestão no Chrome: `https://maiscapinhas.hiper.com.br`
2. Faça login normalmente
3. Abra o DevTools (`F12`)
4. Vá em **Application** → **Cookies** → `maiscapinhas.hiper.com.br`
5. Clique em qualquer cookie → **Ctrl+A** (seleciona todos) → **Ctrl+C**
6. Cole no textarea do nosso sistema
7. Repita para `app.hiper.com.br` e `.hiper.com.br` se existirem (cole tudo junto)

> ⚠️ Os cookies do Hiper expiram. Se os requests começarem a dar erro 401/403, reimporte os cookies.

---

## ⚠️ Pontos de Atenção

1. **Super Admin only** — todos os endpoints requerem `super-admin` middleware
2. **Cookies expiram** — a página deve facilitar reimportação frequente
3. **`missing_cookies`** — sempre exibir para o admin quando houver cookies faltando
4. **Cookies são criptografados** — o backend cuida disso, o frontend não precisa se preocupar
5. **TSV aceita múltiplos domínios** — o parser do backend separa automaticamente
6. **`body` textarea desabilitada para GET** — só endpoints POST usam body
7. **Templates fazem merge** — o runtime `query`/`body` faz merge com o template salvo, não substitui
