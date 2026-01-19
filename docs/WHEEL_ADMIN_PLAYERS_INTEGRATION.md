# 🎛️ Guia de Integração - Admin Players (Dashboard)

> **Para:** Time Frontend Admin/Dashboard  
> **Data:** 19/01/2026  
> **Status:** ✅ Backend pronto

---

## 📊 Visão Geral

O módulo **Players** permite gerenciar os jogadores que participam da roleta. Um **Player** é uma pessoa única identificada pelo WhatsApp, que pode participar de múltiplas sessões em diferentes lojas.

```
Player (pessoa) ─┬─► SessionPlayer (participação 1) ─► Session ─► Screen ─► Store
                 ├─► SessionPlayer (participação 2) ─► Session ─► Screen ─► Store
                 └─► SessionPlayer (participação N) ...
```

---

## 🔌 Endpoints

**Base:** `/api/v1/admin/wheel/players`

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/` | Listar players com filtros |
| GET | `/stats/by-city` | Estatísticas por cidade |
| GET | `/stats/by-store` | Estatísticas por loja |
| GET | `/export` | Exportar dados |
| GET | `/{player_key}` | Detalhes do player |
| PUT | `/{player_key}` | Atualizar dados |
| GET | `/{player_key}/logs` | Logs do player |
| GET | `/{player_key}/spins` | Histórico de giros |

---

## 📋 GET /players - Listagem

### Query Parameters

```typescript
interface PlayersFilters {
  // Busca geral
  search?: string;       // Nome, telefone mascarado, player_key, cidade
  
  // Localização
  city?: string;         // Ex: "Tijucas"
  state?: string;        // Ex: "SC"
  cep?: string;          // Prefixo, ex: "88160"
  
  // Relacionamentos
  store_id?: number;     // Filtrar por loja
  campaign_id?: number;  // Filtrar por campanha
  
  // Flags
  has_address?: boolean;   // Apenas com endereço
  verified_only?: boolean; // Apenas WhatsApp verificado
  has_spins?: boolean;     // Apenas quem jogou
  
  // Período
  date_from?: string;    // "2026-01-01"
  date_to?: string;      // "2026-01-31"
  
  // Ordenação
  sort_by?: 'created_at' | 'full_name' | 'city' | 'state' | 'last_seen_at';
  sort_dir?: 'asc' | 'desc';
  
  // Paginação
  page?: number;
  per_page?: number;     // Max: 100
}
```

### Response

```typescript
interface PlayersResponse {
  success: true;
  data: Player[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  stats: {
    total: number;           // Total de players
    verified: number;        // Com WhatsApp verificado
    with_address: number;    // Com endereço
    cities: number;          // Cidades únicas
  };
  filters_applied: object;   // Filtros ativos
}

interface Player {
  id: number;
  player_key: string;
  full_name: string | null;
  phone_masked: string;
  
  whatsapp_confirmed: boolean;
  whatsapp_confirmed_at: string | null;
  
  address: {
    cep: string | null;
    street: string | null;
    number: string | null;
    complement: string | null;
    neighborhood: string | null;
    city: string | null;
    state: string | null;
    ibge: string | null;
    full: string | null;  // Endereço formatado
  };
  has_address: boolean;
  
  sessions_count: number;
  spins_count: number;
  
  last_session?: {
    session_key: string;
    store: string;
    campaign: string;
    joined_at: string;
  };
  
  last_seen_at: string | null;
  created_at: string;
}
```

### Exemplo React Query

```typescript
// hooks/use-wheel-players.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface UsePlayersParams {
  search?: string;
  city?: string;
  storeId?: number;
  page?: number;
  perPage?: number;
}

export function useWheelPlayers(params: UsePlayersParams) {
  return useQuery({
    queryKey: ['wheel', 'players', params],
    queryFn: () => api.get('/admin/wheel/players', { params }).then(r => r.data),
    staleTime: 30_000, // 30s
  });
}
```

---

## 👤 GET /players/{key} - Detalhes

Retorna dados completos de um jogador incluindo timeline de participações.

```typescript
interface PlayerDetailResponse {
  success: true;
  data: {
    player: Player;
    stats: {
      total_sessions: number;
      total_spins: number;
      prizes_won: number;
      stores_visited: number;
      campaigns_participated: number;
    };
    timeline: {
      session_player_key: string;
      session_key: string;
      campaign: string;
      store: string;
      status: string;
      spins: {
        spin_key: string;
        prize: string;
        code: string | null;
        created_at: string;
      }[];
      joined_at: string;
      left_at: string | null;
    }[];
  };
}
```

---

## 📝 PUT /players/{key} - Atualizar

Permite editar dados do jogador (admin).

```typescript
interface UpdatePlayerRequest {
  full_name?: string;
  cep?: string;
  street?: string;
  number?: string;
  complement?: string;
  neighborhood?: string;
  city?: string;
  state?: string;
}
```

---

## 📜 GET /players/{key}/logs - Logs

Histórico de eventos do jogador.

```typescript
interface PlayerLogsParams {
  type?: string;        // Filtrar por tipo de evento
  date_from?: string;
  date_to?: string;
  per_page?: number;
}

