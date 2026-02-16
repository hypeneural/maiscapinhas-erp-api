# 🔍 Guia Frontend — Validação de Lote com Fonte ERP

**Data**: 2026-02-16  
**Endpoint**: `POST /api/v1/pdv/sales/validate-batch`  
**Acesso**: Somente **Super Admin**

---

## 📖 Contexto

A página de **Validação de Vendas** (aba "Validação em Lote") já possui:
- Uma **textarea** onde o admin cola o JSON com `{ "Lista": [...] }`
- Um botão **"Validar Lote"** que envia esses dados

A mudança é adicionar um **segundo botão** que busca os dados direto do ERP Hiper Gestão, sem precisar colar JSON manualmente.

---

## 🖥️ UI Sugerida

```
┌──────────────────────────────────────────────────────────────┐
│  Validação em Lote                                           │
│                                                              │
│  ┌─ Fonte dos dados ──────────────────────────────────────┐  │
│  │  (●) JSON Manual    (○) Buscar do ERP                  │  │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  ══ Se "JSON Manual" (existente, sem mudanças) ═════════════ │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │  <textarea> Cole o JSON aqui...                        │  │
│  └────────────────────────────────────────────────────────┘  │
│  [ 🔄 Validar Lote ]                                        │
│                                                              │
│  ══ Se "Buscar do ERP" (novo, só p/ super admin) ═══════════ │
│                                                              │
│  Conexão: [Hiper - Maiscapinhas ▾]  (ou hidden se só 1)     │
│                                                              │
│  ┌─ Filtro do ERP (JSON) ─────────────────────────────────┐  │
│  │  {                                                     │  │
│  │    "filtro": {                                         │  │
│  │      "Lojas": [                                        │  │
│  │        { "Id": "4dcbc02b-...", "Nome": "MC Morretes" } │  │
│  │      ]                                                 │  │
│  │    }                                                   │  │
│  │  }                                                     │  │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  [ 🌐 Buscar do ERP e Validar ]                              │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## 📡 Chamadas da API

### Modo 1: JSON Manual (sem mudanças)

```typescript
// Comportamento atual — nada muda
const response = await api.post('/pdv/sales/validate-batch', {
  Lista: parsedJson.Lista,
  timezone: 'America/Sao_Paulo',
});
```

### Modo 2: Buscar do ERP

```typescript
const response = await api.post('/pdv/sales/validate-batch', {
  source: 'erp',                          // ← nova flag
  connection_id: selectedConnectionId,     // opcional, usa a padrão se omitir
  body: filterJson,                        // o filtro editado pelo admin
  timezone: 'America/Sao_Paulo',
});
```

> O campo `body` faz **merge** com o template salvo do endpoint `operacoes.listar`, então o admin só precisa enviar o que quer sobrescrever (ex: filtro de Lojas).

---

## 📊 Diferenças na Response

| Campo | Modo JSON | Modo ERP |
|---|---|---|
| `source` | `"json"` | `"erp"` |
| `batch_count` | ✅ | ✅ |
| `results` | ✅ | ✅ |
| `ok` | — | ✅ (indica se o ERP respondeu) |
| `erp_total_returned` | — | ✅ (quantas vendas o ERP retornou) |
| `url` | — | ✅ (URL chamada no ERP) |
| `missing_cookies` | — | ✅ (cookies faltando) |

O array `results` tem a **mesma estrutura** nos dois modos, incluindo o `sale_summary`.

---

## 📋 Resumo da Venda (`sale_summary`)

Cada item do array `results` agora inclui um campo `sale_summary` com dados legíveis:

| Campo | Tipo | Descrição |
|---|---|---|
| `codigo` | `number` | Código da operação no ERP |
| `erp_id` | `string` | UUID da operação no ERP |
| `valor` | `string` | Valor formatado (ex: `"R$ 110,00"`) |
| `data` | `string` | Data formatada (ex: `"16/02/2026 às 14:50"`) |
| `turno` | `string` | Label do turno (ex: `"1º Turno"`) |
| `turno_id` | `string` | UUID do turno no ERP |
| `loja_erp_id` | `string` | UUID da loja no ERP (`Turno.LojaId`) |
| `loja_nome` | `string\|null` | Nome da loja **resolvido do nosso DB** (via `stores.guid`) |
| `found_in_db` | `boolean` | Se a loja foi encontrada no nosso banco |
| `cancelada` | `boolean` | Se a venda foi cancelada |
| `itens` | `number` | Quantidade de itens na venda |

**Exemplo de um resultado:**
```json
{
  "input_id": "b33ee0f6-6fbc-4cd2-b07f-26e68a74ddc1",
  "sale_summary": {
    "codigo": 298140,
    "erp_id": "b33ee0f6-6fbc-4cd2-b07f-26e68a74ddc1",
    "valor": "R$ 110,00",
    "data": "16/02/2026 às 14:50",
    "turno": "1º Turno",
    "turno_id": "adfbc718-1190-433e-8e9f-372bbc767992",
    "loja_erp_id": "ba5c67af-0f8d-451d-932e-61f84a041169",
    "loja_nome": "Loja 2 - MC Morretes",
    "found_in_db": true,
    "cancelada": false,
    "itens": 2
  },
  "validation": { "...resultado da validação..." }
}
```

> Se `found_in_db: false`, significa que a loja ERP **não está mapeada** no nosso banco. Útil para detectar lojas faltando.

---

## 🔧 TypeScript

```typescript
interface ValidateBatchRequest {
  // Modo JSON
  Lista?: any[];

