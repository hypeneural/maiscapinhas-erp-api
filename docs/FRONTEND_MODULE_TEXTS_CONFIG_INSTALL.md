# 📬 Respostas - Textos, Config e Instalação de Módulos

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 19:00  
> **Status:** ✅ TODOS ENDPOINTS IMPLEMENTADOS!

---

## ✅ Checklist de Respostas

| # | Pergunta | Resposta |
|---|----------|----------|
| 1.1 | PUT /texts existe? | ✅ **SIM** - Implementado! |
| 1.2 | Response format de texts? | ✅ Ver abaixo |
| 1.3 | GET /texts existe? | ✅ **SIM** - Adicionado agora! |
| 2.1 | GET /config existe? | ✅ **SIM** - Já implementado |
| 2.2 | Quais módulos têm config? | ✅ **TODOS** (schema padrão + específico) |
| 2.3 | Configs são iguais por módulo? | ✅ Schema base + override por módulo |
| 3.1 | Como instalar módulo? | ✅ `POST /install` - Implementado |
| 3.2 | Listar módulos disponíveis? | ✅ `/modules` retorna todos |
| 3.3 | Diferença instalar vs ativar? | ✅ Ver explicação abaixo |

---

## 📝 SEÇÃO 1: Textos/Labels

### 1.1 GET `/modules/{id}/texts`

```http
GET /api/v1/admin/modules/pedidos-simples/texts
Authorization: Bearer {token}
```

**Response:**
```json
{
  "module_id": "pedidos-simples",
  "module_name": "Pedidos Simples",
  "texts": {
    "menu_label": "Pedidos Personalizados",
    "menu_tooltip": "Gerenciar pedidos",
    "page_title": "Lista de Pedidos",
    "page_description": "Acompanhe os pedidos",
    "create_button": "Novo Pedido",
    "empty_state": "Nenhum pedido encontrado"
  },
  "defaults": {
    "menu_label": "Pedidos Simples",
    "menu_tooltip": "Gestão rápida de pedidos",
    "page_title": "Pedidos Simples"
  },
  "schema": {
    "menu_label": {"type": "string", "max": 100, "description": "Label no menu lateral"},
    "page_title": {"type": "string", "max": 100, "description": "Título da página principal"},
    "create_button": {"type": "string", "max": 50, "description": "Texto do botão criar"}
  },
  "has_custom_texts": true
}
```

---

### 1.2 PUT `/modules/{id}/texts`

```http
PUT /api/v1/admin/modules/pedidos-simples/texts
Content-Type: application/json

{
  "texts": {
    "menu_label": "Pedidos de Encomenda",
    "page_title": "Controle de Pedidos"
  }
}
```

**Response:**
```json
{
  "message": "Textos atualizados.",
  "data": {
    "menu_label": "Pedidos de Encomenda",
    "page_title": "Controle de Pedidos",
    "create_button": "Novo Pedido"
  }
}
```

---

### Onde cada texto aparece

| Texto | Onde Aparece |
|-------|--------------|
| `menu_label` | Sidebar do admin |
| `menu_tooltip` | Tooltip ao hover no menu |
| `page_title` | Header H1 da página |
| `page_description` | Subtítulo abaixo do H1 |
| `create_button` | Botão "+ Novo" |
| `empty_state` | Mensagem quando tabela vazia |
| `loading_title` | Durante carregamento |
| `error_title` | Quando dá erro |

---

## 🔧 SEÇÃO 2: Configurações

### 2.1 GET `/modules/{id}/config` ✅ JÁ EXISTE

```http
GET /api/v1/admin/modules/pedidos-simples/config
```

**Response:**
```json
{
  "module_id": "pedidos-simples",
  "module_name": "Pedidos Simples",
  "config": {
    "notify_on_status_change": false,
    "notification_channel": "whatsapp",
    "warning_after_days": 5,
    "auto_cancel_days": 20,
    "require_customer_phone": true,
    "require_notes": false
  },
  "schema": {
    "sections": {
      "notifications": {
        "label": "Notificações",
        "icon": "Bell",
        "fields": { ... }
      },
      "deadlines": {
        "label": "Prazos",
        "icon": "Clock",
        "fields": { ... }
      }
    }
  },
  "has_custom_config": false
}
```

---

### 2.2 Quais módulos têm config?

| Módulo | Tem Config? | Configurações |
|--------|-------------|---------------|
| `pedidos-simples` | ✅ SIM | Notificações, Prazos, Requisitos |
| `capas-personalizadas` | ✅ SIM | Notificações, Prazos, Requisitos |
| `fabrica` | ✅ SIM | Produção (sync, tracking), Notificações |

