# 🎰 Regras de Prêmios - Documentação Completa para Frontend Admin

> **Para:** Time Frontend Admin  
> **Data:** 19/01/2026  
> **Versão:** 2.0

---

## 📚 Índice

1. [Glossário (para Tooltips)](#glossário-para-tooltips)
2. [Schemas TypeScript](#schemas-typescript)
3. [CRUD de Regras](#crud-de-regras)
4. [Estado de Elegibilidade](#estado-de-elegibilidade)
5. [Respostas de Erro](#respostas-de-erro)
6. [Exemplos de UI](#exemplos-de-ui)

---

## 📖 Glossário (para Tooltips)

Use estes textos nos tooltips da interface para ajudar o usuário:

| Campo | Tooltip (texto amigável) |
|-------|--------------------------|
| **min_gap_spins** | "Número mínimo de jogadas que devem acontecer antes deste prêmio poder sair novamente. Ex: 10 = depois de sair, precisa de 10 jogadas para poder sair de novo." |
| **cooldown_seconds** | "Tempo mínimo em segundos entre cada vez que este prêmio sai. Ex: 300 = 5 minutos de espera após sair." |
| **cooldown_scope** | "Define se o controle é por TV ou geral. 'Por tela' = cada TV tem seu próprio contador. 'Global' = todas as TVs compartilham o mesmo contador." |
| **max_per_hour** | "Quantidade máxima de vezes que este prêmio pode sair por hora. Deixe vazio para sem limite." |
| **max_per_day** | "Quantidade máxima de vezes que este prêmio pode sair por dia. Deixe vazio para sem limite." |
| **pacing_enabled** | "Se ativado, o sistema distribui os prêmios ao longo da campanha para não acabar o estoque no primeiro dia." |
| **pacing_buffer** | "Margem acima do ritmo ideal. 1.30 = permite gastar até 30% acima do ritmo ideal antes de frear." |
| **priority** | "Prioridade para desempate quando há conflito. Quanto menor o número, maior a prioridade." |
| **is_eligible** | "Indica se o prêmio pode sair na próxima jogada. Se verde ✅, pode sair. Se vermelho 🚫, está bloqueado." |
| **spins_until_eligible** | "Quantas jogadas faltam para este prêmio poder sair novamente." |
| **seconds_until_eligible** | "Quantos segundos faltam para este prêmio poder sair novamente." |
| **awarded_count_hour** | "Quantas vezes este prêmio saiu na última hora." |
| **awarded_count_day** | "Quantas vezes este prêmio saiu hoje." |
| **awarded_count_total** | "Total de vezes que este prêmio saiu desde o início da campanha." |

---

## 📋 Schemas TypeScript

### PrizeRule (Regra de Prêmio)

```typescript
/**
 * Regra de controle de prêmio.
 * Define quando e com que frequência um prêmio pode sair.
 */
interface PrizeRule {
  /** ID único da regra */
  id: number;
  
  /** ID da campanha */
  campaign_id: number;
  
  /** ID do prêmio */
  prize_id: number;
  
  /** Dados do prêmio associado */
  prize: {
    prize_key: string;
    name: string;
    type: 'nothing' | 'try_again' | 'coupon' | 'voucher' | 'physical' | 'experience';
    icon: string | null;
  };
  
  // ========== COOLDOWN ==========
  
  /** 
   * Mínimo de jogadas sem sair.
   * 0 = sem cooldown por jogadas.
   * @example 10 = depois de sair, precisa de 10 jogadas para poder sair novamente
   */
  min_gap_spins: number;
  
  /** 
   * Segundos mínimos entre saídas.
   * 0 = sem cooldown por tempo.
   * @example 300 = 5 minutos entre cada vez que sai
   */
  cooldown_seconds: number;
  
  /**
   * Escopo do cooldown.
   * 'screen' = cada TV tem contador separado
   * 'campaign' = todas as TVs compartilham contador
   */
  cooldown_scope: 'screen' | 'campaign';
  
  // ========== LIMITES ==========
  
  /** 
   * Máximo de vezes por hora.
   * null = sem limite.
   */
  max_per_hour: number | null;
  
  /** 
   * Máximo de vezes por dia.
   * null = sem limite.
   */
  max_per_day: number | null;
  
  // ========== PACING ==========
  
  /** Se ativo, distribui prêmios ao longo da campanha */
  pacing_enabled: boolean;
  
  /** 
   * Buffer acima do ritmo ideal.
   * @example 1.30 = permite 30% acima do ritmo
   */
  pacing_buffer: number;
  
  // ========== OUTROS ==========
  
  /** Prioridade (menor = mais importante) */
  priority: number;
  
  /** Se a regra está ativa */
  active: boolean;
  
  /** Resumo formatado para exibição */
  summary: {
    cooldown: string;   // "10 spins + 5 min"
    limits: string;     // "5/hora, 20/dia"
    scope: string;      // "Global" | "Por tela"
    pacing: string;     // "Ativo (1.3x)" | "Desativado"
  };
  
  created_at: string;   // ISO 8601
  updated_at: string;   // ISO 8601
}
```

### PrizeState (Estado de Elegibilidade)

```typescript
/**
 * Estado atual de um prêmio.
 * Mostra se pode sair e por que não pode.
 */
interface PrizeState {
  /** Chave única do prêmio */
  prize_key: string;
  
  /** Nome do prêmio */
  prize_name: string;
  
  /** Label no segmento da roleta */
  segment_label: string;
  
  /** Peso de probabilidade (quanto maior, mais chance) */
  probability_weight: number;
  
  /** 
   * Se o prêmio pode sair na próxima jogada.
   * true = elegível
   * false = bloqueado
   */
  is_eligible: boolean;
  
  /**
   * Motivo do bloqueio (se não elegível).
   * Exemplos:
   * - "Cooldown: faltam 3 spins"
   * - "Limite hora: 5/5"
   * - "Sem estoque"
   */
  reason: string | null;
  
  /** Resumo da regra (se existir) */
  rule: {
    cooldown: string;
    limits: string;
    scope: string;
    pacing: string;
  } | null;
  
  /** Estado atual */
  state: {
    /** Última vez que saiu */
    last_awarded_at: string | null;
    
    /** Sequência do último spin que saiu */
    last_awarded_spin_seq: number | null;
    
    /** Vezes que saiu nesta hora */
    awarded_count_hour: number;
    
    /** Vezes que saiu hoje */
    awarded_count_day: number;
    
    /** Total de vezes que saiu */
    awarded_count_total: number;
    
    /** Jogadas faltando para ficar elegível */
    spins_until_eligible: number;
    
    /** Segundos faltando para ficar elegível */
    seconds_until_eligible: number;
    
    /** Data/hora que ficará elegível */
    next_eligible_at: string | null;
  };
  
  /** Dados do estoque */
  inventory: {
    total: number;           // Quantidade total
    remaining: number;       // Restante
    daily_limit: number | null;    // Limite diário
    daily_remaining: number | null; // Restante hoje
  } | null;
}
```

### CreatePrizeRuleRequest (Criar Regra)

```typescript
/**
 * Payload para criar uma nova regra.
 */
interface CreatePrizeRuleRequest {
  /** [OBRIGATÓRIO] ID do prêmio */
  prize_id: number;
  
  /** [OPCIONAL] Mínimo de jogadas sem sair. Padrão: 0 */
  min_gap_spins?: number;
  
  /** [OPCIONAL] Segundos mínimos entre saídas. Padrão: 0 */
  cooldown_seconds?: number;
  
  /** [OPCIONAL] Máximo por hora. Padrão: null (sem limite) */
  max_per_hour?: number | null;
  
  /** [OPCIONAL] Máximo por dia. Padrão: null (sem limite) */
  max_per_day?: number | null;
  
  /** [OPCIONAL] Escopo do cooldown. Padrão: 'campaign' */
  cooldown_scope?: 'screen' | 'campaign';
  
  /** [OPCIONAL] Ativar pacing. Padrão: false */
  pacing_enabled?: boolean;
  
  /** [OPCIONAL] Buffer do pacing. Padrão: 1.20 */
  pacing_buffer?: number;
  
  /** [OPCIONAL] Prioridade. Padrão: 100 */
  priority?: number;
  
  /** [OPCIONAL] Se está ativa. Padrão: true */
  active?: boolean;
}
```

### UpdatePrizeRuleRequest (Atualizar Regra)

```typescript
/**
 * Payload para atualizar regra existente.
 * Todos os campos são opcionais.
 */
interface UpdatePrizeRuleRequest {
  min_gap_spins?: number;
  cooldown_seconds?: number;
  max_per_hour?: number | null;
  max_per_day?: number | null;
  cooldown_scope?: 'screen' | 'campaign';
  pacing_enabled?: boolean;
  pacing_buffer?: number;
  priority?: number;
  active?: boolean;
}
```

---

## 📡 CRUD de Regras

### 1. Listar Regras

**Endpoint:** `GET /api/v1/admin/wheel/campaigns/{campaign_key}/prize-rules`

**Permissão:** `wheel.campaigns.view`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "campaign_id": 5,
      "prize_id": 10,
      "prize": {
        "prize_key": "prize_pelicula",
        "name": "Película Premium",
        "type": "physical",
        "icon": "🎁"
      },
      "min_gap_spins": 10,
      "cooldown_seconds": 300,
      "max_per_hour": 5,
      "max_per_day": 20,
      "cooldown_scope": "campaign",
      "pacing_enabled": true,
      "pacing_buffer": 1.30,
      "priority": 50,
      "active": true,
      "summary": {
        "cooldown": "10 spins + 5 min",
        "limits": "5/hora, 20/dia",
        "scope": "Global",
        "pacing": "Ativo (1.3x)"
      },
      "created_at": "2026-01-19T10:00:00Z",
      "updated_at": "2026-01-19T10:00:00Z"
    }
  ]
}
```

---

### 2. Criar Regra

**Endpoint:** `POST /api/v1/admin/wheel/campaigns/{campaign_key}/prize-rules`

**Permissão:** `wheel.campaigns.manage`

**Request:**
```json
{
  "prize_id": 10,
  "min_gap_spins": 10,
  "cooldown_seconds": 300,
  "max_per_hour": 5,
  "max_per_day": 20,
  "cooldown_scope": "campaign",
  "pacing_enabled": true,
  "pacing_buffer": 1.30,
  "priority": 50,
  "active": true
}
```

**Response 201 (Sucesso):**
```json
{
  "success": true,
  "message": "Regra criada com sucesso.",
  "data": {
    "id": 1,
    "campaign_id": 5,
    "prize_id": 10,
    "prize": { ... },
    "min_gap_spins": 10,
    ...
  }
}
```

**Response 409 (Conflito - já existe):**
```json
{
  "success": false,
  "message": "Já existe uma regra para este prêmio nesta campanha.",
  "code": "RULE_EXISTS"
}
```

**Response 422 (Validação):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "prize_id": ["The prize_id field is required."],
    "min_gap_spins": ["The min_gap_spins must be at least 0."]
  }
}
```

---

### 3. Ver Regra

**Endpoint:** `GET /api/v1/admin/wheel/prize-rules/{rule_id}`

**Permissão:** `wheel.campaigns.view`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "rule": {
      "id": 1,
      "campaign_id": 5,
      "prize_id": 10,
      "prize": { ... },
      "min_gap_spins": 10,
      "cooldown_seconds": 300,
      ...
    },
    "state": {
      "last_awarded_at": "2026-01-19T10:15:00Z",
      "last_awarded_spin_seq": 45,
      "awarded_count_hour": 3,
      "awarded_count_day": 12,
      "awarded_count_total": 87,
      "spins_until_eligible": 3,
      "seconds_until_eligible": 120,
      "next_eligible_at": "2026-01-19T10:17:00Z"
    }
  }
}
```

**Response 404:**
```json
{
  "message": "No query results for model [WheelPrizeRule] 999"
}
```

---

### 4. Atualizar Regra

**Endpoint:** `PUT /api/v1/admin/wheel/prize-rules/{rule_id}`

**Permissão:** `wheel.campaigns.manage`

**Request:**
```json
{
  "min_gap_spins": 15,
  "max_per_day": 30
}
```

**Response 200:**
```json
{
  "success": true,
  "message": "Regra atualizada com sucesso.",
  "data": {
    "id": 1,
    "min_gap_spins": 15,
    "max_per_day": 30,
    ...
  }
}
```

---

### 5. Remover Regra

**Endpoint:** `DELETE /api/v1/admin/wheel/prize-rules/{rule_id}`

**Permissão:** `wheel.campaigns.manage`

**Response 200:**
```json
{
  "success": true,
  "message": "Regra removida com sucesso."
}
```

---

### 6. Bulk Update

**Endpoint:** `PUT /api/v1/admin/wheel/campaigns/{campaign_key}/prize-rules/bulk`

**Permissão:** `wheel.campaigns.manage`

**Request:**
```json
{
  "rules": [
    {
      "prize_id": 10,
      "min_gap_spins": 10,
      "max_per_day": 20
    },
    {
      "prize_id": 11,
      "min_gap_spins": 5,
      "max_per_day": 50
    },
    {
      "prize_id": 12,
      "cooldown_seconds": 600
    }
  ]
}
```

**Response 200:**
```json
{
  "success": true,
  "message": "Regras atualizadas: 2 criadas, 1 atualizadas.",
  "data": {
    "created": 2,
    "updated": 1
  }
}
```

---

### 7. Reset Cooldown

**Endpoint:** `POST /api/v1/admin/wheel/prize-rules/{rule_id}/reset-cooldown`

**Permissão:** `wheel.campaigns.manage`

**Request (opcional):**
```json
{
  "scope_id": 5
}
```
*Onde `scope_id` é o `screen_id` para reset específico de uma tela, ou `null` para reset global.*

**Response 200:**
```json
{
  "success": true,
  "message": "Cooldown resetado com sucesso."
}
```

---

## 📊 Estado de Elegibilidade

### Ver Estado de Todos os Prêmios

**Endpoint:** `GET /api/v1/admin/wheel/campaigns/{campaign_key}/prize-state`

**Query Params:**
| Param | Tipo | Descrição |
|-------|------|-----------|
| `screen_id` | number | (Opcional) Filtrar por tela específica |
| `spin_seq` | number | (Opcional) Sequência atual de spin |

**Permissão:** `wheel.campaigns.view`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "prize_key": "prize_pelicula",
      "prize_name": "Película Premium",
      "segment_label": "🎁 Película",
      "probability_weight": 5,
      "is_eligible": false,
      "reason": "Cooldown: faltam 3 spins",
      "rule": {
        "cooldown": "10 spins + 5 min",
        "limits": "5/hora, 20/dia",
        "scope": "Global",
        "pacing": "Ativo (1.3x)"
      },
      "state": {
        "last_awarded_at": "2026-01-19T10:15:00Z",
        "last_awarded_spin_seq": 45,
        "awarded_count_hour": 3,
        "awarded_count_day": 12,
        "awarded_count_total": 87,
        "spins_until_eligible": 3,
        "seconds_until_eligible": 120,
        "next_eligible_at": "2026-01-19T10:17:00Z"
      },
      "inventory": {
        "total": 100,
        "remaining": 13,
        "daily_limit": 20,
        "daily_remaining": 8
      }
    },
    {
      "prize_key": "prize_cupom20",
      "prize_name": "Cupom 20% OFF",
      "segment_label": "💰 20% OFF",
      "probability_weight": 8,
      "is_eligible": true,
      "reason": null,
      "rule": {
        "cooldown": "5 spins",
        "limits": "10/hora, 50/dia",
        "scope": "Global",
        "pacing": "Desativado"
      },
      "state": {
        "last_awarded_at": "2026-01-19T10:10:00Z",
        "awarded_count_hour": 2,
        "awarded_count_day": 8,
        "awarded_count_total": 45,
        "spins_until_eligible": 0,
        "seconds_until_eligible": 0,
        "next_eligible_at": null
      },
      "inventory": {
        "total": 500,
        "remaining": 455,
        "daily_limit": 100,
        "daily_remaining": 92
      }
    },
    {
      "prize_key": "prize_nothing",
      "prize_name": "Tente Novamente",
      "segment_label": "❌ Tente Novamente",
      "probability_weight": 60,
      "is_eligible": true,
      "reason": null,
      "rule": null,
      "state": {
        "awarded_count_hour": 25,
        "awarded_count_day": 180,
        "awarded_count_total": 1250,
        "spins_until_eligible": 0,
        "seconds_until_eligible": 0
      },
      "inventory": null
    }
  ],
  "meta": {
    "campaign_key": "camp_verao2026",
    "screen_id": null,
    "current_spin_seq": 48,
    "timestamp": "2026-01-19T10:30:00Z"
  }
}
```

---

## ❌ Respostas de Erro

### Erros Comuns

| Status | Código | Mensagem | Quando Ocorre |
|--------|--------|----------|---------------|
| 401 | - | "Unauthenticated." | Token inválido ou ausente |
| 403 | - | "This action is unauthorized." | Sem permissão |
| 404 | - | "No query results for model..." | Regra/Campanha não encontrada |
| 409 | RULE_EXISTS | "Já existe uma regra para este prêmio nesta campanha." | Tentou criar regra duplicada |
| 422 | - | "The given data was invalid." | Validação falhou |

### Formato de Erro de Validação

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "campo_1": ["Mensagem de erro 1", "Mensagem de erro 2"],
    "campo_2": ["Mensagem de erro"]
  }
}
```

---

## 🎨 Exemplos de UI

### Tabela de Regras

```
┌────────────────┬─────────┬────────────────┬─────────────┬──────────┐
│ Prêmio         │ Prob.   │ Cooldown       │ Limites     │ Status   │
├────────────────┼─────────┼────────────────┼─────────────┼──────────┤
│ 🎁 Película    │ 5%      │ 10 spins + 5m  │ 5/h, 20/d   │ ⏳ -3    │
│ 💰 Cupom 20%   │ 8%      │ 5 spins        │ 10/h, 50/d  │ ✅       │
│ 🏷️ Cupom 10%   │ 15%     │ —              │ —           │ ✅       │
│ ❌ Tente Nova  │ 60%     │ —              │ —           │ ✅       │
└────────────────┴─────────┴────────────────┴─────────────┴──────────┘

