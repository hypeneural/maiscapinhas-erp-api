# API PDV Reports v3

Data: 2026-02-12  
Base path: `/api/v1/pdv/reports/*`  
Auth: `auth:sanctum`

## 1) GET `/api/v1/pdv/reports/turnos`

Consulta fechamento de caixa por turno no modelo `pdv_turnos` + `pdv_turno_pagamentos`.

Query params:
- `store_id` (int, opcional) ou `store_pdv_id` (int, opcional). Informar pelo menos um.
- `date` (date, obrigatorio): data de referencia (`YYYY-MM-DD`).
- `sequencial` (int, opcional).
- `periodo` (enum, opcional): `MATUTINO`, `VESPERTINO`, `NOTURNO`.
- `fechado` (bool, opcional): `true/false` ou `1/0`.
- `operador_id` (int, opcional): filtra por `operador_pdv_id`.
- `responsavel_id` (int, opcional): filtra por `responsavel_pdv_id`.

Resposta (resumo):

```json
{
  "data": {
    "filters": {
      "store_id": 1,
      "store_pdv_id": 13,
      "date": "2026-02-11",
      "sequencial": null,
      "periodo": "MATUTINO",
      "fechado": true,
      "operador_id": 12,
      "responsavel_id": 80
    },
    "summary": {
      "qtd_turnos": 1,
      "qtd_turnos_fechados": 1,
      "qtd_turnos_falta": 1,
      "qtd_turnos_sobra": 0,
      "qtd_turnos_conferido": 0,
      "total_sistema": 12500.0,
      "total_declarado": 12480.0,
      "total_falta": 20.0,
      "total_falta_absoluto": 20.0
    },
    "turnos": [
      {
        "id_turno": "656335C4-D6C4-455A-8E3D-FF6B3F570C64",
        "sequencial": 2,
        "status": "FECHADO",
        "operador": { "id_usuario": 12, "nome": "Carlos" },
        "responsavel": { "id_usuario": 80, "nome": "Daren" },
        "totais": {
          "total_sistema": 12500.0,
          "total_declarado": 12480.0,
          "total_falta": 20.0,
          "falta_caixa_tipo": "FALTA",
          "falta_caixa_valor_absoluto": 20.0
        },
        "pagamentos": {
          "sistema": [],
          "declarado": [],
          "falta": []
        }
      }
    ]
  },
  "meta": {
    "request_id": "req-...",
    "timestamp": "2026-02-12T03:40:00Z"
  }
}
```

## 2) GET `/api/v1/pdv/reports/vendas`

Consulta vendas com filtros v3 e paginacao.

Query params:
- `store_id` (int, opcional).
- `store_pdv_id` (int, opcional).
- `from` (date, opcional, default = hoje-30d).
- `to` (date, opcional, default = hoje).
- `vendedor_id` (int, opcional): `vendedor_pdv_id`.
- `canal` (enum, opcional): `HIPER_CAIXA` ou `HIPER_LOJA`.
- `id_turno` (string, opcional).
- `id_finalizador` (int, opcional): filtra vendas que tenham pagamento com este finalizador.
- `meio_pagamento` (string, opcional): filtro textual exato case-insensitive no nome do meio.
- `sort` (enum, opcional): `asc` ou `desc` (default `desc`).
- `per_page` (int, opcional, default `25`, max `100`).

Resposta (resumo):

```json
{
  "data": [
    {
      "store_id": 1,
      "store_pdv_id": 13,
      "id_operacao": 12380,
      "canal": "HIPER_CAIXA",
      "id_turno": "656335C4-D6C4-455A-8E3D-FF6B3F570C64",
      "total": 129.0,
      "itens": { "qtd_linhas": 3, "qtd_total": 3.0, "valor_total": 129.0 },
      "pagamentos": { "qtd_linhas": 1, "valor_total": 129.0 }
    },
    {
      "store_id": 1,
      "store_pdv_id": 13,
      "id_operacao": 22380,
      "canal": "HIPER_LOJA",
      "id_turno": null,
      "total": 245.5,
      "itens": { "qtd_linhas": 2, "qtd_total": 2.0, "valor_total": 245.5 },
      "pagamentos": { "qtd_linhas": 1, "valor_total": 245.5 }
    }
  ],
  "summary": {
    "total_vendas": 2,
    "total_vendido": 374.5
  },
  "filters": {
    "canal": null
  },
  "meta": {
    "pagination": {
      "total": 2,
      "per_page": 25,
      "current_page": 1,
      "last_page": 1
    }
  }
}
```