interface LogEntry {
  id: number;
  type: string;         // player_joined, player_verified, spin_started...
  payload: object;
  screen_id: number | null;
  campaign_id: number | null;
  created_at: string;
}
```

---

## 🎰 GET /players/{key}/spins - Giros

Histórico de giros do jogador.

```typescript
interface PlayerSpinsParams {
  campaign_id?: number;
  prize_id?: number;
  winners_only?: boolean;
  per_page?: number;
}

interface SpinEntry {
  spin_key: string;
  campaign: string;
  store: string;
  prize: {
    name: string;
    type: string;
    icon: string;
  };
  prize_code: string | null;
  status: string;
  created_at: string;
}
```

---

## 📈 Estatísticas

### GET /players/stats/by-city

```typescript
interface CityStats {
  city: string;
  state: string;
  players_count: number;
}[]
```

### GET /players/stats/by-store

```typescript
interface StoreStats {
  store_id: number;
  store_name: string;
  unique_players: number;
  total_participations: number;
}[]
```

---

## 🔗 Relacionamentos (Importante!)

### Hierarquia

```
Store (Loja)
  └── Screen (TV/Totem)
        └── Session (QR Code ativo)
              └── SessionPlayer (participação)
                    ├── Player (pessoa)
                    └── Spin (giro)
                          └── Prize (prêmio)
```

### Por que isso importa?

1. **Player pode jogar em múltiplas lojas** - Filtrar por `store_id` busca via `SessionPlayer → Session → Screen → Store`

2. **Player pode participar da mesma campanha em lojas diferentes** - O limite `1_per_campaign` é global para o WhatsApp

3. **Logs são distribuídos** - Use o endpoint `/logs` para consolidar eventos do player

---

## 💡 Boas Práticas

### 1. Cache de Estatísticas

```typescript
// Estatísticas mudam pouco, use staleTime alto
useQuery({
  queryKey: ['wheel', 'players', 'stats', 'by-city'],
  queryFn: () => api.get('/admin/wheel/players/stats/by-city'),
  staleTime: 5 * 60 * 1000, // 5 minutos
});
```

### 2. Debounce na Busca

```typescript
const [search, setSearch] = useState('');
const debouncedSearch = useDebounce(search, 300);

const { data } = useWheelPlayers({ search: debouncedSearch });
```

### 3. Filtros Persistentes na URL

```typescript
// Sincronize filtros com query params
const searchParams = useSearchParams();
const filters = {
  city: searchParams.get('city'),
  storeId: searchParams.get('store_id'),
};
```

### 4. Pré-carregar Detalhes no Hover

```typescript
const queryClient = useQueryClient();

<TableRow
  onMouseEnter={() => {
    queryClient.prefetchQuery({
      queryKey: ['wheel', 'player', player.player_key],
      queryFn: () => fetchPlayerDetail(player.player_key),
    });
  }}
/>
```

### 5. Exportar em Lotes (Futuro)

O endpoint `/export` retorna JSON por enquanto. Para CSV real:
- Frontend pode converter JSON → CSV
- Ou aguardar endpoint de export assíncrono

---

## 🔐 Permissões

| Endpoint | Permissão |
|----------|-----------|
| GET /players | `wheel.players.view` |
| PUT /players/{key} | `wheel.players.manage` |
| GET /stats/* | `wheel.analytics.view` |

---

## 🎨 Sugestão de UI

### Tabela Principal

| Nome | Telefone | Cidade/UF | Verificado | Giros | Última Sessão |
|------|----------|-----------|------------|-------|---------------|
| João | ****-9999 | Tijucas/SC | ✅ | 3 | Hoje 14:30 |
| Maria | ****-8888 | Itajaí/SC | ✅ | 1 | Ontem |
| - | ****-7777 | - | ⏳ | 0 | - |

### Filtros Sidebar

```
🔍 Buscar...

📍 Localização
  [Cidade: ____] [UF: __]
  
🏪 Loja
  [Selecione...]
  
🎯 Campanha
  [Selecione...]

☑️ [x] Apenas verificados
☑️ [ ] Com endereço
☑️ [ ] Com giros

📅 Período
  De: [____] Até: [____]
```

### Drawer/Modal de Detalhes

```
┌─────────────────────────────────────┐
│ João Silva              player_xyz  │
│ +55 48 *****-9999      ✅ Verificado │
├─────────────────────────────────────┤
│ 📍 Rua XV de Novembro, 123          │
│    Centro, Tijucas/SC - 88160-000   │
├─────────────────────────────────────┤
│ ESTATÍSTICAS                        │
│ ┌───────┐ ┌───────┐ ┌───────┐      │
│ │   3   │ │   2   │ │   2   │      │
│ │ Giros │ │Prêmios│ │ Lojas │      │
│ └───────┘ └───────┘ └───────┘      │
├─────────────────────────────────────┤
│ TIMELINE                            │
│ • 19/01 14:30 - Loja Tijucas        │
│   Campanha: Verão 2026              │
│   🎰 Película Premium (MC-A1B2)     │
│                                     │
│ • 15/01 16:00 - Loja Itajaí         │
│   Campanha: Verão 2026              │
│   🎰 10% OFF                        │
└─────────────────────────────────────┘
```

---

## 📞 Dúvidas?

Entre em contato com o time de backend para esclarecer qualquer ponto.