**Todos os módulos têm um schema base herdado do BaseModule**, mas podem sobrescrever com configs específicas.

---

### 2.3 Configs são iguais?

**Schema Base (todos têm):**
- Notificações (notify_on_status_change, channel)
- Prazos (warning_after_days, auto_cancel_days)
- Requisitos (require_customer_phone, require_notes)

**Overrides por módulo:**
- `fabrica`: auto_sync_status, require_tracking_code, require_rejection_reason

---

## 📦 SEÇÃO 3: Instalação

### 3.1 POST `/modules/{id}/install`

```http
POST /api/v1/admin/modules/fabrica/install
Authorization: Bearer {token}
```

**Response:**
```json
{
  "message": "Módulo 'Fábrica' instalado com sucesso.",
  "data": {
    "id": "fabrica",
    "name": "Fábrica",
    "is_installed": true,
    "is_active": true,
    "installed_at": "2026-01-16T19:00:00Z"
  }
}
```

**O que acontece:**
1. ✅ Cria registro no banco (tabela `modules`)
2. ✅ Define como ativo por padrão
3. ✅ Executa hook `onInstall()` do módulo
4. ❌ NÃO cria tabelas (já existem)

---

### 3.2 Listar módulos disponíveis

O endpoint `GET /modules` **já retorna todos**:
- Instalados + não instalados
- Campo `is_installed` indica status

```json
{
  "data": [
    {"id": "pedidos-simples", "is_installed": true, "is_active": true},
    {"id": "capas-personalizadas", "is_installed": true, "is_active": true},
    {"id": "fabrica", "is_installed": true, "is_active": true}
  ]
}
```

---

### 3.3 Diferença entre Instalar e Ativar

```
Não Instalado → [POST /install] → Instalado + Ativo automaticamente
                                          ↓
                              [POST /deactivate] → Instalado + Inativo
                                          ↓
                              [POST /activate] → Instalado + Ativo
```

**Resumo:**
- `install`: Primeira vez, cria registro, já ativa
- `activate/deactivate`: Liga/desliga módulo instalado

---

## 💡 Respostas às Sugestões de UX

### 1. Edição de Textos - Modal ou Seção?

**Recomendação do backend: Modal dedicado**

Razões:
- Uma única requisição GET + PUT
- Permite "Restaurar padrão" facilmente
- Schema inclui descrições para cada campo

---

### 2. Instalação de Módulos - Wizard?

**Suportamos um fluxo simplificado:**

```http
# Passo 1: Instalar (já ativa automaticamente)
POST /modules/{id}/install

# Passo 2: Configurar (opcional)
PATCH /modules/{id}/config

# Passo 3: Ativar para lojas específicas
POST /modules/{id}/stores/{storeId}/activate
```

**Resposta:** Backend suporta requests separadas. Não tem endpoint único para tudo.

---

## 🔐 Permissões

| Ação | Quem pode? |
|------|------------|
| Editar textos | **Super Admin** apenas |
| Instalar módulos | **Super Admin** apenas |
| Configurar módulos | **Super Admin** apenas |
| Ativar/desativar | **Super Admin** apenas |

Todos os endpoints estão no grupo `admin` com middleware `super-admin`.

---

## 📋 Auditoria

**SIM!** Todas as alterações são registradas:

```json
{
  "action": "texts_updated",
  "data": {
    "menu_label": "Novo Label"
  },
  "user_name": "Super Admin",
  "timestamp": "2026-01-16T19:00:00Z",
  "ip_address": "192.168.1.1"
}
```

Ver: `GET /modules/{id}/audit-log`

---

## 📋 Lista de Endpoints Disponíveis

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/modules` | Listar todos (instalados + disponíveis) |
| GET | `/modules/{id}` | Detalhes básicos |
| GET | `/modules/{id}/full` | Detalhes completos |
| POST | `/modules/{id}/install` | Instalar módulo |
| POST | `/modules/{id}/activate` | Ativar globalmente |
| POST | `/modules/{id}/deactivate` | Desativar globalmente |
| GET | `/modules/{id}/texts` | **NOVO** - Buscar textos |
| PUT | `/modules/{id}/texts` | Atualizar textos |
| GET | `/modules/{id}/config` | Buscar config com schema |
| PATCH | `/modules/{id}/config` | Atualizar config |
| POST | `/modules/{id}/config/reset` | Restaurar config padrão |
| GET | `/modules/{id}/audit-log` | Histórico de alterações |

---

*Backend Team - MaisCapinhas - 16/01/2026 19:00*
