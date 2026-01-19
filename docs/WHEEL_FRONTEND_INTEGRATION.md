# 📺 Guia de Integração Backend ↔ Frontend TV

> **Para:** Time de Frontend React (Totem/TV)  
> **De:** Time de Backend Laravel  
> **Data:** 19/01/2026  
> **Status:** ✅ Backend 100% implementado

---

## 📌 Resumo: O Que Está Pronto

| Componente Backend | Status | Observações |
|-------------------|--------|-------------|
| Autenticação TV | ✅ Pronto | Via `screen_key` + `secret_token` |
| Sessões QR | ✅ Pronto | Criação, expiração, estado |
| Sistema de Sorteio | ✅ Pronto | Lock, idempotência, pesos |
| WebSocket (Ably) | ✅ Pronto | Eventos para TV e Mobile |
| Fila de Jogadores | ✅ Pronto | Posição, vez, timeout |
| Prêmios e Inventário | ✅ Pronto | Consumo atômico de estoque |

---

## 🔌 Endpoints de Runtime (TV)

### Base URL de Produção
```
https://api.maiscapinhas.com/api/v1/wheel
```

---

### 1. Autenticar a TV

```http
POST /api/v1/wheel/screens/{screenKey}/auth
Content-Type: application/json
```

**Request:**
```json
{
  "secret_token": "token-secreto-gerado-no-admin"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "screen": {
      "screen_key": "screen-tijucas-001",
      "name": "Vitrine Principal",
      "store": {
        "id": 1,
        "name": "Mais Capinhas • Tijucas"
      },
      "status": "active"
    },
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "ably": {
      "token_url": "/api/v1/wheel/realtime/auth",
      "channel": "wheel:screen:screen-tijucas-001"
    },
    "campaign": {
      "campaign_key": "camp_verao_2026",
      "name": "Campanha Verão 2026",
      "segments": [...],
      "settings": {
        "qr_ttl_seconds": 120,
        "spin_duration_ms": 8000,
        "min_rotations": 5,
        "max_rotations": 8
      }
    }
  }
}
```

> 💡 **Dica:** Guarde o `access_token` em memória. Use-o em todas as requisições subsequentes no header `Authorization: Bearer {access_token}`.

---

### 2. Criar Sessão QR

```http
POST /api/v1/wheel/screens/{screenKey}/sessions
Authorization: Bearer {access_token}
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "session_key": "sess_abc123xyz",
    "qr_url": "https://app.maiscapinhas.com/roleta/sess_abc123xyz",
    "fallback_code": "ABC123",
    "expires_at": "2026-01-19T00:05:00Z",
    "expires_in_seconds": 120,
    "server_time": "2026-01-19T00:03:00Z"
  }
}
```

> 📱 O `qr_url` é o que o jogador escaneia. O `fallback_code` é para digitar manualmente.

---

### 3. Obter Estado Atual (Reidratação)

Use quando a TV reconectar ou precisar sincronizar:

```http
GET /api/v1/wheel/screens/{screenKey}/state
Authorization: Bearer {access_token}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "screen": {
      "screen_key": "screen-tijucas-001",
      "name": "Vitrine Principal",
      "store_name": "Mais Capinhas • Tijucas"
    },
    "session": {
      "session_key": "sess_abc123xyz",
      "qr_url": "https://app.maiscapinhas.com/roleta/sess_abc123xyz",
      "fallback_code": "ABC123",
      "expires_at": "2026-01-19T00:05:00Z",
      "status": "active"
    },
    "current_player": {
      "player_key": "player_123abc",
      "phone_masked": "****9999",
      "status": "verified",
      "spins_available": 1,
      "queue_position": 0
    },
    "queue": [
      {
        "player_key": "player_456def",
        "phone_masked": "****8888",
        "status": "verified",
        "queue_position": 1
      }
    ],
    "pending_spin": null,
    "wheel_config": {
      "segments": [...],
      "spin_duration_ms": 8000,
      "min_rotations": 5,
      "max_rotations": 8
    },
    "server_time": "2026-01-19T00:03:30Z"
  }
}
```