Exemplo filtrando somente canal caixa:

`GET /api/v1/pdv/reports/vendas?store_pdv_id=13&from=2026-02-01&to=2026-02-12&canal=HIPER_CAIXA`

Exemplo filtrando somente canal loja:

`GET /api/v1/pdv/reports/vendas?store_pdv_id=13&from=2026-02-01&to=2026-02-12&canal=HIPER_LOJA`

## 3) GET `/api/v1/pdv/reports/ranking-vendedores`

Ranking por vendedor com base em `pdv_venda_itens` + `pdv_vendas`.

Query params:
- `mode` (enum, opcional): `daily`, `weekly`, `monthly` (default `monthly`).
- `reference_date` (date, opcional): ancora para `mode`.
- `from` e `to` (date, opcional): quando informados, sobrescrevem `mode`.
- `store_id` (int, opcional).
- `store_pdv_id` (int, opcional).
- `canal` (enum, opcional): `HIPER_CAIXA` ou `HIPER_LOJA`.
- `limit` (int, opcional, default `50`, max `200`).

Resposta (resumo):

```json
{
  "data": {
    "mode": "monthly",
    "period": {
      "from": "2026-02-01T00:00:00Z",
      "to": "2026-02-28T23:59:59Z"
    },
    "summary": {
      "vendedores": 2,
      "total_vendido": 10000.0,
      "qtd_vendas": 120,
      "total_itens": 280.0
    },
    "ranking": [
      {
        "position": 1,
        "vendedor_id": 80,
        "vendedor_nome": "Daren",
        "qtd_vendas": 70,
        "total_vendido": 6200.0,
        "total_itens": 170.0
      },
      {
        "position": 2,
        "vendedor_id": 12,
        "vendedor_nome": "Carlos",
        "qtd_vendas": 50,
        "total_vendido": 3800.0,
        "total_itens": 110.0
      }
    ]
  }
}
```

## 4) GET `/api/v1/pdv/reports/ranking-vendedor-loja`

Ranking analitico cruzando vendedor e loja no periodo informado.

Query params:
- `from` (date, obrigatorio).
- `to` (date, obrigatorio).
- `store_id` (int, opcional).
- `store_pdv_id` (int, opcional).
- `vendedor_id` (int, opcional).
- `canal` (enum, opcional): `HIPER_CAIXA` ou `HIPER_LOJA`.
- `sort_by` (enum, opcional): `total_vendido`, `qtd_vendas`, `total_itens`.
- `sort` (enum, opcional): `asc` ou `desc` (default `desc`).
- `per_page` (int, opcional, default `50`, max `200`).

Resposta (resumo):

```json
{
  "data": [
    {
      "position": 1,
      "store_id": 2,
      "store_pdv_id": 14,
      "store_nome": "Loja Centro",
      "vendedor_id": 80,
      "vendedor_nome": "Daren",
      "qtd_vendas": 32,
      "total_vendido": 8450.0,
      "total_itens": 126.0
    }
  ],
  "summary": {
    "linhas": 1,
    "total_vendido": 8450.0,
    "qtd_vendas": 32,
    "total_itens": 126.0
  },
  "filters": {
    "canal": "HIPER_CAIXA",
    "sort_by": "total_vendido",
    "sort": "desc"
  }
}
```

## 5) Politica de autorizacao

- Super admin: acesso global.
- Usuario comum: somente lojas com vinculo em `store_users`.
- Se `store_id`/`store_pdv_id` nao pertencer a uma loja autorizada, retorna `403`.

## 6) Regra operacional para warning `GESTAO_DB_FAILURE`

Quando o webhook chegar com `integrity.warnings[]` contendo prefixo `GESTAO_DB_FAILURE`, o backend mapeia para `risk_flags=["gestao_db_failure"]`.

Regras para KPI e agregacoes:
- Nao zerar metricas do canal `HIPER_LOJA` automaticamente nesse ciclo.
- Marcar a leitura como potencialmente incompleta e manter o ultimo valor confiavel para comparativos.
- Expor essa condicao no monitor/admin para triagem operacional.
- Nao rejeitar payload valido apenas por warning operacional.

Consulta administrativa para triagem:
- `GET /api/v1/admin/pdv/syncs?risk_flag=gestao_db_failure`
