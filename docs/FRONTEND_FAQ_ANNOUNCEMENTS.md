# Respostas às Perguntas do Frontend - Sistema de Comunicação Interna

> **Data:** 12/01/2026  
> **Versão da API:** v1

---

## 📋 Índice de Respostas

1. [Endpoints para Seleção de Alvos](#1-endpoints-para-seleção-de-alvos)
2. [Validação de Targets](#2-validação-de-targets)
3. [Estatísticas de Comunicados](#3-estatísticas-de-comunicados)
4. [Funcionalidades Adicionais](#4-funcionalidades-adicionais)
5. [Melhorias de UX](#5-melhorias-de-ux)
6. [Tabela Final de Endpoints](#6-tabela-final-de-endpoints)

---

## 1. Endpoints para Seleção de Alvos

### 1.1 Listagem de Lojas ✅ EXISTE

**Endpoint:** `GET /api/v1/stores/all`

Retorna todas as lojas ativas do sistema (sem verificação de vínculo).

```bash
curl -X GET "http://localhost/api/v1/stores/all" \
  -H "Authorization: Bearer {token}"
```

**Resposta:**
```json
{
  "data": [
    { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas" },
    { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema" }
  ],
  "meta": { "current_page": 1, "per_page": 100, "total": 3, "last_page": 1 }
}
```

**Uso no frontend:** Use este endpoint para popular o seletor de lojas quando `scope=store`.

---

### 1.2 Listagem de Usuários ✅ EXISTE

**Endpoint:** `GET /api/v1/admin/users`

> ⚠️ **Permissão:** Apenas Admin ou Super Admin

```bash
curl -X GET "http://localhost/api/v1/admin/users?search=joao&store_id=1&active=true&per_page=50" \
  -H "Authorization: Bearer {token}"
```

**Filtros disponíveis:**
| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `search` | string | Busca por nome ou email (case insensitive) |
| `active` | boolean | `true` = ativos, `false` = inativos |
| `store_id` | integer | Filtra usuários vinculados a esta loja |
| `per_page` | integer | Quantidade por página (1-100, default: 25) |

**Resposta:**
```json
{
  "data": [
    {
      "id": 6,
      "name": "João Vendedor",
      "email": "joao.vendedor@maiscapinhas.com.br",
      "active": true,
      "avatar_url": "https://...",
      "stores": [
        { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "vendedor" }
      ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 10, "last_page": 1 }
}
```

**Uso no frontend:** 
- Use para popular o seletor de usuários quando `scope=user`
- Só Admin/Super Admin pode ver este endpoint
- Gerentes não podem criar comunicados `scope=user` (somente `scope=store` ou `scope=role`)

---

### 1.3 Listagem de Cargos ✅ FIXO

**Os cargos são fixos no sistema.** Não há endpoint dinâmico.

| ID (target_id) | Label |
|----------------|-------|
| `admin` | Administrador |
| `gerente` | Gerente |
| `conferente` | Conferente |
| `vendedor` | Vendedor |

**No frontend, usar a lista fixa que vocês já têm:**

```typescript
const AVAILABLE_ROLES = [
  { id: 'admin', label: 'Administrador' },
  { id: 'gerente', label: 'Gerente' },
  { id: 'conferente', label: 'Conferente' },
  { id: 'vendedor', label: 'Vendedor' },
];
```

---

## 2. Validação de Targets

### 2.1 Formato dos Targets ✅ CORRETO

O formato está correto. **Importante:** `target_id` é sempre **string**, mesmo para IDs numéricos.

```json
{
  "title": "Novo comunicado",
  "message": "<p>Conteúdo HTML</p>",
  "type": "recado",
  "severity": "info",
  "scope": "store",
  "targets": [
    { "target_type": "store", "target_id": "1" },
    { "target_type": "store", "target_id": "2" }
  ]
}
```

**Regras de targets por scope:**

| Scope | target_type esperado | target_id |
|-------|---------------------|-----------|
| `global` | Nenhum (targets vazio ou omitido) | - |
| `store` | `store` | ID da loja como string: `"1"`, `"2"` |
| `user` | `user` | ID do usuário como string: `"6"`, `"15"` |
| `role` | `role` | Nome do cargo: `"vendedor"`, `"gerente"` |

**Exemplo para scope=role:**
```json
{
  "scope": "role",
  "targets": [
    { "target_type": "role", "target_id": "vendedor" },
    { "target_type": "role", "target_id": "conferente" }
  ]
}
```

---

### 2.2 Validação de Permissões ✅ IMPLEMENTADO

**Sim, o backend retorna erro 403 se o gerente tentar criar comunicado para loja onde não é gerente.**

**Regras de permissão:**

| Usuário | Pode criar scope=global | Pode criar scope=store | Pode criar scope=user | Pode criar scope=role |
|---------|:-----------------------:|:----------------------:|:---------------------:|:---------------------:|
| Super Admin | ✅ | ✅ (qualquer loja) | ✅ | ✅ |
| Admin | ✅ | ✅ (qualquer loja) | ✅ | ✅ |
| Gerente | ❌ 403 | ✅ (apenas suas lojas) | ❌ 403 | ✅ (apenas suas lojas) |

**Exemplo de erro 403:**
```json
{
  "error": {
    "code": 403,
    "message": "This action is unauthorized."
  }
}
```

---

## 3. Estatísticas de Comunicados

### 3.1 Endpoint de Stats ✅ IMPLEMENTADO

**Endpoint:** `GET /api/v1/announcements/{id}/stats`

```bash
curl -X GET "http://localhost/api/v1/announcements/1/stats" \
  -H "Authorization: Bearer {token}"
```

**Resposta:**
```json
{
  "data": {
    "total_recipients": 50,
    "delivered_count": 42,
    "seen_count": 35,
    "acknowledged_count": 30,
    "dismissed_count": 5,
    "pending_count": 20,
    "seen_percentage": 70.0,
    "ack_percentage": 60.0,
    "require_ack": true
  },
  "meta": { "request_id": "...", "timestamp": "..." }
}
```

**Campos explicados:**

| Campo | Descrição |
|-------|-----------|
| `total_recipients` | Estimativa de quantos usuários deveriam ver (baseado no scope) |
| `delivered_count` | Quantos já receberam (têm receipt) |
| `seen_count` | Quantos clicaram "Ler agora" |
| `acknowledged_count` | Quantos clicaram "RECEBIDO" |
| `dismissed_count` | Quantos dispensaram |
| `pending_count` | `total_recipients - acknowledged_count` |
| `seen_percentage` | `(seen_count / total_recipients) * 100` |
| `ack_percentage` | `(acknowledged_count / total_recipients) * 100` |

---

### 3.2 Lista de Recibos ✅ IMPLEMENTADO

**Endpoint:** `GET /api/v1/announcements/{id}/receipts`

```bash
curl -X GET "http://localhost/api/v1/announcements/1/receipts?status=pending&per_page=25" \
  -H "Authorization: Bearer {token}"
```

**Filtros disponíveis:**

| Parâmetro | Valores | Descrição |
|-----------|---------|-----------|
| `status` | `seen`, `unseen`, `acknowledged`, `pending`, `dismissed` | Filtrar por status |
| `store_id` | integer | Filtrar por loja |
| `per_page` | integer | Itens por página |

**Resposta:**
```json
{
  "data": [
    {
      "user": {
        "id": 6,
        "name": "João Vendedor",
        "email": "joao@exemplo.com",
        "avatar_url": "https://..."
      },
      "store": {
        "id": 1,
        "name": "Loja Centro"
      },
      "delivered_at": "2026-01-12T10:00:00Z",
      "seen_at": "2026-01-12T10:05:00Z",
      "acknowledged_at": null,
      "dismissed_at": null,
      "last_shown_at": "2026-01-12T10:05:00Z",
      "show_count": 2
    }
  ],
  "meta": {
    "pagination": { "current_page": 1, "per_page": 25, "total": 42 }
  }
}
```

---

## 4. Funcionalidades Adicionais

### 4.1 Preview Antes de Publicar ❌ NÃO IMPLEMENTADO

Não há endpoint de preview no momento. 

**Sugestão alternativa:** O comunicado é criado como `status=draft`, então o criador pode visualizar através do endpoint `GET /announcements/{id}` antes de chamar `/publish`.

---

### 4.2 Duplicar Comunicado ✅ IMPLEMENTADO

**Endpoint:** `POST /api/v1/announcements/{id}/duplicate`

Cria uma cópia do comunicado como rascunho.

```bash
curl -X POST "http://localhost/api/v1/announcements/1/duplicate" \
  -H "Authorization: Bearer {token}"
```

**Resposta:** Retorna o novo comunicado com `status=draft` e título `"[Cópia] Título Original"`.

**O que é copiado:**
- ✅ Título (com prefixo "[Cópia]")
- ✅ Mensagem, excerpt
- ✅ Tipo, severidade, display_mode
- ✅ Ícone, imagem, CTA
- ✅ Scope e targets
- ✅ require_ack, priority

**O que NÃO é copiado:**
- ❌ Datas (starts_at, expires_at)
- ❌ Status (sempre começa como draft)
- ❌ Receipts (começa zerado)

---

### 4.3 Republicar Comunicado Arquivado ✅ IMPLEMENTADO

**Endpoint:** `POST /api/v1/announcements/{id}/republish`

Republica um comunicado arquivado ou expirado.

```bash
curl -X POST "http://localhost/api/v1/announcements/1/republish" \
  -H "Authorization: Bearer {token}"
```

**Resposta:**
```json
{
  "data": {
    "message": "Republicado com sucesso.",
    "status": "active",
    "published_at": "2026-01-12T19:00:00Z"
  }
}
```

**O que acontece:**
- Status muda para `active`
- `starts_at` é definido como agora
- `expires_at` é limpo (sem expiração)
- `archived_at` e `archived_by_user_id` são limpos
- Os receipts antigos são mantidos (quem já confirmou não precisa confirmar novamente)

**Erro se status não for arquivado/expirado:**
```json
{
  "error": {
    "code": 422,
    "message": "Apenas comunicados arquivados ou expirados podem ser republicados."
  }
}
```

---

### 4.4 Comportamento do expires_at 📌 ESCLARECIMENTO

**Pergunta:** O campo `expires_at` já faz o arquivamento automático ou apenas esconde do dashboard?

**Resposta:** **Apenas esconde do dashboard.** O status não muda automaticamente.

| expires_at | Comportamento |
|------------|---------------|
| `null` | Nunca expira, sempre visível |
| Data futura | Visível normalmente |
| Data passada | Não aparece em `/me/announcements/active`, mas status continua `active` |

**Por que não muda automaticamente?**
- Evita jobs/crons adicionais
- Permite que admin veja comunicados "tecnicamente expirados" na listagem admin
- Admin pode republicar ou arquivar manualmente

**Se quiserem arquivamento automático no futuro**, podemos criar um comando artisan:
```bash
php artisan announcements:expire
```

---

## 5. Melhorias de UX

### 5.1 Notificações Push/WebSocket 📅 FUTURO

Ainda não implementado, mas está planejado.

**Arquitetura sugerida:**
```
1. User faz login → conecta WebSocket
2. Admin publica comunicado → evento broadcast
3. Frontend recebe → mostra notificação + recarrega `/me/announcements/active`
```

**Por enquanto, usar polling:**
```typescript
// Polling a cada 5 minutos
useEffect(() => {
  const interval = setInterval(() => {
    mutate('/api/v1/me/announcements/active');
  }, 5 * 60 * 1000);
  return () => clearInterval(interval);
}, []);
```

---

### 5.2 Upload de Imagens 📌 ESCLARECIMENTO

**O campo `image_url` aceita apenas URL externa.**

**Não há endpoint de upload de imagem para comunicados no momento.**

**Fluxo recomendado:**
1. Admin faz upload da imagem em serviço externo (S3, Cloudinary, etc.)
2. Copia a URL
3. Cola no campo `image_url` ao criar o comunicado

**Se precisarem de upload integrado**, podemos criar:
```
POST /api/v1/announcements/{id}/image
Content-Type: multipart/form-data
image: [arquivo]
```

---

### 5.3 Múltiplos Idiomas ❌ NÃO PLANEJADO

Não há plano para suporte multi-idioma no momento. Sistema é 100% em português.

---

## 6. Tabela Final de Endpoints

### ✅ Endpoints Existentes

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/api/v1/stores/all` | Listar todas as lojas | Autenticado |
| GET | `/api/v1/admin/users` | Listar usuários | Admin |
| GET | `/api/v1/me/announcements/active` | Avisos ativos (dashboard) | Autenticado |
| GET | `/api/v1/me/announcements` | Histórico do usuário | Autenticado |
| GET | `/api/v1/announcements` | Listar (admin) | Admin/Gerente |
| POST | `/api/v1/announcements` | Criar | Admin/Gerente |
| GET | `/api/v1/announcements/{id}` | Detalhes | Autenticado |
| PUT | `/api/v1/announcements/{id}` | Atualizar | Admin/Gerente |
| DELETE | `/api/v1/announcements/{id}` | Excluir | Admin/Gerente |
| POST | `/api/v1/announcements/{id}/seen` | Marcar como visto | Autenticado |
| POST | `/api/v1/announcements/{id}/ack` | Confirmar recebimento | Autenticado |
| POST | `/api/v1/announcements/{id}/dismiss` | Dispensar | Autenticado |
| POST | `/api/v1/announcements/{id}/publish` | Publicar | Admin/Gerente |
| POST | `/api/v1/announcements/{id}/archive` | Arquivar | Admin/Gerente |
| GET | `/api/v1/announcements/{id}/stats` | Estatísticas | Admin/Gerente |
| GET | `/api/v1/announcements/{id}/receipts` | Lista de recibos | Admin/Gerente |
| POST | `/api/v1/announcements/{id}/duplicate` | Duplicar | Admin/Gerente |
| POST | `/api/v1/announcements/{id}/republish` | Republicar | Admin/Gerente |

### 📅 Endpoints Futuros (se necessário)

| Método | Endpoint | Descrição | Prioridade |
|--------|----------|-----------|------------|
| POST | `/api/v1/announcements/{id}/image` | Upload de imagem | Média |
| POST | `/api/v1/announcements/{id}/preview` | Enviar preview | Baixa |
| GET | `/api/v1/roles` | Listar cargos dinamicamente | Baixa (cargos são fixos) |

---

## 7. Schema JSON Completo para Create/Update

### Criar Comunicado

```json
{
  // Obrigatórios
  "title": "Título do comunicado (max 120 chars)",
  "message": "<p>Conteúdo HTML ou texto plano</p>",
  "type": "recado",           // "recado" | "advertencia"
  "severity": "info",         // "info" | "warning" | "danger"
  "scope": "store",           // "global" | "store" | "user" | "role"

  // Opcionais
  "excerpt": "Resumo curto (max 200 chars, auto-gerado se omitido)",
  "display_mode": "banner",   // "banner" | "modal" | "both" (default: banner)
  "icon": "bell",             // Nome do ícone Lucide
  "image_url": "https://exemplo.com/imagem.jpg",
  "image_alt": "Descrição da imagem",
  "cta_label": "Ler mais",
  "cta_url": "https://exemplo.com/detalhes",
  "require_ack": false,       // Se true, exige botão RECEBIDO
  "starts_at": "2026-01-13T09:00:00Z",  // ISO 8601, null = imediato
  "expires_at": "2026-01-20T18:00:00Z", // ISO 8601, null = nunca
  "repeat_every_minutes": 60, // Só para require_ack=true
  "priority": 10,             // 0-100, maior = mais importante
  "pinned_until": "2026-01-15T23:59:00Z",  // Fixa no topo até esta data
  "meta_json": { "cor_fundo": "#FF0000" }, // Dados customizados

  // Targets (obrigatório se scope != global)
  "targets": [
    { "target_type": "store", "target_id": "1" },
    { "target_type": "store", "target_id": "2" }
  ]
}
```

### Regras de Validação

| Campo | Regra |
|-------|-------|
| `title` | Obrigatório, max 120 caracteres |
| `message` | Obrigatório |
| `type` | Obrigatório, `recado` ou `advertencia` |
| `severity` | Obrigatório, se `type=advertencia` deve ser `danger` |
| `scope` | Obrigatório |
| `targets` | Obrigatório se `scope != global` |
| `expires_at` | Deve ser após `starts_at` |
| `repeat_every_minutes` | Só permitido se `require_ack=true` |

### Erros de Validação (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["O título é obrigatório."],
    "severity": ["Advertências devem ter severidade \"danger\"."],
    "targets": ["Alvos são obrigatórios para este escopo."],
    "expires_at": ["A data de expiração deve ser posterior à data de início."]
  }
}
```

---

## 8. Próximos Passos

### Para o Frontend

1. ✅ Usar `GET /api/v1/stores/all` para seletor de lojas
2. ✅ Usar `GET /api/v1/admin/users` para seletor de usuários (apenas admins)
3. ✅ Usar lista fixa de cargos
4. ✅ Implementar tela de estatísticas usando `/stats`
5. ✅ Implementar lista de recibos usando `/receipts`
6. ✅ Adicionar botão "Duplicar" usando `/duplicate`
7. ✅ Adicionar botão "Republicar" para arquivados/expirados

### Para o Backend (se solicitado)

1. 📅 Endpoint de upload de imagem
2. 📅 WebSocket para notificações em tempo real
3. 📅 Comando artisan para expirar automaticamente

---

**Dúvidas?** Abra uma issue ou entre em contato!
