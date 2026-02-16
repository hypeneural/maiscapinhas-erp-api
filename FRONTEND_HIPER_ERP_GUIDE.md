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

### 1. Criar/Atualizar Conexão

```
POST /api/v1/hiper/connections/upsert
```

**Request:**
```json
{
  "id": null,
  "name": "Hiper - Maiscapinhas",
  "base_url": "https://maiscapinhas.hiper.com.br",
  "default_referer": "https://maiscapinhas.hiper.com.br/operacoes",
  "default_headers": {
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7",
    "User-Agent": "Mozilla/5.0 ...",
    "X-Requested-With": "XMLHttpRequest"
  }
}
```

- Se `id` for `null`, **cria** nova conexão (resposta `201`)
- Se `id` existir, **atualiza** (resposta `200`)
- Se `default_headers` for omitido, o backend usa headers padrão automaticamente

**Response (201/200):**
```json
{
  "ok": true,
  "connection": {
    "id": 1,
    "name": "Hiper - Maiscapinhas",
    "base_url": "https://maiscapinhas.hiper.com.br",
    "default_referer": "https://maiscapinhas.hiper.com.br/operacoes",
    "default_headers": { ... },
    "cookies": { ... },
    "is_active": true,
    "last_used_at": null,
    "created_at": "2026-02-16T14:00:00.000000Z",
    "updated_at": "2026-02-16T14:00:00.000000Z"
  }
}
```

---

### 2. Importar Cookies (TSV do DevTools)

```
POST /api/v1/hiper/connections/{id}/import-tsv
```

**Request:**
```json
{
  "tsv": "dominio_hiper\tmaiscapinhas\tmaiscapinhas.hiper.com.br\t/\t...\nTempDataProvider\tabc123\tmaiscapinhas.hiper.com.br\t/\t...\n..."
}
```

> O campo `tsv` é a **string crua** que o usuário cola da aba `Application > Cookies` no DevTools (Ctrl+A → Ctrl+C).

**Response (200):**
```json
{
  "ok": true,
  "imported": 12,
  "total_cookies": 15,
  "domains": [
    "maiscapinhas.hiper.com.br",
    ".hiper.com.br",
    "app.hiper.com.br"
  ],
  "last_imported_at": "2026-02-16T14:05:00-03:00"
}
```

**Response (422) — TSV inválido:**
```json
{
  "ok": false,
  "error": "Não foi possível ler nenhum cookie do TSV fornecido."
}
```

---

### 3. Gerar Cookie + cURL

```
GET /api/v1/hiper/connections/{id}/curl?url=https://maiscapinhas.hiper.com.br/operacoes/123/detalhes
```

**Response (200):**
```json
{
  "ok": true,
  "cookie": "dominio_hiper=maiscapinhas; TempDataProvider=abc123; __RequestVerificationToken=xyz; .AspNet.ApplicationCookie=big_token; .AspNet.TwoFactorRememberBrowser=2fa_token",
  "missing": [],
  "curl": "curl --location 'https://...' \\\n  --header 'Accept: ...' \\\n  --header 'Cookie: ...'"
}
```

Se faltarem cookies essenciais, `missing` terá os nomes. Ex: `["TempDataProvider", ".AspNet.ApplicationCookie"]`

---

### 4. Executar Request no ERP

```
POST /api/v1/hiper/execute
```

**Request:**
```json
{
  "connection_id": 1,
  "endpoint_key": "operacoes.detalhes",
  "params": { "id": "abc-123-def" },
  "query": {},
  "body": {}
}
```

| Campo | Tipo | Descrição |
|---|---|---|
| `connection_id` | `number` | ID da conexão |
| `endpoint_key` | `string` | Chave do endpoint cadastrado (ver tabela abaixo) |
| `params` | `object?` | Substitui `{placeholders}` na URL. Ex: `{id}` |
| `query` | `object?` | Merge com `query_template` do endpoint (GET/POST) |
| `body` | `object?` | Merge com `body_template` do endpoint (POST only) |

**Response (200):**
```json
{
  "ok": true,
  "status": 200,
  "url": "https://maiscapinhas.hiper.com.br/operacoes/abc-123-def/detalhes",
  "missing_cookies": [],
  "response": { ... }
}
```

**Response (502) — Erro de conexão com o ERP:**
```json
{
  "ok": false,
  "error": "Connection timed out",
  "url": "https://...",
  "missing_cookies": ["TempDataProvider"]
}
```

---

## 📋 Endpoints Cadastrados (Catálogo)

| Key | Método | Path | Notas |
|---|---|---|---|
| `usuarios.perfis` | GET | `/usuarios/perfis` | Lista perfis de usuários |
| `entidades.listagem.funcionarios` | GET | `/entidades/listagem` | Query defaults para funcionários |
| `operacoes.detalhes` | GET | `/operacoes/{id}/detalhes` | Precisa de `params.id` |
| `operacoes.listar` | POST | `/operacoes/ListarOperacoes` | Body com filtros |

---

## 🖥️ Sugestão de UI — Página de Conexão ERP

A página deve ter **duas seções principais**:

### Seção 1: Configuração da Conexão

Formulário com os campos:

| Campo | Tipo | Obrigatório | Valor Padrão |
|---|---|---|---|
| Nome | `<input text>` | ✅ | `"Hiper - Maiscapinhas"` |
| URL Base | `<input url>` | ✅ | `"https://maiscapinhas.hiper.com.br"` |
| Referer Padrão | `<input text>` | ❌ | `"https://maiscapinhas.hiper.com.br/operacoes"` |