  // Modo ERP
  source?: 'json' | 'erp';
  connection_id?: number;
  endpoint_key?: string;   // default: 'operacoes.listar'
  body?: Record<string, any>;

  // Comum
  timezone?: string;
  tolerance?: {
    total?: number;
    start_minus_minutes?: number;
    end_plus_minutes?: number;
  };
}

interface SaleSummary {
  codigo: number | null;
  erp_id: string | null;
  valor: string | null;
  data: string | null;         // "16/02/2026 às 14:50"
  turno: string | null;        // "1º Turno"
  turno_id: string | null;
  loja_erp_id: string | null;
  loja_nome: string | null;
  found_in_db: boolean;
  cancelada: boolean;
  itens: number | null;
}

interface ValidateBatchResponse {
  ok?: boolean;               // só no modo erp
  source: 'json' | 'erp';
  batch_count: number;
  erp_total_returned?: number; // só no modo erp
  url?: string;                // só no modo erp
  missing_cookies?: string[];  // só no modo erp
  results: Array<{
    input_id: string;
    sale_summary: SaleSummary;
    validation: any;           // mesmo schema do validate-single
  }>;
}
```

---

## 💡 Implementação Sugerida

```typescript
// No componente SalesValidation.tsx (aba batch)

const [source, setSource] = useState<'json' | 'erp'>('json');
const [filterJson, setFilterJson] = useState<string>(
  JSON.stringify({
    filtro: {
      Lojas: [
        // Pré-carregar as lojas do admin, ou deixar vazio
      ]
    }
  }, null, 2)
);

async function handleValidateBatch() {
  if (source === 'json') {
    // Lógica atual — sem mudanças
    const parsed = JSON.parse(textareaValue);
    const res = await api.post('/pdv/sales/validate-batch', {
      Lista: parsed.Lista,
      timezone: 'America/Sao_Paulo',
    });
    setResults(res.data);
  } else {
    // Novo — buscar do ERP
    const body = JSON.parse(filterJson);
    const res = await api.post('/pdv/sales/validate-batch', {
      source: 'erp',
      body,
      timezone: 'America/Sao_Paulo',
    });

    if (!res.data.ok) {
      // Mostrar erro: res.data.error
      // Se missing_cookies não vazio → sugerir reimportar cookies
      return;
    }

    setResults(res.data);
  }
}
```

---

## ⚠️ Pontos de Atenção

1. **Mostrar/Esconder** — O radio "Buscar do ERP" só aparece para **super admin**
2. **Missing cookies** — Se `missing_cookies.length > 0`, mostrar alerta: *"Cookies podem estar expirados. Reimporte na página Hiper ERP."*
3. **Timeout** — O modo ERP pode demorar mais (até 60s) porque faz request real ao Hiper. Mostrar loading/spinner
4. **Filtro padrão** — O textarea de filtro pode vir pré-preenchido com as lojas que o admin tem mapeadas
5. **Retrocompatibilidade** — Se `source` não for enviado, funciona exatamente como antes (`"json"`)
