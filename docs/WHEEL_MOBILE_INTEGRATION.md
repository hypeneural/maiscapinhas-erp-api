# 📱 Guia de Integração Backend ↔ Mobile Controller

> **Para:** Time de Frontend React/Mobile (Controle Remoto)  
> **De:** Time de Backend Laravel  
> **Data:** 19/01/2026  
> **Status:** ✅ Backend 100% implementado

---

## 📌 Resumo: O que está pronto

| Funcionalidade | Status | Endpoint |
|----------------|--------|----------|
| Contexto da sessão | ✅ | `GET /sessions/{key}/state` |
| Entrar na fila | ✅ | `POST /sessions/{key}/join` |
| Validação WhatsApp | ✅ | `POST /sessions/{key}/verify` |
| Solicitar giro | ✅ | `POST /mobile/spins` |
| Estado do jogador | ✅ | `GET /mobile/state` |
| ACK do resultado | ✅ | `POST /spins/{key}/ack` |
| WebSocket (Ably) | ✅ | `POST /realtime/auth` |

---

## 🔌 Endpoints do Mobile

### Base URL
```
https://api.maiscapinhas.com/api/v1/wheel
```

---

### 1️⃣ GET /sessions/{sessionKey}

**Objetivo:** Carregar contexto do QR code escaneado.

> O `sessionKey` vem da URL do QR: `https://app.maiscapinhas.com/roleta/{sessionKey}`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "session_key": "sess_abc123xyz",
    "status": "active",
    "expires_at": "2026-01-19T00:05:00Z",
    "screen": {
      "screen_key": "screen-tijucas-001",
      "store_name": "Mais Capinhas • Tijucas"
    },
    "campaign": {
      "campaign_key": "camp_verao_2026",
      "name": "Roleta ao Vivo",
      "terms_version": "2026-01",
      "settings": {
        "per_phone_limit": "1_per_campaign",
        "spin_duration_ms": 8000
      }
    },
    "queue_size": 2,
    "server_time": "2026-01-19T00:03:00Z"
  }
}
```

**Erros:**
| Status | Código | Quando |
|--------|--------|--------|
| 404 | `SESSION_NOT_FOUND` | Session key inválido |
| 410 | `SESSION_EXPIRED` | Sessão já expirou |
| 503 | `SCREEN_OFFLINE` | TV está offline |

---

### 2️⃣ POST /sessions/{sessionKey}/join

**Objetivo:** Entrar na fila/sessão com nome e telefone.

**Request:**
```json
{
  "phone": "+5547999999999",
  "name": "Anderson Marques",
  "terms_accepted": true,
  "device_fingerprint": "fp_xyz789"
}
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "player_key": "player_123abc",
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "status": "pending",
    "queue_position": 0,
    "verification_required": true,
    "ably": {
      "token_url": "/api/v1/wheel/realtime/auth",
      "channel": "wheel:player:player_123abc"
    }
  }
}
```

> 💡 **Importante:** Guarde o `access_token`! Use em todas as requisições seguintes.

**Erros:**
| Status | Código | Quando |
|--------|--------|--------|
| 409 | `ALREADY_JOINED` | Telefone já está na sessão |
| 429 | `PHONE_LIMIT_REACHED` | Telefone já participou da campanha |
| 422 | `QUEUE_FULL` | Fila está cheia |

---

### 3️⃣ POST /sessions/{sessionKey}/request-code

**Objetivo:** Solicitar código de verificação via WhatsApp.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Código enviado via WhatsApp",
  "data": {
    "whatsapp_url": "https://wa.me/5548999999999?text=%23MC-2481%23",
    "code_expires_in": 300,
    "attempts_remaining": 3
  }
}
```

> 📱 O `whatsapp_url` é um deep link. Ao clicar, abre o WhatsApp com mensagem pré-preenchida.

---

### 4️⃣ POST /sessions/{sessionKey}/verify

**Objetivo:** Verificar código recebido no WhatsApp.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request:**
```json
{
  "code": "2481"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Telefone verificado com sucesso!",
  "data": {
    "status": "verified",
    "queue_position": 0,
    "spins_available": 1
  }
}
```

**Erros:**
| Status | Código | Quando |
|--------|--------|--------|
| 400 | `INVALID_CODE` | Código errado |
| 429 | `MAX_ATTEMPTS` | Muitas tentativas |
| 410 | `CODE_EXPIRED` | Código expirou |

---

### 5️⃣ GET /mobile/state

