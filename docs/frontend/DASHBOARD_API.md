# 📊 Dashboard API - Guia Completo para Frontend

> **Documentação de referência para implementação dos dashboards por perfil**  
> **Atualizado:** 09/01/2026  
> **Backend Version:** API v1

---

## 📋 Índice

1. [Arquitetura de Endpoints](#arquitetura-de-endpoints)
2. [Dashboard do Vendedor](#dashboard-do-vendedor)
3. [Dashboard do Conferente](#dashboard-do-conferente)
4. [Dashboard do Gerente](#dashboard-do-gerente)
5. [Dashboard do Admin](#dashboard-do-admin)
6. [Endpoints Complementares](#endpoints-complementares)
7. [TypeScript Schemas](#typescript-schemas)
8. [Sugestões de Componentes UI](#sugestões-de-componentes-ui)
9. [Segurança e Boas Práticas](#segurança-e-boas-práticas)

---

## 🏗️ Arquitetura de Endpoints

### Resposta às Perguntas Principais

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | **Endpoint único ou múltiplos?** | ✅ **Endpoints separados por role** - Cada dashboard tem seu endpoint específico otimizado |
| 2 | **Filtros disponíveis** | `store_id`, `date`, `month` (depende do endpoint) |
| 3 | **Cálculo de trends** | ✅ **Backend calcula** - Já fornece `yoy_growth`, `today_vs_average`, etc. |
| 4 | **Status do farol** | ✅ **Backend calcula** - Usa regras fixas (80%/100%) |
| 5 | **Top N vendedores** | ✅ Configurável via `?limit=N` (default: 10) |
| 6 | **Divergências** | Endpoints separados: `/cash/shifts/pending` e `/cash/shifts/divergent` |
| 7 | **Dashboards por role** | ✅ **Endpoints separados** |
| 8 | **Refresh automático** | Recomendado **Polling** com `staleTime: 60000` (1 min) |

### Mapa de Endpoints por Role

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         DASHBOARD ENDPOINTS                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  VENDEDOR                    CONFERENTE                                 │
│  ─────────                   ───────────                                │
│  GET /dashboard/vendedor     GET /dashboard/conferente                  │
│  GET /finance/bonus/seller   GET /cash/shifts/pending                   │
│  GET /finance/commission/    GET /cash/shifts/divergent                 │
│      projection              GET /reports/cash-integrity                │
│                                                                          │
│  GERENTE                     ADMIN                                      │
│  ────────                    ─────                                      │
│  GET /dashboard/admin        GET /dashboard/admin                       │
│  GET /reports/store-         GET /reports/consolidated                  │
│      performance             GET /admin/audit-logs/stats                │
│  GET /reports/ranking        GET /finance/commission                    │
│                              GET /finance/bonus                         │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 👤 Dashboard do Vendedor

### Endpoint Principal

```http
GET /api/v1/dashboard/vendedor?store_id=1&date=2026-01-09
```

### Query Parameters

| Param | Tipo | Obrigatório | Default | Descrição |
|-------|------|-------------|---------|-----------|
| `store_id` | `integer` | ✅ Sim | - | ID da loja |
| `date` | `YYYY-MM-DD` | Não | Hoje | Data para consulta |

### Response Schema

```json
{
  "data": {
    "date": "2026-01-09",
    
    // KPI 1: Vendas do dia
    "my_sales": {
      "count": 5,
      "total": 450.00
    },
    
    // Comparativo com loja
    "store_sales": {
      "count": 23,
      "total": 3200.00
    },
    
    // KPI 2: Gamificação de Bônus (Próximo Nível)
    "bonus_gamification": {
      "current_amount": 450.00,        // Vendeu hoje
      "next_bonus_goal": 500.00,       // Meta do próximo tier
      "gap_to_bonus": 50.00,           // Falta para próximo tier
      "next_bonus_value": 10.00,       // Valor do próximo bônus
      "current_bonus_earned": 0,       // Bônus já garantido
      "message": "Faltam R$ 50,00 para ganhar R$ 10,00 de bônus!"
    },
    
    // KPI 3: Projeção de Comissão Mensal
    "monthly_commission": {
      "month": "2026-01",
      "sales_mtd": 8500.00,            // Vendas do mês até agora
      "goal_amount": 15000.00,         // Meta individual
      "achievement_rate": 56.67,       // % atingido
      "days_elapsed": 9,
      "days_total": 31,
      
      "current_tier": 2.0,             // Tier atual (%)
      "current_commission_value": 170.00,
      
      "next_tier": 3.0,                // Próximo tier (%)
      "next_tier_goal": 12000.00,      // Valor para próximo tier
      "next_tier_goal_percent": 80.0,  // % para próximo tier
      "gap_to_next_tier": 3500.00,     // Falta para próximo tier
      
      "projected_sales": 29167.00,     // Projeção de vendas
      "projected_achievement": 194.44, // Projeção de atingimento
      "projected_tier": 4.0,           // Tier projetado
      "potential_commission": 1166.68  // Comissão potencial
    },
    
    // KPI 4: Velocímetro / Pace Diário
    "daily_pace": {
      "today_sales": 450.00,
      "average_daily_sales": 566.67,   // Média diária do mês
      "today_vs_average": -116.67,     // Diferença
      "days_worked_this_month": 15,
      "status": "BEHIND"               // AHEAD | ON_TRACK | BEHIND
    },
    
    // Turnos do dia
    "my_shifts": [
      {
        "id": 12,
        "date": "2026-01-09",
        "shift_code": "M",
        "status": "open",
        "cash_closing": null
      }
    ]
  },
  "meta": {
    "timestamp": "2026-01-09T12:00:00Z"
  }
}
```

### Componentes Sugeridos para Vendedor

```
┌─────────────────────────────────────────────────────────────────────┐
│                    DASHBOARD DO VENDEDOR                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │  🎯 ODÔMETRO DA META DO DIA                          │            │
│  │  ┌────────────────────────────────────────────────┐ │            │
│  │  │          [====>              ] 45%              │ │            │
│  │  │          R$ 450 / R$ 1.000                      │ │            │
│  │  │                                                  │ │            │
│  │  │  ⏰ Faltam 4h no turno  |  🎯 Meta: R$ 550      │ │            │
│  │  └────────────────────────────────────────────────┘ │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
│  ┌───────────────────────┐  ┌───────────────────────┐               │
│  │ 💰 PRÓXIMO BÔNUS      │  │ 📈 COMISSÃO MENSAL    │               │
│  │                       │  │                        │               │
│  │ Faltam R$ 50,00       │  │ Tier Atual: 2%         │               │
│  │ para ganhar R$ 10,00  │  │ Comissão: R$ 170,00    │               │
│  │                       │  │                        │               │
│  │ [=====>      ] 90%    │  │ Se bater meta:         │               │
│  │                       │  │ Sobe para 3% 📈        │               │
│  │ 🏆 Bônus atual: R$ 0  │  │ Potencial: R$ 450,00   │               │
│  └───────────────────────┘  └───────────────────────┘               │
│                                                                      │
│  ┌───────────────────────┐  ┌───────────────────────┐               │
│  │ ⚡ PACE DIÁRIO        │  │ 🔄 MEUS TURNOS        │               │
│  │                       │  │                        │               │
│  │ Hoje: R$ 450,00       │  │ Turno M - Aberto ⏳    │               │
│  │ Média: R$ 566,67      │  │                        │               │
│  │                       │  │ [Fechar Caixa]         │               │
│  │ 🔻 -20% abaixo        │  │                        │               │
│  │ Status: BEHIND        │  │                        │               │
│  └───────────────────────┘  └───────────────────────┘               │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Hooks React Sugeridos

```typescript
// hooks/useDashboardVendedor.ts
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';

interface VendedorDashboardParams {
  storeId: number;
  date?: string; // YYYY-MM-DD
}

export function useDashboardVendedor({ storeId, date }: VendedorDashboardParams) {
  return useQuery({
    queryKey: ['dashboard', 'vendedor', storeId, date],
    queryFn: () => apiGet('/dashboard/vendedor', { 
      store_id: storeId, 
      date 
    }),
    staleTime: 1000 * 60, // 1 minuto - atualiza frequentemente
    refetchInterval: 1000 * 60 * 2, // Polling a cada 2 min
    enabled: !!storeId,
  });
}
```

---

## 🔍 Dashboard do Conferente

### Endpoint Principal

```http
GET /api/v1/dashboard/conferente?store_id=1&date=2026-01-09
```

### Query Parameters

| Param | Tipo | Obrigatório | Default | Descrição |
|-------|------|-------------|---------|-----------|
| `store_id` | `integer` | ✅ Sim | - | ID da loja |
| `date` | `YYYY-MM-DD` | Não | Hoje | Data para consulta |

### Response Schema

```json
{
  "data": {
    "date": "2026-01-09",
    
    // KPI 1: Fila de Pendentes
    "pending_closings": [
      {
        "id": 45,
        "status": "submitted",
        "cash_shift": {
          "id": 22,
          "date": "2026-01-09",
          "shift_code": "M",
          "seller": {
            "id": 6,
            "name": "João Vendedor"
          }
        }
      }
    ],
    "pending_count": 3,
    
    // KPI 2: Vendas da Loja
    "store_sales": {
      "count": 23,
      "total": 3200.00
    },
    
    // KPI 3: Resumo de Turnos
    "shifts_today": {
      "open": 2,
      "closed": 4
    },
    
    // KPI 4: Top Vendedores do Dia
    "top_sellers": [
      {
        "seller_id": 6,
        "name": "João Vendedor",
        "total": 1500.00
      },
      {
        "seller_id": 7,
        "name": "Maria Silva",
        "total": 1200.00
      }
    ]
  }
}
```

### Endpoints Complementares para Conferente

#### 1. Turnos Pendentes de Conferência

```http
GET /api/v1/cash/shifts/pending?store_id=1
```

```json
{
  "data": {
    "pending_count": 5,
    "shifts": [
      {
        "id": 22,
        "date": "2026-01-09",
        "shift_code": "M",
        "priority": "high",          // high | medium | low
        "store_name": "Tijucas",
        "seller_name": "João Silva",
        "system_total": 4500.00,
        "days_pending": 0
      }
    ]
  }
}
```

#### 2. Turnos com Divergência

```http
GET /api/v1/cash/shifts/divergent?store_id=1&month=2026-01
```

```json
{
  "data": {
    "total_divergent": 3,
    "total_divergence_value": -85.00,
    "shifts": [
      {
        "id": 18,
        "date": "2026-01-07",
        "shift_code": "T",
        "seller_name": "Maria Silva",
        "divergence": -50.00,
        "has_justification": false,
        "days_pending": 2
      }
    ]
  }
}
```

#### 3. Relatório de Integridade de Caixa

```http
GET /api/v1/reports/cash-integrity?store_id=1&month=2026-01
```

```json
{
  "data": {
    "store_id": 1,
    "period": "2026-01",
    
    // KPI 2: % de Quebra de Caixa
    "cash_integrity": {
      "total_system_value": 150000.00,
      "total_real_value": 146250.00,
      "total_divergence": -3750.00,
      "cash_break_percentage": 2.5,    // % de quebra
      "status": "YELLOW"               // GREEN | YELLOW | RED
    },
    
    // KPI 3: Divergências Não Justificadas
    "divergence_analysis": {
      "total_lines_with_divergence": 15,
      "justified_count": 12,
      "unjustified_count": 3,
      "justified_rate": 80.00
    },
    
    // Status do Workflow
    "workflow_status": {
      "total_shifts": 45,
      "closed_count": 40,
      "pending_approval": 3,
      "completion_rate": 88.89
    },
    
    // Alertas Automáticos
    "alerts": [
      {
        "type": "WARNING",
        "code": "ELEVATED_CASH_BREAK",
        "message": "Quebra de 2.50% acima do limite"
      }
    ]
  }
}
```

### Componentes Sugeridos para Conferente

```
┌─────────────────────────────────────────────────────────────────────┐
│                    DASHBOARD DO CONFERENTE                           │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐     │
│  │ 📋 FILA    │  │ ⚠️ QUEBRA   │  │ ❌ NÃO JUST │  │ ✅ FECHADOS │     │
│  │            │  │            │  │            │  │            │     │
│  │    5       │  │   2.5%     │  │     3      │  │    40      │     │
│  │  pendentes │  │  (Amarelo) │  │divergências│  │   turnos   │     │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘     │
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │  📋 FILA DE ENVELOPES PENDENTES                      │            │
│  ├─────────────────────────────────────────────────────┤            │
│  │  🔴 Alta  │ João Silva   │ Turno M │ 09/01 │ R$ 4.500│            │
│  │  🟡 Média │ Maria Santos │ Turno T │ 08/01 │ R$ 3.200│            │
│  │  🟢 Baixa │ Pedro Costa  │ Turno M │ 08/01 │ R$ 2.100│            │
│  │                                                      │            │
│  │  [Aprovar] [Rejeitar] [Ver Detalhes]                 │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │  ❌ DIVERGÊNCIAS NÃO JUSTIFICADAS                    │            │
│  ├─────────────────────────────────────────────────────┤            │
│  │  Vendedor      │  Data   │ Turno │ Diferença        │            │
│  │  João Silva    │ 07/01   │   T   │ -R$ 50,00 🔴     │            │
│  │  Maria Santos  │ 06/01   │   M   │ -R$ 25,00 🟡     │            │
│  │  Pedro Costa   │ 05/01   │   T   │ -R$ 10,00 🟢     │            │
│  │                                                      │            │
│  │  Total: -R$ 85,00                                    │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Regras de Status para Conferente

| Métrica | Verde 🟢 | Amarelo 🟡 | Vermelho 🔴 |
|---------|----------|------------|-------------|
| **Quebra de Caixa** | < 1% | 1% - 2% | > 2% |
| **Divergências Pendentes** | 0 | 1-3 | > 3 |
| **Turnos Pendentes** | 0-2 | 3-5 | > 5 |
| **Taxa de Justificativa** | > 95% | 80-95% | < 80% |

---

## 👔 Dashboard do Gerente

O Gerente utiliza o **endpoint `/dashboard/admin`** com foco em **gerenciamento de equipe e metas**.

### Endpoint Principal

```http
GET /api/v1/dashboard/admin?month=2026-01
```

### Response Schema

```json
{
  "data": {
    "month": "2026-01",
    
    // KPI 1: Vendas Totais da Rede
    "total_sales": {
      "count": 450,
      "total": 67500.00
    },
    
    // Vendas por Loja (usado para Farol)
    "sales_by_store": [
      {
        "store_id": 1,
        "store_name": "Mais Capinhas Tijucas",
        "count": 180,
        "total": 28000.00
      },
      {
        "store_id": 2,
        "store_name": "Mais Capinhas Outlet",
        "count": 270,
        "total": 39500.00
      }
    ],
    
    // Resumo de Fechamentos
    "closings_summary": {
      "approved": 40,
      "submitted": 5,
      "draft": 3
    },
    
    // KPI 2: Top Vendedores (Ranking)
    "top_sellers": [
      {
        "seller_id": 6,
        "name": "João Vendedor",
        "total": 12500.00,
        "count": 85
      }
    ]
  }
}
```

### Endpoints Complementares para Gerente

#### 1. Ranking de Vendedores (Detalhado)

```http
GET /api/v1/reports/ranking?month=2026-01&store_id=1&limit=10
```

```json
{
  "data": {
    "period": "2026-01",
    
    // Pódio (Top 3) - Para destaque visual
    "podium": [
      {
        "position": 1,
        "seller": {
          "id": 5,
          "name": "João Silva",
          "avatar_url": "https://...",
          "store_name": "Tijucas"
        },
        "total_sold": 85000.00,
        "goal": 75000.00,
        "achievement_rate": 113.33,
        "bonus_accumulated": 450.00
      }
    ],
    
    // Ranking completo
    "ranking": [...],
    
    // Estatísticas gerais
    "stats": {
      "total_sellers": 25,
      "above_goal": 12,
      "average_achievement": 92.5
    }
  }
}
```

#### 2. Performance da Loja (Com Projeção/Forecast)

```http
GET /api/v1/reports/store-performance?store_id=1&month=2026-01
```

```json
{
  "data": {
    "store_id": 1,
    "period": "2026-01",
    "days_elapsed": 15,
    "days_total": 31,
    
    // Vendas Atuais
    "sales": {
      "current_amount": 31981.29,
      "goal_amount": 52000.00,
      "achievement_rate": 61.50,
      "remaining_to_goal": 20018.71
    },
    
    // Comparação YoY
    "comparison": {
      "same_period_last_year": 26950.00,
      "total_last_year_month": 55835.00,
      "yoy_growth": 18.60
    },
    
    // KPI 3: Projeção de Fechamento (Forecast)
    "forecast": {
      "linear_projection": 66100.00,    // Regra de 3
      "trend_projection": 66220.31,     // Baseado em YoY
      "status": "ON_TRACK"              // ON_TRACK | AT_RISK | BEHIND
    }
  }
}
```

### Componentes Sugeridos para Gerente

```
┌─────────────────────────────────────────────────────────────────────┐
│                    DASHBOARD DO GERENTE                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐     │
│  │ 💰 VENDAS  │  │ 🎯 META    │  │ 👥 ATIVOS  │  │ ⚠️ QUEBRA   │     │
│  │            │  │            │  │            │  │            │     │
│  │ R$ 67.500  │  │   65%      │  │    15      │  │   0.27%    │     │
│  │   ▲ 6.1%   │  │ verde ✓    │  │ vendedores │  │   verde ✓  │     │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘     │
│                                                                      │
│  ┌──────────────────────────────────┐  ┌──────────────────────────┐ │
│  │ 🏆 RANKING DE VENDEDORES          │  │ 🚦 FAROL DE LOJAS        │ │
│  ├──────────────────────────────────│  ├──────────────────────────┤ │
│  │  🥇 João Silva   │ R$ 12.500     │  │  Tijucas     85% 🟡     │ │
│  │  🥈 Maria Santos │ R$ 11.200     │  │  Outlet     105% 🟢     │ │
│  │  🥉 Pedro Costa  │ R$ 10.800     │  │  Centro      72% 🔴     │ │
│  │                                   │  │                         │ │
│  │  4. Ana Lima    │ R$  9.500     │  │  [Ver Detalhes]          │ │
│  │  5. Carlos Reis │ R$  8.900     │  │                         │ │
│  └──────────────────────────────────┘  └──────────────────────────┘ │
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │ 📈 PROJEÇÃO DE FECHAMENTO (Forecast)                 │            │
│  ├─────────────────────────────────────────────────────┤            │
│  │                                                      │            │
│  │  Loja       │ Atual    │ Meta      │ Projeção │ St. │            │
│  │  Tijucas    │ R$ 28k   │ R$ 52k    │ R$ 58k   │ 🟢  │            │
│  │  Outlet     │ R$ 39k   │ R$ 60k    │ R$ 78k   │ 🟢  │            │
│  │  Centro     │ R$ 18k   │ R$ 44k    │ R$ 36k   │ 🔴  │            │
│  │                                                      │            │
│  │  🔮 Se mantiver o ritmo: R$ 172k (106% da meta)      │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Regras de Status do Farol de Lojas

```typescript
function getStoreStatus(achievementRate: number): 'green' | 'yellow' | 'red' {
  if (achievementRate >= 100) return 'green';
  if (achievementRate >= 80) return 'yellow';
  return 'red';
}

// Projeção calculada pelo backend usando "regra de três mental":
// projection = (current_sales / days_elapsed) * days_total
```

---

## 🏢 Dashboard do Admin

O Admin tem a **visão mais completa** com métricas estratégicas e comparativos históricos.

### Endpoint Principal

```http
GET /api/v1/dashboard/admin?month=2026-01
```

*(Mesmo endpoint do Gerente, mas Admin vê TODAS as lojas)*

### Endpoints Exclusivos do Admin

#### 1. Performance Consolidada (Multi-Loja)

```http
GET /api/v1/reports/consolidated?month=2026-01
```

```json
{
  "data": {
    "period": "2026-01",
    
    "stores": [
      {
        "store_id": 1,
        "sales": { "current_amount": 31981.29 },
        "forecast": { "status": "ON_TRACK" }
      }
    ],
    
    // KPI 1: Totais Consolidados
    "consolidated": {
      "total_sales": 95000.00,
      "total_goal": 156000.00,
      "total_achievement_rate": 60.90,
      "total_linear_projection": 198000.00
    }
  }
}
```

#### 2. Estatísticas de Auditoria

```http
GET /api/v1/admin/audit-logs/stats?from=2026-01-01&to=2026-01-09
```

```json
{
  "data": {
    "total_events": 1250,
    "by_event_type": {
      "cash_closing_approved": 45,
      "cash_closing_rejected": 3,
      "sale_created": 890,
      "user_login": 312
    },
    "by_user": [...],
    "by_store": [...]
  }
}
```

#### 3. Extrato de Bônus (Passivo Total)

```http
GET /api/v1/finance/bonus?store_id=1&from=2026-01-01&to=2026-01-31
```

```json
{
  "data": {
    "period": { "from": "2026-01-01", "to": "2026-01-31" },
    "summary": {
      "total_bonus": 3450.00,
      "approved_bonus": 2800.00,
      "pending_bonus": 650.00
    },
    "entries": [...]
  }
}
```

#### 4. Extrato de Comissão (Passivo Total)

```http
GET /api/v1/finance/commission?store_id=1&month=2026-01
```

```json
{
  "data": {
    "month": "2026-01",
    "summary": {
      "total_sales": 125000.00,
      "total_commission": 4500.00,
      "by_tier": {
        "2%": { "count": 5, "value": 1200.00 },
        "3%": { "count": 8, "value": 2400.00 },
        "4%": { "count": 2, "value": 900.00 }
      }
    },
    "sellers": [...]
  }
}
```

### Componentes Sugeridos para Admin

```
┌─────────────────────────────────────────────────────────────────────┐
│                    DASHBOARD DO ADMIN                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐     │
│  │ 💰 VENDAS  │  │ 📈 YoY     │  │ 💵 PASSIVO │  │ 👥 META %  │     │
│  │            │  │            │  │ COMISSÕES  │  │            │     │
│  │ R$ 95.000  │  │  +18.6%    │  │ R$ 7.950   │  │   61%      │     │
│  │   rede     │  │ vs 2025    │  │   a pagar  │  │  AT_RISK   │     │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘     │
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │ 📊 CRESCIMENTO YoY (Year-over-Year)                  │            │
│  ├─────────────────────────────────────────────────────┤            │
│  │                                                      │            │
│  │  Gráfico de linha: Jan/25 vs Jan/26                  │            │
│  │                                                      │            │
│  │     Jan    Fev    Mar    Abr    Mai    Jun          │            │
│  │  2025: ─────────────────────────────────             │            │
│  │  2026: ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━             │            │
│  │                                                      │            │
│  │  Média YoY: +12.3%                                   │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
│  ┌───────────────────────┐  ┌───────────────────────┐               │
│  │ 🕐 ANÁLISE DE TURNOS  │  │ 💵 PASSIVO DE COMISSÃO│               │
│  ├───────────────────────│  ├───────────────────────┤               │
│  │                       │  │                        │               │
│  │ Tijucas               │  │ Comissões: R$ 4.500   │               │
│  │ T1: 62% | T2: 38%     │  │ Bônus:     R$ 3.450   │               │
│  │                       │  │ ─────────────────────  │               │
│  │ Outlet                │  │ Total:     R$ 7.950   │               │
│  │ T1: 45% | T2: 55%     │  │                        │               │
│  │                       │  │ [Exportar Relatório]   │               │
│  └───────────────────────┘  └───────────────────────┘               │
│                                                                      │
│  ┌─────────────────────────────────────────────────────┐            │
│  │ 🎯 MAPA DE DISTRIBUIÇÃO DE METAS                     │            │
│  ├─────────────────────────────────────────────────────┤            │
│  │                                                      │            │
│  │  Tijucas  ████████████░░░  52k (33%)                │            │
│  │  Outlet   ██████████████░  60k (38%)                │            │
│  │  Centro   ████████░░░░░░░  44k (29%)                │            │
│  │           ─────────────────────────────              │            │
│  │           Meta Total: R$ 156.000                     │            │
│  │                                                      │            │
│  └─────────────────────────────────────────────────────┘            │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔗 Endpoints Complementares

### Tabela de Todos os Endpoints Disponíveis

| Endpoint | Método | Roles | Descrição |
|----------|--------|-------|-----------|
| `/dashboard/vendedor` | GET | todos | Dashboard do vendedor |
| `/dashboard/conferente` | GET | conferente+ | Dashboard do conferente |
| `/dashboard/admin` | GET | gerente+ | Dashboard admin/gerente |
| `/reports/ranking` | GET | todos | Ranking de vendedores |
| `/reports/store-performance` | GET | gerente+ | Performance da loja |
| `/reports/consolidated` | GET | gerente+ | Performance multi-loja |
| `/reports/cash-integrity` | GET | conferente+ | Integridade de caixa |
| `/cash/shifts/pending` | GET | conferente+ | Turnos pendentes |
| `/cash/shifts/divergent` | GET | conferente+ | Turnos com divergência |
| `/finance/bonus` | GET | gerente+ | Extrato de bônus |
| `/finance/bonus/seller/{id}` | GET | vendedor/gerente+ | Bônus do vendedor |
| `/finance/commission` | GET | gerente+ | Extrato de comissão |
| `/finance/commission/seller/{id}` | GET | vendedor/gerente+ | Comissão do vendedor |
| `/finance/commission/projection/{id}` | GET | vendedor/gerente+ | Projeção de comissão |
| `/admin/audit-logs/stats` | GET | admin | Estatísticas de auditoria |

---

## 📐 TypeScript Schemas

```typescript
// types/dashboard.types.ts

// ============================================
// COMMON TYPES
// ============================================

type ShiftCode = 'M' | 'T' | 'N';
type PaceStatus = 'AHEAD' | 'ON_TRACK' | 'BEHIND';
type ForecastStatus = 'ON_TRACK' | 'AT_RISK' | 'BEHIND';
type StoreStatusColor = 'green' | 'yellow' | 'red';
type ClosingStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

// ============================================
// VENDEDOR DASHBOARD
// ============================================

interface SalesCount {
  count: number;
  total: number;
}

interface BonusGamification {
  current_amount: number;
  next_bonus_goal: number | null;
  gap_to_bonus: number | null;
  next_bonus_value: number | null;
  current_bonus_earned: number;
  message: string;
}

interface MonthlyCommission {
  month: string;
  sales_mtd: number;
  goal_amount: number;
  achievement_rate: number;
  days_elapsed: number;
  days_total: number;
  current_tier: number;
  current_commission_value: number;
  next_tier: number | null;
  next_tier_goal: number | null;
  next_tier_goal_percent: number | null;
  gap_to_next_tier: number;
  projected_sales: number;
  projected_achievement: number;
  projected_tier: number;
  potential_commission: number;
}

interface DailyPace {
  today_sales: number;
  average_daily_sales: number;
  today_vs_average: number;
  days_worked_this_month: number;
  status: PaceStatus;
}

interface MyShift {
  id: number;
  date: string;
  shift_code: ShiftCode;
  status: string;
  cash_closing: {
    id: number;
    status: ClosingStatus;
  } | null;
}

interface VendedorDashboardData {
  date: string;
  my_sales: SalesCount;
  store_sales: SalesCount;
  bonus_gamification: BonusGamification;
  monthly_commission: MonthlyCommission;
  daily_pace: DailyPace;
  my_shifts: MyShift[];
}

// ============================================
// CONFERENTE DASHBOARD
// ============================================

interface PendingClosing {
  id: number;
  status: ClosingStatus;
  cash_shift: {
    id: number;
    date: string;
    shift_code: ShiftCode;
    seller: {
      id: number;
      name: string;
    };
  };
}

interface TopSeller {
  seller_id: number;
  name: string;
  total: number;
}

interface ConferenteDashboardData {
  date: string;
  pending_closings: PendingClosing[];
  pending_count: number;
  store_sales: SalesCount;
  shifts_today: Record<string, number>;
  top_sellers: TopSeller[];
}

// ============================================
// ADMIN/GERENTE DASHBOARD
// ============================================

interface StoreSales {
  store_id: number;
  store_name: string;
  count: number;
  total: number;
}

interface TopSellerWithCount extends TopSeller {
  count: number;
}

interface AdminDashboardData {
  month: string;
  total_sales: SalesCount;
  sales_by_store: StoreSales[];
  closings_summary: Record<ClosingStatus, number>;
  top_sellers: TopSellerWithCount[];
}

// ============================================
// REPORTS
// ============================================

interface StorePerformanceData {
  store_id: number;
  period: string;
  days_elapsed: number;
  days_total: number;
  sales: {
    current_amount: number;
    goal_amount: number;
    achievement_rate: number;
    remaining_to_goal: number;
  };
  comparison: {
    same_period_last_year: number;
    total_last_year_month: number;
    yoy_growth: number;
  };
  forecast: {
    linear_projection: number;
    trend_projection: number;
    status: ForecastStatus;
  };
}

interface CashIntegrityData {
  store_id: number;
  period: string;
  cash_integrity: {
    total_system_value: number;
    total_real_value: number;
    total_divergence: number;
    cash_break_percentage: number;
    status: StoreStatusColor;
  };
  divergence_analysis: {
    total_lines_with_divergence: number;
    justified_count: number;
    unjustified_count: number;
    justified_rate: number;
  };
  workflow_status: {
    total_shifts: number;
    closed_count: number;
    pending_approval: number;
    completion_rate: number;
  };
  alerts: Array<{
    type: 'INFO' | 'WARNING' | 'CRITICAL';
    code: string;
    message: string;
  }>;
}

interface RankingEntry {
  position: number;
  seller: {
    id: number;
    name: string;
    avatar_url: string | null;
    store_name: string;
  };
  total_sold: number;
  goal: number;
  achievement_rate: number;
  bonus_accumulated: number;
}

interface RankingData {
  period: string;
  podium: RankingEntry[];
  ranking: RankingEntry[];
  stats: {
    total_sellers: number;
    above_goal: number;
    average_achievement: number;
  };
}

// ============================================
// API RESPONSE WRAPPER
// ============================================

interface ApiResponse<T> {
  data: T;
  meta: {
    timestamp: string;
    request_id?: string;
  };
}

type VendedorDashboardResponse = ApiResponse<VendedorDashboardData>;
type ConferenteDashboardResponse = ApiResponse<ConferenteDashboardData>;
type AdminDashboardResponse = ApiResponse<AdminDashboardData>;
type StorePerformanceResponse = ApiResponse<StorePerformanceData>;
type CashIntegrityResponse = ApiResponse<CashIntegrityData>;
type RankingResponse = ApiResponse<RankingData>;
```

---

## 🎨 Sugestões de Componentes UI

### Componentes por Tipo de KPI

| KPI | Componente | Biblioteca Sugerida |
|-----|------------|---------------------|
| Odômetro/Velocímetro | `RadialProgress` | react-circular-progressbar |
| Barra de Progresso | `Progress` | shadcn/ui |
| Cards de Stat | `Card` + `Stat` | shadcn/ui |
| Gráfico YoY | `LineChart` | recharts |
| Ranking | `Table` | shadcn/ui + tanstack-table |
| Farol de Lojas | `Badge` colorido | shadcn/ui |
| Alertas | `Alert` | shadcn/ui |
| Timeline | `Timeline` | custom |

### Exemplo de Uso com shadcn/ui

```typescript
// components/dashboard/StatCard.tsx
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface StatCardProps {
  title: string;
  value: string | number;
  description?: string;
  trend?: {
    value: number;
    direction: 'up' | 'down' | 'stable';
  };
  status?: 'success' | 'warning' | 'danger';
  icon?: React.ReactNode;
}

export function StatCard({ 
  title, 
  value, 
  description, 
  trend, 
  status,
  icon 
}: StatCardProps) {
  return (
    <Card className={cn(
      status === 'success' && 'border-green-500/50',
      status === 'warning' && 'border-yellow-500/50',
      status === 'danger' && 'border-red-500/50'
    )}>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">
          {title}
        </CardTitle>
        {icon}
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {description && (
          <p className="text-xs text-muted-foreground">{description}</p>
        )}
        {trend && (
          <div className={cn(
            "flex items-center text-xs mt-1",
            trend.direction === 'up' && 'text-green-600',
            trend.direction === 'down' && 'text-red-600'
          )}>
            {trend.direction === 'up' ? '↑' : '↓'} {trend.value}%
          </div>
        )}
      </CardContent>
    </Card>
  );
}
```

---

## 🔒 Segurança e Boas Práticas

### 1. Validação de Acesso

O backend já valida automaticamente:

- ✅ **Store Access**: Usuário só vê lojas às quais tem acesso
- ✅ **Role-based**: Endpoints verificam role mínimo necessário
- ✅ **Data Isolation**: Vendedor só vê seus próprios dados

### 2. Cache Strategy (TanStack Query)

```typescript
const queryOptions = {
  // Vendedor - Atualização frequente
  vendedor: {
    staleTime: 1000 * 60,      // 1 min
    refetchInterval: 1000 * 60 * 2, // Polling 2 min
  },
  
  // Conferente - Atualização moderada
  conferente: {
    staleTime: 1000 * 60 * 2,  // 2 min
    refetchInterval: 1000 * 60 * 3, // Polling 3 min
  },
  
  // Admin - Dados mais estáveis
  admin: {
    staleTime: 1000 * 60 * 5,  // 5 min
    refetchInterval: false,    // Manual refresh
  },
  
  // Reports históricos - Cache longo
  reports: {
    staleTime: 1000 * 60 * 30, // 30 min
    cacheTime: 1000 * 60 * 60, // 1 hora
  },
};
```

### 3. Error Handling

```typescript
// hooks/useDashboard.ts
export function useDashboard(role: string, params: DashboardParams) {
  return useQuery({
    queryKey: ['dashboard', role, params],
    queryFn: () => fetchDashboard(role, params),
    retry: (failureCount, error) => {
      // Não retry em:
      // - 401: Token expirado
      // - 403: Sem permissão
      // - 404: Recurso não encontrado
      if (error instanceof ApiError) {
        if ([401, 403, 404].includes(error.status)) {
          return false;
        }
      }
      return failureCount < 3;
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 403) {
        toast.error('Você não tem permissão para acessar este dashboard');
      }
    },
  });
}
```

### 4. Refresh Manual vs Polling

| Cenário | Estratégia | Justificativa |
|---------|------------|---------------|
| Vendedor no turno | Polling 2min | Precisa ver vendas em tempo quase-real |
| Conferente | Polling 3min | Precisa ver novos envios rapidamente |
| Gerente | Refresh manual | Dados mudam menos frequentemente |
| Admin (relatórios) | Cache longo | Dados históricos são estáveis |

### 5. Otimização de Requests

```typescript
// Prefetch dados relacionados quando navegar para dashboard
const router = useRouter();

// Ao entrar no dashboard
useEffect(() => {
  if (user?.role === 'vendedor') {
    // Prefetch dados de comissão enquanto carrega dashboard
    queryClient.prefetchQuery({
      queryKey: ['finance', 'commission', 'projection', user.id],
      queryFn: () => fetchCommissionProjection(user.id),
    });
  }
}, [user]);
```

---

## 📝 Resumo das Respostas às Perguntas

| # | Pergunta | Resposta Resumida |
|---|----------|-------------------|
| 1 | Endpoint único ou múltiplos? | **Múltiplos** - Um por role |
| 2 | Filtros disponíveis | `store_id`, `date`, `month` |
| 3 | Cálculo de trends | **Backend** - Já calcula YoY, pace, etc. |
| 4 | Status do farol | **Backend** - 80%=amarelo, 100%=verde |
| 5 | Top N vendedores | **Configurável** - `?limit=N` |
| 6 | Divergências | Todas + filtros para pendentes |
| 7 | Dashboards por role | **Endpoints separados** |
| 8 | Refresh automático | **Polling** - Intervalos por role |

---

## 🚀 Próximos Passos Sugeridos

1. **Criar hooks para cada dashboard** com query keys bem definidas
2. **Implementar componentes reutilizáveis** (StatCard, ProgressRing, etc.)
3. **Configurar refetch intervals** por role
4. **Implementar tratamento de erros** consistente
5. **Testar com dados reais** em staging

---

> **Dúvidas?** Abra uma issue ou entre em contato com o time de backend.
