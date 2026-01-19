# 🔄 Novo Modelo de Dados - wheel_players

> **Para:** Time de Frontend  
> **Data:** 19/01/2026  
> **Status:** ✅ Implementado

---

## 📊 Mudança Arquitetural

### Antes ❌
```
wheel_players (session_id obrigatório)
   └── Mesmo cliente = múltiplos registros
   └── Sem endereço
```

### Agora ✅
```
wheel_players (pessoa única por WhatsApp)
   └── full_name, whatsapp_e164, endereço ViaCEP
   └── 1 registro por cliente

wheel_session_players (participação)
   └── Pivot entre session e player
   └── Status, fila, token
```

---

## 🆕 Tabelas Criadas

### wheel_session_players (NOVA)

```typescript
interface SessionPlayer {
  session_player_key: string; // "sp_abc123"
  session_id: number;
  player_id: number;
  status: 'pending' | 'verifying' | 'verified' | 'playing' | 'spinning' | 'completed' | 'disconnected';
  queue_position: number;
  access_token_hash: string;
  device_info: object;
  terms_version: string;
  joined_at: string;
  left_at: string | null;
}
```

### wheel_players (ATUALIZADA)

```typescript
interface Player {
  player_key: string; // "player_abc123"
  full_name: string;
  whatsapp_e164: string;
  whatsapp_lid: string | null; // Evolution API
  whatsapp_confirmed_at: string | null;
  // Endereço (ViaCEP)
  cep: string;
  street: string;
  number: string;
  complement: string;
  neighborhood: string;
  city: string;
  state: string;
  ibge: string;
}
```

---

## 🔌 Novos Endpoints

### POST /mobile/address

Atualiza endereço via CEP (integração ViaCEP automática).

```json
// Request
{
  "cep": "88160000",
  "number": "123",
  "complement": "Sala 1"
}

// Response
{
  "success": true,
  "data": {
    "cep": "88160-000",
    "street": "Rua XV de Novembro",
    "number": "123",
    "complement": "Sala 1",
    "neighborhood": "Centro",
    "city": "Tijucas",
    "state": "SC"
  }
}
```

---

## 📋 Responses Atualizadas

### POST /sessions/{key}/join

```json
{
  "success": true,
  "data": {
    "session_player": {
      "session_player_key": "sp_abc123",
      "player_key": "player_xyz",
      "phone_masked": "+55 48 *****-9999",
      "status": "pending",
      "state": "PENDING",
      "queue_position": 0,
      "spins_available": 1
    },
    "player": {
      "player_key": "player_xyz",
      "name": "João Silva",
      "phone_masked": "+55 48 *****-9999",
      "whatsapp_confirmed": false,
      "has_address": false,
      "city": null,
      "state": null
    },
    "access_token": "eyJ...",
    "realtime": {
      "auth_url": "/api/v1/wheel/realtime/auth",
      "channel": "wheel:player:player_xyz"
    }
  }
}
```

### GET /mobile/state

```json
{
  "success": true,
  "data": {
    "state": "READY_TO_SPIN",
    "server_time": "2026-01-19T12:00:00Z",
    "player": {...},
    "session_player": {
      "session_player_key": "sp_abc123",
      "status": "verified",
      "queue_position": 0,
      "spins_available": 1
    },
    "session": {...},
    "screen": {...},
    "queue": {
      "position": 0,
      "total": 1,
      "eta_seconds": 0
    },
    "current_spin": null,
    "last_result": null,
    "ui_hints": {...}
  }
}
```

---

## ⚠️ O Que MUDA no Frontend

1. **Token** → Agora autentica `session_player`, não `player`
2. **Keys** → Use `session_player_key` para operações na sessão
3. **Estado** → Campo `state` na resposta (PENDING, VERIFIED, SPINNING, etc.)
4. **Endereço** → Novo endpoint `POST /mobile/address`

---

## 🔗 Relacionamentos

```
Spin → SessionPlayer → Session → Screen → Store
Spin → SessionPlayer → Player (dados pessoais)
```

**Para auditoria:** O spin sempre aponta para session_player, que contém session_id e player_id.

---

## 🚀 Próximos Passos Backend

```bash
# Rodar migrations
php artisan migrate

# Limpar cache
php artisan config:clear && php artisan cache:clear
```