**Objetivo:** Obter estado completo do jogador (polling e reconexão).

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "state": "READY_TO_SPIN",
    "server_time": "2026-01-19T00:03:30Z",
    "player": {
      "player_key": "player_123abc",
      "phone_masked": "****9999",
      "status": "verified",
      "spins_available": 1
    },
    "session": {
      "session_key": "sess_abc123xyz",
      "status": "active",
      "expires_at": "2026-01-19T00:05:00Z"
    },
    "screen": {
      "screen_key": "screen-tijucas-001",
      "store_name": "Mais Capinhas • Tijucas"
    },
    "queue": {
      "position": 0,
      "total": 1,
      "eta_seconds": 0
    },
    "current_spin": null,
    "ui_hints": {
      "focus_mode_min_ms": 2500,
      "spin_duration_ms": 8000
    }
  }
}
```

**Estados possíveis (`state`):**
| Estado | Descrição |
|--------|-----------|
| `PENDING` | Aguardando verificação |
| `VERIFYING` | Verificação em andamento |
| `IN_QUEUE` | Há jogadores na frente |
| `READY_TO_SPIN` | É a vez! Pode girar |
| `SPINNING` | Giro em andamento |
| `RESULT` | Resultado disponível |
| `COMPLETED` | Finalizou participação |

---

### 6️⃣ POST /mobile/spins ⚡ O BOTÃO GIRAR!

**Objetivo:** Solicitar um giro na roleta.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request:**
```json
{
  "client_nonce": "550e8400-e29b-41d4-a716-446655440000"
}
```

> 🔒 O `client_nonce` é um UUID gerado pelo frontend. Garante **idempotência** - se clicar 2x, só um giro é criado.

**Response (201):**
```json
{
  "success": true,
  "data": {
    "spin_key": "spin_789xyz",
    "status": "processing",
    "requested_at": "2026-01-19T00:03:35Z"
  }
}
```

**Erros:**
| Status | Código | Quando |
|--------|--------|--------|
| 409 | `SPIN_ALREADY_PENDING` | Já existe giro em andamento |
| 429 | `NO_SPINS_LEFT` | Sem giros disponíveis |
| 403 | `NOT_YOUR_TURN` | Não é a vez deste jogador |
| 423 | `SESSION_LOCKED` | Outro jogador está girando |

---

### 7️⃣ POST /spins/{spinKey}/ack

**Objetivo:** Confirmar que a animação terminou e player viu resultado.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request:**
```json
{
  "animation_duration_ms": 8150,
  "client_time": "2026-01-19T00:03:43Z"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Resultado confirmado",
  "data": {
    "prize": {
      "prize_key": "prize_pelicula",
      "name": "Película Premium",
      "type": "product",
      "code": "MC-A1B2C3",
      "icon": "🎁",
      "redeem_instructions": "Apresente este código no caixa"
    },
    "spins_remaining": 0,
    "share_mission": {
      "enabled": true,
      "share_url": "https://app.maiscapinhas.com/invite/abc123"
    }
  }
}
```

---

## 📡 WebSocket via Ably

### Configuração

```typescript
import Ably from 'ably';

const ably = new Ably.Realtime({
  authUrl: 'https://api.maiscapinhas.com/api/v1/wheel/realtime/auth',
  authHeaders: {
    Authorization: `Bearer ${accessToken}`,
  },
});

const channel = ably.channels.get(`wheel:player:${playerKey}`);