Os `default_headers` normalmente **não precisam ser editados** pelo usuário (usamos os padrões do Chrome). Mas podem ficar num **accordion/collapse** "Headers Avançados" para quem quiser customizar.

**Botão:** `Salvar Conexão` → `POST /hiper/connections/upsert`

---

### Seção 2: Importação de Cookies

Esta é a parte que o admin usa **frequentemente** (sempre que os cookies expiram).

```
┌────────────────────────────────────────────────────────────┐
│  📋 Importar Cookies do DevTools                           │
│                                                            │
│  ┌────────────────────────────────────────────────────────┐│
│  │                                                        ││
│  │  <textarea> Cole aqui o TSV dos cookies...             ││
│  │                                                        ││
│  │  (Copie de: DevTools > Application > Cookies >         ││
│  │   maiscapinhas.hiper.com.br → Ctrl+A → Ctrl+C)        ││
│  │                                                        ││
│  └────────────────────────────────────────────────────────┘│
│                                                            │
│  [ 🔄 Importar Cookies ]                                   │
│                                                            │
│  Status: ✅ 12 cookies importados de 3 domínios            │
│  Última importação: 16/02/2026 14:05                       │
└────────────────────────────────────────────────────────────┘
```

**Fluxo:**
1. Usuário cola o TSV no textarea
2. Clica "Importar Cookies"
3. Frontend chama `POST /hiper/connections/{id}/import-tsv` com `{ tsv: textareaValue }`
4. Mostra resultado: quantos cookies lidos, domínios encontrados

**Dica de UX:** Mostrar um badge/chip colorido para cada domínio retornado.

---

### Seção 3 (Opcional): Teste Rápido

Um mini-formulário para testar se os cookies estão funcionando:

```
┌────────────────────────────────────────────────────────────┐
│  🧪 Teste de Conexão                                       │
│                                                            │
│  URL: [ https://maiscapinhas.hiper.com.br/usuarios/perfis ]│
│                                                            │
│  [ 🔍 Testar ] [ 📋 Copiar cURL ]                          │
│                                                            │
│  Cookie essencial: dominio_hiper=...; TempDataProvider=... │
│  Faltando: (nenhum)                                        │
│                                                            │
│  ── Resultado do cURL ──                                   │
│  curl --location '...' \                                   │
│    --header 'Accept: ...' \                                │
│    --header 'Cookie: ...'                                  │
└────────────────────────────────────────────────────────────┘
```

**Fluxo "Testar":**
1. Chama `GET /hiper/connections/{id}/curl?url=...` para ver o cookie gerado
2. Opcionalmente, chama `POST /hiper/execute` com `endpoint_key: "usuarios.perfis"` para fazer a chamada real

**Fluxo "Copiar cURL":**
1. Chama `GET /hiper/connections/{id}/curl?url=...`
2. Copia o campo `curl` para o clipboard

---

## 📊 TypeScript Schemas

```typescript
// ============ CONEXÃO ============
interface HiperConnection {
  id: number;
  name: string;
  base_url: string;
  default_referer: string | null;
  default_headers: Record<string, string>;
  cookies?: HiperCookiesJson;  // só vem no upsert
  is_active: boolean;
  last_used_at: string | null;
  created_at: string;
  updated_at: string;
}

interface HiperCookiesJson {
  by_domain: Record<string, Record<string, string>>;
  last_imported_at: string;
}

// ============ ENDPOINT ============
interface HiperEndpoint {
  id: number;
  key: string;
  method: 'GET' | 'POST';
  path: string;
  headers: Record<string, string> | null;
  query_template: Record<string, string> | null;
  body_template: Record<string, any> | null;
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

interface ExecuteRequest {
  connection_id: number;
  endpoint_key: string;
  params?: Record<string, string>;
  query?: Record<string, any>;
  body?: Record<string, any>;
}

// ============ RESPONSES ============
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

interface ExecuteErrorResponse {
  ok: false;
  error: string;
  url: string;
  missing_cookies: string[];
}
```

---

## 🔧 Service Sugerido

```typescript
// src/services/hiperErpService.ts
import api from '@/lib/api';

const BASE = '/hiper';

export const hiperErpService = {
  /** Criar ou atualizar conexão */
  async upsertConnection(data: UpsertConnectionRequest) {
    const res = await api.post<UpsertConnectionResponse>(
      `${BASE}/connections/upsert`,
      data
    );
    return res.data;
  },

  /** Importar cookies do TSV colado do DevTools */
  async importTsv(connectionId: number, tsv: string) {
    const res = await api.post<ImportTsvResponse>(
      `${BASE}/connections/${connectionId}/import-tsv`,
      { tsv }
    );
    return res.data;
  },

  /** Gerar cookie essencial + cURL */
  async getCurl(connectionId: number, url: string) {
    const res = await api.get<CurlResponse>(
      `${BASE}/connections/${connectionId}/curl`,
      { params: { url } }
    );
    return res.data;
  },

  /** Executar request no ERP */
  async execute(data: ExecuteRequest) {
    const res = await api.post<ExecuteResponse>(
      `${BASE}/execute`,
      data
    );
    return res.data;
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
4. **O campo `cookies` é criptografado** — o backend cuida disso, o frontend não precisa se preocupar
5. **O TSV aceita múltiplos domínios** colados juntos — o parser do backend separa automaticamente
