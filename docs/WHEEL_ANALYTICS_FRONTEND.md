# Wheel Analytics API - Documentação Frontend

> Documentação completa dos novos endpoints de analytics avançado do módulo Wheel.  
> **Base URL:** `/api/v1/admin/wheel`

---

## 📊 Índice

1. [Performance por Loja](#1-performance-por-loja)
2. [Pico de Horário](#2-pico-de-horário)
3. [Mapa Geográfico](#3-mapa-geográfico)
4. [Métricas de ROI](#4-métricas-de-roi)

---

## 1. Performance por Loja

### `GET /analytics/by-store`

Retorna métricas de performance agrupadas por loja e screen, permitindo comparar o desempenho entre diferentes pontos de venda.

### Query Parameters

| Parâmetro | Tipo | Obrigatório | Default | Descrição |
|-----------|------|-------------|---------|-----------|
| `period` | `string` | Não | `week` | Período: `today`, `week`, `month` |
| `from` | `date` | Não | - | Data inicial (formato: YYYY-MM-DD) |
| `to` | `date` | Não | - | Data final (formato: YYYY-MM-DD) |

### Response Schema

```typescript
interface ByStoreResponse {
  success: boolean;
  data: {
    period: {
      from: string;  // "2026-01-12"
      to: string;    // "2026-01-19"
    };
    by_store: StorePerformance[];
  };
}

interface StorePerformance {
  store_id: number;
  store_name: string;
  screens_count: number;
  totals: {
    spins: number;
    prizes_won: number;
    players_joined: number;
    redeemed: number;
  };
  screens: ScreenPerformance[];
}

interface ScreenPerformance {
  screen_key: string;
  screen_name: string;
  store_id: number;
  store_name: string;
  metrics: {
    spins: number;
    prizes_won: number;
    players_joined: number;
    redeemed: number;
    conversion_rate: number;   // Porcentagem (0-100)
    redemption_rate: number;   // Porcentagem (0-100)
  };
}
```

### Tooltips para UI

| Campo | Tooltip |
|-------|---------|
| `spins` | "Total de giros realizados neste período" |
| `prizes_won` | "Quantidade de prêmios ganhos (exclui 'Nada' e 'Tente Novamente')" |
| `players_joined` | "Jogadores únicos que participaram" |
| `redeemed` | "Prêmios que foram resgatados na loja" |
| `conversion_rate` | "Porcentagem de giros que resultaram em prêmio" |
| `redemption_rate` | "Porcentagem de prêmios ganhos que foram resgatados" |

### Exemplo de Response

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-01-12", "to": "2026-01-19" },
    "by_store": [
      {
        "store_id": 1,
        "store_name": "Loja Centro",
        "screens_count": 2,
        "totals": {
          "spins": 156,
          "prizes_won": 78,
          "players_joined": 142,
          "redeemed": 52
        },
        "screens": [
          {
            "screen_key": "screen_abc123",
            "screen_name": "TV Entrada",
            "store_id": 1,
            "store_name": "Loja Centro",
            "metrics": {
              "spins": 98,
              "prizes_won": 45,
              "players_joined": 85,
              "redeemed": 30,
              "conversion_rate": 45.9,
              "redemption_rate": 66.7
            }
          }
        ]
      }
    ]
  }
}
```

---

## 2. Pico de Horário

### `GET /analytics/peak-hours`

Retorna a distribuição de giros por hora do dia e dia da semana, identificando os horários de maior movimento.

### Query Parameters

| Parâmetro | Tipo | Obrigatório | Default | Descrição |
|-----------|------|-------------|---------|-----------|
| `period` | `string` | Não | `week` | Período: `today`, `week`, `month` |

### Response Schema

```typescript
interface PeakHoursResponse {
  success: boolean;
  data: {
    period: string;
    total_spins: number;
    peak_hour: {
      hour: number;      // 0-23
      label: string;     // "14:00 - 14:59"
      spins: number;
    };
    peak_day: {
      day: number;       // 0=Domingo, 6=Sábado
      name: string;      // "Sábado"
      spins: number;
    };
    by_hour: HourData[];
    by_day_of_week: DayData[];
  };
}

interface HourData {
  hour: number;     // 0-23
  label: string;    // "14:00"
  spins: number;
}

interface DayData {
  name: string;     // "Segunda", "Terça", etc.
  spins: number;
}
```

### Tooltips para UI

| Campo | Tooltip |
|-------|---------|
| `peak_hour` | "Horário com maior número de giros. Ideal para ações promocionais" |
| `peak_day` | "Dia da semana com maior engajamento" |
| `by_hour` | "Use para identificar horários de baixo movimento e otimizar campanhas" |
| `by_day_of_week` | "Permite ajustar estoque e equipe conforme demanda" |

### Sugestões de Visualização

- **Gráfico de barras horizontal:** para `by_hour` (horários no eixo Y)
- **Gráfico de barras vertical:** para `by_day_of_week`
- **Cards de destaque:** para `peak_hour` e `peak_day`

### Exemplo de Response

```json
{
  "success": true,
  "data": {
    "period": "week",
    "total_spins": 409,
    "peak_hour": {
      "hour": 15,
      "label": "15:00 - 15:59",
      "spins": 45
    },
    "peak_day": {
      "day": 6,
      "name": "Sábado",
      "spins": 98
    },
    "by_hour": [
      { "hour": 0, "label": "00:00", "spins": 0 },
      { "hour": 9, "label": "09:00", "spins": 12 },
      { "hour": 10, "label": "10:00", "spins": 28 },
      // ... 24 itens total
    ],
    "by_day_of_week": [
      { "name": "Domingo", "spins": 45 },
      { "name": "Segunda", "spins": 52 },
      { "name": "Terça", "spins": 48 },
      { "name": "Quarta", "spins": 55 },
      { "name": "Quinta", "spins": 61 },
      { "name": "Sexta", "spins": 50 },
      { "name": "Sábado", "spins": 98 }
    ]
  }
}
```

---

## 3. Mapa Geográfico

### `GET /analytics/geographic`

Retorna a distribuição geográfica dos jogadores por cidade e estado, baseado nos dados cadastrais dos participantes.

### Query Parameters

| Parâmetro | Tipo | Obrigatório | Default | Descrição |
|-----------|------|-------------|---------|-----------|
| `period` | `string` | Não | `week` | Período: `today`, `week`, `month` |

### Response Schema

```typescript
interface GeographicResponse {
  success: boolean;
  data: {
    period: string;
    total_players: number;
    coverage: {
      states: number;   // Quantidade de estados distintos
      cities: number;   // Quantidade de cidades distintas
    };
    by_state: StateData[];
    by_city: CityData[];
  };
}

interface StateData {
  state: string;      // "SP", "RJ", "MG"
  players: number;
}

interface CityData {
  city_state: string; // "São Paulo, SP"
  players: number;
}
```

### Tooltips para UI

| Campo | Tooltip |
|-------|---------|
| `coverage.states` | "Número de estados brasileiros com participantes" |
| `coverage.cities` | "Número de cidades diferentes que participaram" |
| `by_state` | "Ranking de estados por número de participantes" |
| `by_city` | "Top 20 cidades com mais jogadores. Útil para expansão de lojas" |

### Observações

- Apenas jogadores que informaram estado são contados
- `by_city` retorna no máximo 20 cidades (ordenadas por participação)
- Estados usam sigla UF (SP, RJ, MG, etc.)

### Exemplo de Response

```json
{
  "success": true,
  "data": {
    "period": "week",
    "total_players": 89,
    "coverage": {
      "states": 8,
      "cities": 15
    },
    "by_state": [
      { "state": "SP", "players": 32 },
      { "state": "RJ", "players": 18 },
      { "state": "MG", "players": 14 },
      { "state": "PR", "players": 10 },
      { "state": "RS", "players": 8 },
      { "state": "SC", "players": 4 },
      { "state": "BA", "players": 2 },
      { "state": "PE", "players": 1 }
    ],
    "by_city": [
      { "city_state": "São Paulo, SP", "players": 25 },
      { "city_state": "Rio de Janeiro, RJ", "players": 15 },
      { "city_state": "Belo Horizonte, MG", "players": 10 },
      { "city_state": "Curitiba, PR", "players": 8 }
    ]
  }
}
```

---

## 4. Métricas de ROI

### `GET /analytics/roi`

Retorna métricas financeiras e de retorno sobre investimento, calculando o custo estimado dos prêmios distribuídos.

### Query Parameters

| Parâmetro | Tipo | Obrigatório | Default | Descrição |
|-----------|------|-------------|---------|-----------|
| `period` | `string` | Não | `week` | Período: `today`, `week`, `month` |
| `campaign_key` | `string` | Não | - | Filtrar por campanha específica |

### Response Schema

```typescript
interface RoiResponse {
  success: boolean;
  data: {
    period: {
      from: string;
      to: string;
    };
    campaign: {
      campaign_key: string;
      name: string;
    } | null;  // null se não filtrado por campanha
    totals: {
      spins: number;
      unique_players: number;
      prizes_distributed: number;
      prizes_redeemed: number;
      total_value_distributed: number;  // Em R$
      total_value_redeemed: number;     // Em R$
    };
    metrics: {
      avg_value_per_player: number;     // R$ por jogador
      cost_per_engagement: number;      // R$ por spin
      cost_per_redemption: number;      // R$ por resgate
      redemption_rate: number;          // Porcentagem
    };
    by_prize_type: PrizeTypeBreakdown[];
  };
}

interface PrizeTypeBreakdown {
  type: string;       // "product", "coupon", "nothing", "try_again"
  count: number;      // Quantidade de spins
  value: number;      // Valor total em R$
  redeemed: number;   // Quantidade resgatada
}
```

### Tooltips para UI

| Campo | Tooltip |
|-------|---------|
| `unique_players` | "Número de jogadores distintos que participaram no período" |
| `prizes_distributed` | "Prêmios reais distribuídos (exclui 'Nada' e 'Tente Novamente')" |
| `total_value_distributed` | "Valor estimado total dos prêmios distribuídos" |
| `total_value_redeemed` | "Valor dos prêmios efetivamente resgatados" |
| `avg_value_per_player` | "Investimento médio por participante. Útil para calcular custo de aquisição" |
| `cost_per_engagement` | "Custo médio por interação (spin). Indica eficiência da campanha" |
| `cost_per_redemption` | "Custo médio por prêmio resgatado. Prêmios não resgatados = economia" |
| `redemption_rate` | "% de prêmios que foram efetivamente resgatados" |

### Lógica de Cálculo

```
avg_value_per_player = total_value_distributed / unique_players
cost_per_engagement = total_value_distributed / spins
cost_per_redemption = total_value_redeemed / prizes_redeemed
redemption_rate = (prizes_redeemed / prizes_distributed) * 100
```

### Valores Estimados por Tipo de Prêmio

Os valores são baseados no campo `estimated_value` de cada prêmio:
- **Produtos físicos:** ~R$ 15,00 (configurável)
- **Cupons de desconto:** ~R$ 10,00 (configurável)
- **"Nada" / "Tente Novamente":** R$ 0,00

### Exemplo de Response

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-01-12", "to": "2026-01-19" },
    "campaign": null,
    "totals": {
      "spins": 409,
      "unique_players": 120,
      "prizes_distributed": 215,
      "prizes_redeemed": 140,
      "total_value_distributed": 2580.00,
      "total_value_redeemed": 1680.00
    },
    "metrics": {
      "avg_value_per_player": 21.50,
      "cost_per_engagement": 6.31,
      "cost_per_redemption": 12.00,
      "redemption_rate": 65.1
    },
    "by_prize_type": [
      { "type": "product", "count": 45, "value": 675.00, "redeemed": 30 },
      { "type": "coupon", "count": 170, "value": 1700.00, "redeemed": 110 },
      { "type": "nothing", "count": 98, "value": 0, "redeemed": 0 },
      { "type": "try_again", "count": 96, "value": 0, "redeemed": 0 }
    ]
  }
}
```

---

## 🎨 Sugestões de UI

### Cards de Resumo (Dashboard)
- **ROI Card:** Mostrar `avg_value_per_player` em destaque
- **Peak Card:** Mostrar `peak_hour` e `peak_day`
- **Coverage Card:** Mostrar estados/cidades cobertos

### Gráficos Recomendados
- **by_hour:** Gráfico de área ou barras (heatmap horizontal)
- **by_day_of_week:** Gráfico de barras vertical
- **by_state:** Mapa do Brasil com cores por intensidade
- **by_prize_type:** Gráfico de pizza ou donut

### Tabelas
- **by_store:** Tabela ordenável por qualquer coluna
- **by_city:** Tabela com paginação (top 20)

---

## 🔐 Permissões

Todos os endpoints requerem:
- Autenticação via Bearer Token
- Permissão: `wheel.analytics.view`

---

## ⚠️ Códigos de Erro

| Código | Significado |
|--------|-------------|
| 401 | Token inválido ou expirado |
| 403 | Sem permissão para acessar analytics |
| 422 | Parâmetros inválidos (ex: period inválido) |
| 500 | Erro interno do servidor |