Legenda:
✅ Elegível (pode sair)
⏳ -3 = Cooldown (faltam 3 spins)
🚫 Limite atingido
📦 Sem estoque
```

### Formulário de Edição

```
┌─────────────────────────────────────────────────────────────────┐
│ ⚙️ Configurar Regra: Película Premium                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ 🔄 COOLDOWN  ℹ️                                                  │
│                                                                 │
│   Mínimo de jogadas:   [    10    ]  ℹ️                         │
│   Tempo mínimo:        [   300    ] segundos  (= 5 min)         │
│                                                                 │
│   Escopo:  ○ Por tela (cada TV separada)                        │
│            ● Global (todas as TVs juntas)  ℹ️                   │
│                                                                 │
│ 📊 LIMITES  ℹ️                                                   │
│                                                                 │
│   Máximo por hora:     [     5    ] vezes  (vazio = sem limite) │
│   Máximo por dia:      [    20    ] vezes  (vazio = sem limite) │
│                                                                 │
│ 🎯 PACING  ℹ️                                                    │
│                                                                 │
│   [x] Distribuir ao longo da campanha                           │
│                                                                 │
│   Buffer:              [   130    ] %  ℹ️                       │
│   (Permite gastar até 30% acima do ritmo ideal)                 │
│                                                                 │
│ ⚡ OUTROS                                                        │
│                                                                 │
│   Prioridade:          [    50    ]  ℹ️                         │
│   [x] Regra ativa                                               │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Cancelar]    [💾 Salvar]               │
└─────────────────────────────────────────────────────────────────┘
```

### Card de Status em Tempo Real

```
┌───────────────────────────────────────┐
│ 🎁 Película Premium                   │
├───────────────────────────────────────┤
│ Probabilidade: ████████░░ 5%          │
│ Estoque: 13/100 (13%)                 │
│ Hoje: 12/20                           │
├───────────────────────────────────────┤
│ Status: ⏳ COOLDOWN                    │
│                                       │
│ ⏱️ Faltam 3 jogadas                   │
│ 🕐 Liberado em ~2 min                 │
├───────────────────────────────────────┤
│ Contadores:                           │
│ • Esta hora: 3/5                      │
│ • Hoje: 12/20                         │
│ • Total: 87                           │
├───────────────────────────────────────┤
│           [🔄 Reset Cooldown]         │
└───────────────────────────────────────┘
```

---

## 🔗 React Query - Hooks Completos

```typescript
// hooks/use-prize-rules.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