channel.subscribe((message) => {
  const event = message.data;
  handleEvent(event);
});
```

---

### Eventos que o Mobile Recebe

#### `player_state`
Mudança no estado do jogador.

```json
{
  "type": "player_state",
  "event_id": "evt_123",
  "ts": 1737244920000,
  "state": "READY_TO_SPIN",
  "queue_position": 0,
  "spins_available": 1
}
```

---

#### `spin_started`
A roleta começou a girar na TV!

```json
{
  "type": "spin_started",
  "event_id": "evt_456",
  "ts": 1737244925000,
  "spin_key": "spin_789xyz",
  "spin_duration_ms": 8000,
  "message": "Olhe para a TV! 📺"
}
```

> ⚠️ **Importante:** O Mobile **NÃO recebe** o `target_prize_id`. O resultado é segredo até o fim da animação!

---

#### `spin_result`
Resultado do giro!

```json
{
  "type": "spin_result",
  "event_id": "evt_789",
  "ts": 1737244933000,
  "spin_key": "spin_789xyz",
  "prize": {
    "prize_key": "prize_pelicula",
    "name": "Película Premium",
    "type": "product",
    "code": "MC-A1B2C3",
    "icon": "🎁"
  },
  "is_winner": true,
  "spins_remaining": 0
}
```

**Tipos de prêmio:**
| `type` | Descrição |
|--------|-----------|
| `product` | Produto físico |
| `coupon` | Cupom de desconto |
| `nothing` | Não ganhou desta vez |
| `try_again` | Tente novamente |

---

#### `session_expired`
Sessão expirou.

```json
{
  "type": "session_expired",
  "event_id": "evt_999",
  "ts": 1737244950000,
  "reason": "timeout"
}
```

---

## ❓ Respostas às Perguntas

### Sobre /sessions/{key}

**Q1: O token do QR contém apenas screen_id ou é JWT?**
> É apenas o `session_key` na URL. Exemplo: `.../roleta/sess_abc123xyz`. Não é JWT.

**Q2: O session_id é gerado aqui ou passado no token?**
> Está na URL. A TV cria via `POST /screens/{key}/sessions`.

**Q3: Como validamos se a sessão expirou?**
> Backend retorna 410 `SESSION_EXPIRED` automaticamente.

**Q4: expires_at é milliseconds ou seconds?**
> É **ISO 8601** string (`2026-01-19T00:05:00Z`). Também enviamos `server_time` para calcular diferença.

---

### Sobre /whatsapp/start (agora /join + /request-code)

**Q1: WhatsApp é centralizado ou por loja?**
> **Centralizado**. Um único número da empresa recebe todas as mensagens.

**Q2: O código #MC-XXXX# é randômico?**
> Sim, **4 dígitos randômicos**. Expira em 5 minutos.

**Q3: Webhook do WhatsApp ou polling?**
> Por enquanto, **o usuário digita o código manualmente** no app. Estamos integrando Evolution API para automação.

**Q4: Como detectamos que voltou do WhatsApp?**
> Use `visibilitychange` no frontend + polling no `GET /mobile/state`.

**Q5: player_id é gerado no join ou após confirmação?**
> Gerado **no join**. Status começa como `pending` até verificar.

---

### Sobre /me (agora /mobile/state)

**Q: Como obtemos o player_token?**
> É o `access_token` retornado no `POST /sessions/{key}/join`.

---

### Sobre /spins

**Q1: O client_nonce é persistido para idempotência?**
> ✅ Sim. Guardamos no banco. Se receber mesmo nonce, retorna o spin existente.

**Q2: Se reenviar com mesmo nonce?**
> Retorna o spin existente com status atual (não cria duplicata).

**Q3: Valida spins_available > 0?**
> ✅ Sim. Retorna erro `NO_SPINS_LEFT` se zerado.

**Q4: Resultado calculado no backend ou TV?**
> **Backend**! O sorteio é feito no momento do POST /spins.

---

### Sobre WebSocket

**Q1: WebSocket nativo ou Ably?**
> **Ably**. Gerenciado, sem necessidade de infraestrutura própria.

**Q2: Formato do channel name?**
> `wheel:player:{playerKey}` para mobile, `wheel:screen:{screenKey}` para TV.

**Q3: Token de conexão?**
> Usa `authUrl` do Ably apontando para `/api/v1/wheel/realtime/auth` com Bearer token.

---

### Sobre Share Mission

**Q1: O share_url tem tracking?**
> ✅ Sim. Contém ID do player que indicou.

**Q2: Como detectar indicação completada?**
> Backend incrementa automaticamente quando alguém usa o link.

**Q3: Quantos giros extras?**
> Configurável por campanha. Default: **1 giro extra** por indicação.

---

### Perguntas Adicionais

**Q: Como funciona a fila?**
> FIFO por sessão/screen. `queue_position: 0` = é a vez.

**Q: O que acontece se TV estiver offline?**
> Erro 503 `SCREEN_OFFLINE`. Sugerir tentar novamente.

**Q: Limite de sessões simultâneas?**
> 1 sessão ativa por screen. Nova sessão cancela a anterior.

**Q: Cliente fecha app durante spin?**
> Ao reabrir, `GET /mobile/state` retorna estado atual incluyendo `current_spin`.

---

## 🎯 Fluxo Completo

```
1. Usuário escaneia QR → abre app com sessionKey
2. App chama GET /sessions/{key} → obtém contexto
3. Usuário digita nome → POST /join → recebe access_token
4. POST /request-code → deep link WhatsApp
5. Usuário volta → POST /verify com código
6. Status muda para "verified" → conecta WebSocket
7. Recebe player_state "READY_TO_SPIN"
8. Clica GIRAR → POST /spins
9. Recebe spin_started → mostra "Olhe para a TV!"
10. Recebe spin_result → mostra prêmio
11. POST /ack → confirma visualização
```

---

## 🧪 Cenários de Teste

| Cenário | Comportamento |
|---------|---------------|
| QR expirado | 410 + mensagem |
| Double-click GIRAR | Segundo ignorado (nonce) |
| 2 pessoas mesmo QR | Segunda entra na fila |
| TV offline | 503 + retry |
| Fecha app no spin | Reabrir mostra estado atual |
| WhatsApp não verificado | Fica em PENDING |
| Sessão expira | WS envia session_expired |

---

## 📋 Informações para Integração

| Item | Valor |
|------|-------|
| **API Base URL** | `https://api.maiscapinhas.com/api/v1/wheel` |
| **WebSocket** | Ably (SDK npm: `ably`) |
| **Auth** | Bearer token no header |
| **WhatsApp** | Número centralizado (a confirmar) |
| **Timestamps** | ISO 8601 strings |
| **Nonce** | UUID v4 |

---

> Documento gerado em: 19/01/2026  
> Backend Status: ✅ Pronto para integração