---

### 4. Heartbeat (Keep-Alive)

Envie a cada **30 segundos** para manter a TV como "online":

```http
POST /api/v1/wheel/screens/{screenKey}/heartbeat
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request:**
```json
{
  "device_info": {
    "user_agent": "Mozilla/5.0...",
    "resolution": "1920x1080",
    "memory": "8GB"
  }
}
```

---

### 5. Cancelar/Expirar Sessão

```http
DELETE /api/v1/wheel/sessions/{sessionKey}
Authorization: Bearer {access_token}
```

---

## 🌐 WebSocket via Ably

### Configuração Inicial

1. Instale o SDK Ably:
```bash
npm install ably
```

2. Configure o cliente:
```typescript
import Ably from 'ably';

const ably = new Ably.Realtime({
  authUrl: 'https://api.maiscapinhas.com/api/v1/wheel/realtime/auth',
  authHeaders: {
    Authorization: `Bearer ${accessToken}`,
  },
});

const channel = ably.channels.get(`wheel:screen:${screenKey}`);
```

3. Assine os eventos:
```typescript
channel.subscribe((message) => {
  const event = message.data;
  console.log(`Evento: ${event.type}`, event);
});
```

---

### Eventos que a TV Recebe

#### `session_updated`
Nova sessão criada ou atualizada.

```json
{
  "type": "session_updated",
  "event_id": "evt_abc123",
  "ts": 1737244800000,
  "session": {
    "session_key": "sess_abc123xyz",
    "qr_url": "https://app.maiscapinhas.com/roleta/sess_abc123xyz",
    "expires_at": "2026-01-19T00:05:00Z",
    "status": "active"
  }
}
```

---

#### `player_connected`
Jogador escaneou o QR e entrou na sessão.

```json
{
  "type": "player_connected",
  "event_id": "evt_def456",
  "ts": 1737244810000,
  "player": {
    "player_key": "player_123abc",
    "phone_masked": "****9999",
    "status": "pending",
    "queue_position": 0
  }
}
```

**Ação na TV:** Transicionar para estado `PLAYER_CONNECTED`, mostrar PlayerCard.

---

#### `player_verified`
Jogador confirmou telefone via WhatsApp.

```json
{
  "type": "player_verified",
  "event_id": "evt_ghi789",
  "ts": 1737244820000,
  "player_key": "player_123abc"
}
```

**Ação na TV:** Habilitar botão de girar (se for controle na TV) ou aguardar `spin_started`.

---

#### `spin_started` ⚡ CRÍTICO

Giro iniciado! **Este evento contém o `target_prize_id`** para você saber onde parar a roleta.

```json
{
  "type": "spin_started",
  "event_id": "evt_jkl012",
  "ts": 1737244830000,
  "spin": {
    "spin_key": "spin_789xyz",
    "player_key": "player_123abc",
    "target_prize_id": "prize_pelicula",
    "target_segment_index": 2,
    "spin_duration_ms": 8000,
    "rotations": 6
  }
}
```

**Campos importantes:**
- `target_prize_id`: ID do prêmio sorteado (para você identificar)
- `target_segment_index`: Índice do segmento na roleta (0-based)
- `spin_duration_ms`: Duração da animação
- `rotations`: Número de voltas completas

**Ação na TV:**
1. Transicionar para estado `SPINNING`
2. Calcular ângulo final: `(rotations * 360) + (segmentIndex * segmentAngle) + offset`
3. Iniciar animação com easing

---

#### `spin_result`
Resultado do giro confirmado.

```json
{
  "type": "spin_result",
  "event_id": "evt_mno345",
  "ts": 1737244838000,
  "spin": {
    "spin_key": "spin_789xyz",
    "player_key": "player_123abc",
    "prize": {
      "prize_key": "prize_pelicula",
      "name": "Película Premium",
      "type": "product",
      "icon": "🎁",
      "code": "MC-A1B2C3",
      "description": "Película de vidro premium"
    },
    "is_winner": true
  }
}
```

**Ação na TV:**
1. Esperar animação terminar (usar `spin_duration_ms`)
2. Transicionar para estado `RESULT`
3. Mostrar PrizeResult com confetti se `is_winner: true`
4. Após 12s, voltar para `ATTRACTION` ou próximo jogador

---

#### `player_disconnected`
Jogador saiu ou timeout.

```json
{
  "type": "player_disconnected",
  "event_id": "evt_pqr678",
  "ts": 1737244850000,
  "player_key": "player_123abc",
  "reason": "timeout"
}
```

**Razões possíveis:** `timeout`, `left`, `completed`, `error`

---

#### `queue_updated`
Fila de jogadores mudou.

```json
{
  "type": "queue_updated",
  "event_id": "evt_stu901",
  "ts": 1737244860000,
  "current_player": {
    "player_key": "player_456def",
    "phone_masked": "****8888",
    "status": "verified"
  },
  "queue": []
}
```

---

## ❓ Respostas às Perguntas do Frontend

### 1. WebSocket Provider
> **Resposta:** Usamos **Ably** (gerenciado). Vocês conectam via SDK Ably com auth callback apontando para nosso endpoint `/api/v1/wheel/realtime/auth`.

### 2. Autenticação do Player
> **Resposta:** Stateless via `player_key` + `access_token` gerado no join. O app mobile recebe um token no response do join e usa em todas as requisições.

### 3. Verificação WhatsApp
> **Resposta:** Por enquanto, simulado. Estamos integrando com **Evolution API** (WhatsApp Business). O código é enviado via mensagem e o player digita no app.

### 4. Limite de Jogadores na Fila
> **Resposta:** Configurável por campanha via `settings.max_queue_size`. Default: **10 jogadores**.

### 5. Validade dos Prêmios
> **Resposta:** O código do prêmio (`MC-A1B2C3`) não tem expiração no sistema. A validade é controlada pela loja no momento do resgate.

### 6. Multi-Loja
> **Resposta:** Uma única API serve todas as lojas. Cada `Screen` está vinculada a uma `Store`. O `screen_key` identifica qual loja/totem.

### 7. Dashboard Admin
> **Resposta:** Já desenvolvido! Endpoints em `/api/v1/admin/wheel/*` com CRUD completo.

---

## 🎯 Lógica de Sorteio Implementada

### Como Funciona

```
1. Player solicita spin (via app mobile)
2. Backend adquire LOCK na sessão
3. Backend verifica idempotência (client_nonce)
4. Backend calcula sorteio ponderado:
   - Soma todos os probability_weight dos segmentos ativos
   - Gera random entre 1 e soma
   - Itera segmentos até acumular >= random
   - Verifica se prêmio tem estoque
   - Se não tem, redistribui para próximo
5. Backend consome estoque atomicamente
6. Backend cria registro WheelSpin
7. Backend publica spin_started via Ably (com target_prize_id)
8. TV recebe evento e inicia animação
9. Mobile recebe evento (SEM target_prize_id)
10. Após animação, mobile envia ACK
11. Backend publica spin_result
```

### Anti-Cheat Implementado

| Proteção | Implementação |
|----------|---------------|
| **Lock por sessão** | `Cache::lock("wheel:spin:{sessionId}", 10)` |
| **Idempotência** | `client_nonce` único por requisição |
| **Rate limit** | 1 spin por player por sessão |
| **Estoque atômico** | Transaction + `decrement` no DB |
| **Target secreto** | Mobile NÃO recebe `target_prize_id` |

---

## 📐 Cálculo do Ângulo de Parada

Recomendação para a animação da roleta:

```typescript
function calculateFinalAngle(
  targetSegmentIndex: number,
  totalSegments: number,
  rotations: number
): number {
  const segmentAngle = 360 / totalSegments;
  
  // Centro do segmento alvo
  const targetAngle = targetSegmentIndex * segmentAngle + (segmentAngle / 2);
  
  // Adiciona voltas completas + ajuste para ponteiro no topo
  const finalAngle = (rotations * 360) + (360 - targetAngle);
  
  return finalAngle;
}

// Exemplo:
// 8 segmentos, target index 2, 6 rotations
// segmentAngle = 45°
// targetAngle = 2 * 45 + 22.5 = 112.5°
// finalAngle = 2160 + 247.5 = 2407.5°
```

---

## 🔄 Fluxo de Estados Recomendado

```typescript
type TVState = 
  | 'ATTRACTION'       // Mostrando QR
  | 'PLAYER_CONNECTED' // Player na sessão
  | 'SPINNING'         // Animação rodando
  | 'RESULT'           // Mostrando prêmio
  | 'RECONNECTING';    // Tentando reconectar

// Transições baseadas em eventos:
const transitions = {
  'ATTRACTION': {
    'player_connected': 'PLAYER_CONNECTED',
    'connection_lost': 'RECONNECTING',
  },
  'PLAYER_CONNECTED': {
    'spin_started': 'SPINNING',
    'player_disconnected': 'ATTRACTION',
    'connection_lost': 'RECONNECTING',
  },
  'SPINNING': {
    'spin_result': 'RESULT', // após animação terminar
    'connection_lost': 'RECONNECTING',
  },
  'RESULT': {
    'timeout_12s': 'ATTRACTION',
    'queue_updated': 'PLAYER_CONNECTED', // próximo jogador
  },
  'RECONNECTING': {
    'connected': 'ATTRACTION', // com reidratação
    'max_retries': 'ATTRACTION', // exibir erro
  },
};
```

---

## 💡 Sugestões de Melhorias

### Para o Frontend

1. **Retry exponencial no WebSocket:**
   ```typescript
   const retryDelays = [1000, 2000, 4000, 8000, 16000];
   ```

2. **Preload de sons:**
   ```typescript
   useEffect(() => {
     const sounds = ['spin.mp3', 'win.mp3', 'lose.mp3'];
     sounds.forEach(s => new Audio(s).load());
   }, []);
   ```

3. **Fallback offline:**
   - Se perder conexão, mostrar animação de "Reconectando..."
   - Guardar último estado em localStorage
   - Reidratar via `GET /screens/{key}/state` ao reconectar

4. **Animação "respirando" no QR:**
   - Pulsar suavemente o QR para atrair atenção
   - Contador visual de expiração

### Para Integração

1. **Usar `server_time` para sincronizar:**
   ```typescript
   const serverOffset = serverTime - Date.now();
   const adjustedExpiry = expiresAt - serverOffset;
   ```

2. **Debounce no heartbeat:**
   - Se a aba ficar inativa, pausar heartbeat
   - Retomar ao voltar para ativa

---

## 🚀 Checklist de Integração

### Configuração Inicial
- [ ] Obter `screen_key` e `secret_token` do admin
- [ ] Configurar variáveis de ambiente
- [ ] Instalar SDK Ably

### Implementação
- [ ] Fluxo de autenticação (`POST /screens/{key}/auth`)
- [ ] Criação de sessão QR (`POST /screens/{key}/sessions`)
- [ ] Conexão WebSocket via Ably
- [ ] Handler para cada tipo de evento
- [ ] Máquina de estados
- [ ] Animação da roleta com `target_segment_index`
- [ ] Heartbeat a cada 30s
- [ ] Reidratação (`GET /screens/{key}/state`)

### Testes
- [ ] Testar timeout de sessão QR
- [ ] Testar múltiplos jogadores na fila
- [ ] Testar reconexão após queda
- [ ] Testar giro e resultado
- [ ] Testar todos os tipos de prêmios

---

## 📞 Suporte

**Dúvidas técnicas:** Abrir issue no repositório com tag `[wheel][frontend]`

**Endpoint de teste (staging):**
```
https://staging-api.maiscapinhas.com/api/v1/wheel
```

**Logs de debug:**
```http
GET /api/v1/admin/wheel/logs/events?screen_key={key}&limit=50
```

---

> Documento gerado em: 19/01/2026  
> Backend Status: ✅ Pronto para integração