const KEYS = {
  rules: (campaignKey: string) => ['wheel', 'campaigns', campaignKey, 'prize-rules'],
  state: (campaignKey: string, screenId?: number) => 
    ['wheel', 'campaigns', campaignKey, 'prize-state', screenId],
  rule: (id: number) => ['wheel', 'prize-rules', id],
};

// ========== QUERIES ==========

/** Listar todas as regras de uma campanha */
export function usePrizeRules(campaignKey: string) {
  return useQuery({
    queryKey: KEYS.rules(campaignKey),
    queryFn: async () => {
      const { data } = await api.get(`/admin/wheel/campaigns/${campaignKey}/prize-rules`);
      return data.data as PrizeRule[];
    },
    staleTime: 30_000, // 30 segundos
  });
}

/** Ver estado de elegibilidade de todos os prêmios */
export function usePrizeState(campaignKey: string, options?: {
  screenId?: number;
  refetchInterval?: number;
}) {
  return useQuery({
    queryKey: KEYS.state(campaignKey, options?.screenId),
    queryFn: async () => {
      const { data } = await api.get(`/admin/wheel/campaigns/${campaignKey}/prize-state`, {
        params: { screen_id: options?.screenId },
      });
      return data as { data: PrizeState[]; meta: object };
    },
    refetchInterval: options?.refetchInterval ?? 10_000, // 10 segundos
  });
}

