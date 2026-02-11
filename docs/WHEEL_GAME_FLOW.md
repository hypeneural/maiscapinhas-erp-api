# 🎰 Wheel Game - Documentação Completa do Fluxo

> Documentação técnica e funcional do sistema de Roleta Premiada +Capinhas  
> Versão: 1.0 | Data: Janeiro 2026

---

## 📋 Índice

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura do Sistema](#2-arquitetura-do-sistema)
3. [Painel Administrativo](#3-painel-administrativo)
4. [TV/Totem (Screen)](#4-tvtotem-screen)
5. [Controle Mobile (Jogador)](#5-controle-mobile-jogador)
6. [Fluxo Completo do Jogo](#6-fluxo-completo-do-jogo)
7. [Comunicação em Tempo Real](#7-comunicação-em-tempo-real)
8. [Sistema de Prêmios e Probabilidades](#8-sistema-de-prêmios-e-probabilidades)
9. [Regras de Negócio](#9-regras-de-negócio)
10. [Segurança e Anti-Fraude](#10-segurança-e-anti-fraude)

---

## 1. Visão Geral

### O que é o Wheel Game?

O Wheel Game é um sistema de engajamento gamificado onde clientes podem girar uma roleta virtual para ganhar prêmios. O sistema é composto por três interfaces principais:

```
┌─────────────────────────────────────────────────────────────────────┐
│                        ARQUITETURA GERAL                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│   📱 MOBILE                🖥️ TV/TOTEM              🖥️ ADMIN         │
│   (Controle)              (Display)               (Gestão)         │
│       │                       │                      │              │
│       │                       │                      │              │
│       ▼                       ▼                      ▼              │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                      🌐 BACKEND API                          │   │
│  │                   (Laravel + Ably)                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                     │
│                              ▼                                     │
│                   ┌───────────────────┐                            │
│                   │    📦 DATABASE     │                            │
│                   │   (MySQL/Redis)   │                            │
│                   └───────────────────┘                            │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Cenário de Uso

1. **Localização:** TV/Totem instalado na vitrine da loja (visível pelo lado de fora)
2. **Interação:** Cliente escaneia QR Code com celular pessoal
3. **Jogabilidade:** Celular funciona como controle remoto para girar a roleta na TV
4. **Prêmio:** Se ganhar, cliente entra na loja para resgatar

---

## 2. Arquitetura do Sistema

### Componentes Principais

| Componente | Tecnologia | Função |
|------------|------------|--------|
| **Backend API** | Laravel 11 + PHP 8.3 | Lógica de negócio, APIs REST |
| **Realtime** | Ably WebSocket | Comunicação TV ↔ Mobile |
| **TV Frontend** | React + TypeScript | Exibição da roleta |
| **Mobile Frontend** | React + TypeScript (PWA) | Controle remoto do jogador |
| **Admin Frontend** | React + TypeScript | Painel administrativo |
| **Database** | MySQL | Persistência de dados |
| **Cache** | Redis | Sessions e rate limiting |

### Diagrama de Comunicação

```
┌──────────────┐         ┌──────────────┐         ┌──────────────┐
│   📱 MOBILE   │◄───────►│   🌐 ABLY     │◄───────►│   🖥️ TV       │
│  (Jogador)   │ WebSocket│  (Realtime)  │WebSocket│  (Totem)     │
└──────┬───────┘         └──────────────┘         └──────┬───────┘
       │                                                  │
       │  HTTP/REST                          HTTP/REST   │
       │                                                  │
       ▼                                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│                        🌐 BACKEND API                            │
│                                                                  │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐              │
│  │ Screen      │  │ Mobile      │  │ Spin        │              │
│  │ Controller  │  │ Controller  │  │ Service     │              │
│  └─────────────┘  └─────────────┘  └─────────────┘              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Painel Administrativo

### Função
Gerencia todas as configurações do sistema. Acesso restrito a Super Admin, Admin e Manager.

### Módulos

#### 3.1 Campanhas
| Ação | Descrição |
|------|-----------|
| Criar campanha | Define nome, período, regras e prêmios |
| Ativar/Pausar | Controla se campanha está rodando |
| Duplicar | Copia configuração para nova campanha |
| Encerrar | Finaliza campanha definitivamente |

**Status da Campanha:**
- `draft` → Rascunho (editável)
- `active` → Em execução
- `paused` → Pausada temporariamente
- `ended` → Encerrada

#### 3.2 Screens (TVs/Totens)
| Ação | Descrição |
|------|-----------|
| Cadastrar | Registra nova TV com nome, loja e secret token |
| Associar campanha | Define qual campanha roda em cada TV |
| Monitorar | Verifica status online/offline |
| Rotacionar token | Gera novo secret_token por segurança |

#### 3.3 Prêmios
| Campo | Descrição |
|-------|-----------|
| `name` | Nome exibido (ex: "Cupom 20% OFF") |
| `type` | `product`, `coupon`, `nothing`, `try_again` |
| `estimated_value` | Valor em R$ para métricas de ROI |
| `code_prefix` | Prefixo do código de resgate (ex: "CUP20") |
| `redeem_instructions` | Instruções para resgate na loja |

#### 3.4 Segmentos (Fatias da Roleta)
| Campo | Descrição |
|-------|-----------|
| `label` | Texto exibido na fatia |
| `prize_id` | Prêmio associado |
| `probability_weight` | Peso para cálculo de probabilidade |
| `color` | Cor de fundo da fatia |
| `position` | Ordem na roleta |

#### 3.5 Inventário
| Campo | Descrição |
|-------|-----------|
| `total_limit` | Limite total de unidades |
| `remaining` | Unidades restantes |
| `daily_limit` | Limite por dia |
| `daily_remaining` | Restante do dia (reseta à meia-noite) |

#### 3.6 Regras de Prêmio (Prize Rules)
Controles anti-abuso:
- **Cooldown:** Mínimo de X spins entre cada prêmio do mesmo tipo
- **Limite por hora/dia:** Máximo de prêmios distribuídos por período
- **Escopo:** Regras podem ser globais ou por screen

#### 3.7 Analytics
Métricas disponíveis:
- Spins por período
- Prêmios distribuídos/resgatados
- Performance por loja
- Horários de pico
- Mapa geográfico de jogadores
- ROI (valor distribuído vs engajamento)

---

## 4. TV/Totem (Screen)

### Função
Exibir a roleta e animações para o público, instalada na vitrine da loja.

### Estados da TV

```
┌─────────────────────────────────────────────────────────────────┐
│                    DIAGRAMA DE ESTADOS - TV                     │
└─────────────────────────────────────────────────────────────────┘

     ┌───────────┐
     │  OFFLINE  │ ─── Liga TV ───► ┌───────────┐
     │           │                  │  BOOTING  │
     └───────────┘                  └─────┬─────┘
                                          │
                                    Auth Success
                                          │
                                          ▼
     ┌───────────┐                 ┌───────────┐
     │ SPINNING  │◄── Spin ───────│   IDLE    │
     │           │    Triggered   │ (QR Code) │
     └─────┬─────┘                 └─────┬─────┘
           │                             │
     Spin Complete                 Player Joined
           │                             │
           ▼                             ▼
     ┌───────────┐                 ┌───────────┐
     │  RESULT   │◄── Verified ───│  WAITING  │
     │ (Prêmio)  │                │ (Fila)    │
     └─────┬─────┘                 └───────────┘
           │
      Timeout ou
      ACK recebido
           │
           ▼
     ┌───────────┐
     │   IDLE    │ (Novo QR Code)
     └───────────┘
```

### API Endpoints (TV)

| Endpoint | Método | Descrição |
|----------|--------|-----------|
| `/wheel/screens/{key}/auth` | POST | Autentica TV com secret_token |
| `/wheel/screens/{key}/sessions` | POST | Cria nova sessão (gera QR Code) |
| `/wheel/screens/{key}/state` | GET | Obtém estado atual da sessão |
| `/wheel/screens/{key}/heartbeat` | POST | Envia ping de "estou vivo" |
| `/wheel/sessions/{key}` | DELETE | Expira sessão atual |

### Fluxo de Inicialização da TV

```
1. TV Liga
   │
2. Carrega aplicação React
   │
3. POST /screens/{key}/auth
   │ Body: { "secret_token": "xxx" }
   │
4. ✅ Recebe screen_token (JWT valido por 24h)
   │
5. POST /screens/{key}/sessions
   │ Header: Authorization: Bearer {screen_token}
   │
6. ✅ Recebe session_key + qr_code_data
   │
7. Exibe QR Code na tela + Timer de expiração
   │
8. Inicia heartbeat a cada 30s
   │
9. Conecta ao Ably para eventos realtime
```

### Eventos Ably que a TV Escuta

| Canal | Evento | Ação |
|-------|--------|------|
| `session:{key}` | `player_joined` | Mostra jogador na fila |
| `session:{key}` | `player_verified` | Atualiza status do jogador |
| `session:{key}` | `spin_started` | Inicia animação de giro |
| `session:{key}` | `spin_completed` | Mostra resultado |
| `session:{key}` | `session_expired` | Volta para QR Code |

---

## 5. Controle Mobile (Jogador)

### Função
Funciona como "controle remoto" para o jogador interagir com a roleta na TV.

### Estados do Mobile

```
┌─────────────────────────────────────────────────────────────────┐
│                  DIAGRAMA DE ESTADOS - MOBILE                   │
└─────────────────────────────────────────────────────────────────┘

     ┌───────────┐
     │  INICIAL  │ ─── Scan QR ──► ┌───────────────┐
     │           │                 │ PHONE_INPUT   │
     └───────────┘                 └───────┬───────┘
                                           │
                                      Submit Phone
                                           │
                                           ▼
     ┌───────────────┐             ┌───────────────┐
     │  VERIFIED     │◄── Code ────│ CODE_INPUT    │
     │  (Na fila)    │   Correct   │ (Verificação) │
     └───────┬───────┘             └───────────────┘
             │
       É minha vez!
             │
             ▼
     ┌───────────────┐
     │  YOUR_TURN    │ ─── Tap ───► ┌───────────────┐
     │  (Gire!)      │   Button    │  SPINNING     │
     └───────────────┘              └───────┬───────┘
                                            │
                                      Resultado
                                            │
                                            ▼
     ┌───────────────┐             ┌───────────────┐
     │    WON        │    ou       │    LOST       │
     │  (Parabéns!)  │             │ (Não foi...)  │
     └───────────────┘             └───────────────┘
```

### API Endpoints (Mobile)

| Endpoint | Método | Descrição |
|----------|--------|-----------|
| `/wheel/sessions/{key}/join` | POST | Entra na fila (envia phone) |
| `/wheel/sessions/{key}/request-code` | POST | Solicita código SMS/WhatsApp |
| `/wheel/sessions/{key}/verify` | POST | Verifica código recebido |
| `/wheel/mobile/state` | GET | Estado atual do jogador |
| `/wheel/mobile/spins` | POST | Solicita giro da roleta |
| `/wheel/mobile/address` | POST | Atualiza endereço (ViaCEP) |
| `/wheel/spins/{key}/ack` | POST | Confirma recebimento do resultado |

### Fluxo do Jogador

```
1. Escaneia QR Code da TV
   │
2. Abre PWA no navegador mobile
   │ URL: https://app.maiscapinhas.com.br/wheel/join/{session_key}
   │
3. Tela: Insira seu telefone
   │
4. POST /sessions/{key}/join
   │ Body: { "phone": "+5511999999999" }
   │
5. ✅ Recebe session_player_key + access_token
   │
6. Tela: Verificação WhatsApp
   │
7. POST /sessions/{key}/request-code
   │
8. Recebe código via WhatsApp
   │
9. POST /sessions/{key}/verify
   │ Body: { "code": "123456" }
   │
10. ✅ Status muda para VERIFIED
    │
11. Tela: Aguardando sua vez...
    │
12. [Evento Ably] your_turn
    │
13. Tela: É SUA VEZ! (Botão grande GIRAR)
    │
14. Toca no botão
    │
15. POST /mobile/spins
    │ Header: Authorization: Bearer {access_token}
    │ Body: { "client_nonce": "uuid" }
    │
16. Backend calcula prêmio + envia spin_started via Ably
    │
17. TV anima o giro
    │
18. Backend envia spin_completed via Ably
    │
19. Tela: Resultado (GANHOU ou NÃO GANHOU)
    │
20. POST /spins/{key}/ack
    │
21. Se ganhou: Mostra código + instruções de resgate
```

---

## 6. Fluxo Completo do Jogo

### Diagrama de Sequência

```
┌─────────┐          ┌─────────┐          ┌─────────┐          ┌─────────┐
│  Admin  │          │ Backend │          │   TV    │          │ Mobile  │
└────┬────┘          └────┬────┘          └────┬────┘          └────┬────┘
     │                    │                    │                    │
     │ Configura campaign │                    │                    │
     │ e screens          │                    │                    │
     │───────────────────►│                    │                    │
     │                    │                    │                    │
     │                    │    Auth + Session  │                    │
     │                    │◄───────────────────│                    │
     │                    │                    │                    │
     │                    │    QR Code         │                    │
     │                    │───────────────────►│                    │
     │                    │                    │                    │
     │                    │                    │  Escaneia QR       │
     │                    │                    │◄───────────────────│
     │                    │                    │                    │
     │                    │                 Join + Phone            │
     │                    │◄────────────────────────────────────────│
     │                    │                    │                    │
     │                    │ [Ably] player_joined                    │
     │                    │───────────────────►│                    │
     │                    │                    │                    │
     │                    │            Verify Code                  │
     │                    │◄────────────────────────────────────────│
     │                    │                    │                    │
     │                    │ [Ably] player_verified                  │
     │                    │───────────────────►│                    │
     │                    │                    │                    │
     │                    │ [Ably] your_turn   │                    │
     │                    │────────────────────────────────────────►│
     │                    │                    │                    │
     │                    │              Spin Request               │
     │                    │◄────────────────────────────────────────│
     │                    │                    │                    │
     │                    │ Calcula prêmio     │                    │
     │                    │ (probabilidade +   │                    │
     │                    │  inventário +      │                    │
     │                    │  regras)           │                    │
     │                    │                    │                    │
     │                    │ [Ably] spin_started                     │
     │                    │───────────────────►│                    │
     │                    │                    │                    │
     │                    │                    │ Anima roleta       │
     │                    │                    │ (8-12 segundos)    │
     │                    │                    │                    │
     │                    │ [Ably] spin_completed                   │
     │                    │───────────────────►│───────────────────►│
     │                    │                    │                    │
     │                    │                    │ Mostra resultado   │
     │                    │                    │                    │
     │                    │            ACK                          │
     │                    │◄────────────────────────────────────────│
     │                    │                    │                    │
     │                    │    Nova sessão     │                    │
     │                    │───────────────────►│                    │
     │                    │                    │                    │
```

---

## 7. Comunicação em Tempo Real

### Ably Channels

| Canal | Formato | Quem publica | Quem escuta |
|-------|---------|--------------|-------------|
| `wheel:screen:{key}` | Private | Backend | TV |
| `wheel:session:{key}` | Private | Backend | TV + Mobile |
| `wheel:player:{key}` | Private | Backend | Mobile específico |

### Eventos Publicados

#### Canal `session:{key}`

| Evento | Payload | Trigger |
|--------|---------|---------|
| `player_joined` | `{ player_key, phone_masked, position }` | Mobile faz join |
| `player_verified` | `{ player_key, status }` | Mobile verifica código |
| `player_left` | `{ player_key }` | Timeout ou abandono |
| `spin_started` | `{ spin_key, player_key, duration_ms }` | Backend inicia spin |
| `spin_completed` | `{ spin_key, segment, prize, code }` | Spin termina |
| `session_expired` | `{}` | Timeout da sessão |

#### Canal `player:{key}`

| Evento | Payload | Trigger |
|--------|---------|---------|
| `your_turn` | `{ spins_available }` | Vez do jogador |
| `queue_update` | `{ position }` | Posição na fila mudou |
| `session_closed` | `{ reason }` | Sessão fechada |

### Autenticação Ably

```javascript
// TV solicita token Ably
POST /wheel/realtime/auth
Headers: Authorization: Bearer {screen_token}
Body: { "client_id": "screen:{key}" }

// Mobile solicita token Ably  
POST /wheel/realtime/auth
Headers: Authorization: Bearer {access_token}
Body: { "client_id": "player:{key}" }
```

---

## 8. Sistema de Prêmios e Probabilidades

### Cálculo de Probabilidade

A probabilidade de cada segmento é calculada pelo peso relativo:

```
Probabilidade(segmento) = peso_segmento / soma_todos_pesos * 100%
```

**Exemplo:**

| Segmento | Peso | Probabilidade |
|----------|------|---------------|
| Cupom 10% | 50 | 50% |
| Cupom 20% | 20 | 20% |
| Nada | 15 | 15% |
| Tente Novamente | 10 | 10% |
| Produto | 5 | 5% |
| **Total** | **100** | **100%** |

### Fluxo de Seleção do Prêmio

```
1. Gerar número aleatório (1-100)
   │
2. Verificar prize rules (cooldown, limites)
   │ Se prêmio bloqueado → Fallback para "Nada"
   │
3. Verificar inventário
   │ Se estoque zerado → Fallback para "Nada"
   │
4. Selecionar segmento baseado no peso
   │
5. Consumir inventário (se aplicável)
   │
6. Registrar prize_state (para cooldown)
   │
7. Retornar resultado
```

### Tipos de Prêmio

| Tipo | Requer Resgate | Consome Estoque | Valor |
|------|----------------|-----------------|-------|
| `product` | ✅ Sim | ✅ Sim | R$ variável |
| `coupon` | ✅ Sim | ✅ Sim | R$ variável |
| `nothing` | ❌ Não | ❌ Não | R$ 0 |
| `try_again` | ❌ Não | ❌ Não | R$ 0 |

---

## 9. Regras de Negócio

### 9.1 Limites de Participação
- 1 telefone = 1 participação por campanha (controlado por `phone_hash`)
- Verificação obrigatória via WhatsApp

### 9.2 Expiração de Sessão
- Sessão expira em 5-15 minutos (configurável)
- Jogador na fila tem timeout de 2 minutos para girar
- QR Code é único por sessão

### 9.3 Inventário
- Limite total por campanha
- Limite diário (reseta à meia-noite UTC-3)
- Quando estoque zera → Prêmio vira "Nada"

### 9.4 Cooldowns (Prize Rules)
- Mínimo de X spins entre prêmios premium
- Limite de Y prêmios por hora/dia
- Aplicável por campanha ou por screen

### 9.5 Resgate
- Prêmio válido por 7 dias (configurável)
- Código único gerado: `{PREFIX}-XXXXXX`
- Operador valida código na loja

---

## 10. Segurança e Anti-Fraude

### Autenticação por Camada

| Camada | Método |
|--------|--------|
| Admin | JWT via Sanctum (usuário autenticado) |
| TV/Screen | Bearer token (secret_token único) |
| Mobile/Player | Bearer token (gerado no join) |
| Realtime | Token Ably com capabilities limitadas |

### Medidas Anti-Fraude

| Medida | Proteção |
|--------|----------|
| Rate Limiting | Limite de requests por IP/token |
| Phone Hash | Unicidade de participação |
| Nonce Único | Previne replay attacks em spins |
| Token Expiração | Tokens com TTL curto |
| Cooldowns | Evita farming de prêmios premium |
| Heartbeat | Detecta TVs offline/comprometidas |

### Validações no Spin

```
1. Token válido?
2. Sessão ainda ativa?
3. É a vez deste jogador?
4. Já fez spin nesta sessão?
5. Nonce já foi usado?
6. Rate limit OK?
```

---

## 📌 Glossário

| Termo | Definição |
|-------|-----------|
| **Screen** | TV/Totem onde a roleta é exibida |
| **Session** | Instância ativa de jogo (1 QR Code) |
| **Session Player** | Participação de um jogador em uma sessão |
| **Spin** | Um giro da roleta |
| **Segment** | Fatia da roleta (prêmio + visual) |
| **Prize** | Prêmio cadastrado no sistema |
| **Inventory** | Controle de estoque por campanha |
| **Prize Rule** | Regra de distribuição (cooldown/limites) |
| **Prize State** | Estado atual de distribuição de um prêmio |
| **Ably** | Serviço de WebSocket para realtime |
| **Heartbeat** | Ping periódico para verificar conexão |