/** Ver regra específica com estado */
export function usePrizeRule(ruleId: number) {
  return useQuery({
    queryKey: KEYS.rule(ruleId),
    queryFn: async () => {
      const { data } = await api.get(`/admin/wheel/prize-rules/${ruleId}`);
      return data.data;
    },
  });
}

// ========== MUTATIONS ==========

/** Criar nova regra */
export function useCreatePrizeRule(campaignKey: string) {
  const qc = useQueryClient();
  
  return useMutation({
    mutationFn: async (payload: CreatePrizeRuleRequest) => {
      const { data } = await api.post(
        `/admin/wheel/campaigns/${campaignKey}/prize-rules`,
        payload
      );
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.rules(campaignKey) });
      qc.invalidateQueries({ queryKey: KEYS.state(campaignKey) });
    },
  });
}

/** Atualizar regra existente */
export function useUpdatePrizeRule(campaignKey: string) {
  const qc = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: UpdatePrizeRuleRequest }) => {
      const { data } = await api.put(`/admin/wheel/prize-rules/${id}`, payload);
      return data;
    },
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: KEYS.rules(campaignKey) });
      qc.invalidateQueries({ queryKey: KEYS.state(campaignKey) });
      qc.invalidateQueries({ queryKey: KEYS.rule(id) });
    },
  });
}

/** Remover regra */
export function useDeletePrizeRule(campaignKey: string) {
  const qc = useQueryClient();
  
  return useMutation({
    mutationFn: async (ruleId: number) => {
      await api.delete(`/admin/wheel/prize-rules/${ruleId}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.rules(campaignKey) });
      qc.invalidateQueries({ queryKey: KEYS.state(campaignKey) });
    },
  });
}

/** Bulk update de regras */
export function useBulkUpdatePrizeRules(campaignKey: string) {
  const qc = useQueryClient();
  
  return useMutation({
    mutationFn: async (rules: CreatePrizeRuleRequest[]) => {
      const { data } = await api.put(
        `/admin/wheel/campaigns/${campaignKey}/prize-rules/bulk`,
        { rules }
      );
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.rules(campaignKey) });
      qc.invalidateQueries({ queryKey: KEYS.state(campaignKey) });
    },
  });
}

/** Reset cooldown */
export function useResetCooldown(campaignKey: string) {
  const qc = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ ruleId, scopeId }: { ruleId: number; scopeId?: number }) => {
      const { data } = await api.post(
        `/admin/wheel/prize-rules/${ruleId}/reset-cooldown`,
        { scope_id: scopeId }
      );
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.state(campaignKey) });
    },
  });
}
```

---

## ✅ Checklist de Implementação Frontend

- [ ] Listar regras da campanha
- [ ] Criar nova regra (modal/drawer)
- [ ] Editar regra existente
- [ ] Remover regra (com confirmação)
- [ ] Ver estado de elegibilidade
- [ ] Auto-refresh do estado (polling)
- [ ] Reset cooldown manual
- [ ] Tooltips com glossário
- [ ] Validação no formulário
- [ ] Bulk update de regras
