# 📱 Guia de Integração Frontend: Notificação WhatsApp

## 📋 Resumo das Melhorias

Os endpoints de atualização de status foram melhorados para suportar **notificações automáticas via WhatsApp** quando o status é alterado para "Disponível na Loja" (status = 3).

### Endpoints com suporte a notificação WhatsApp:

| Endpoint | Status para Notificação |
|----------|------------------------|
| `PATCH /api/v1/capas-personalizadas/{id}/status` | 3 (Disponível na Loja) |
| `PATCH /api/v1/pedidos/{id}/status` | 3 (Disponível na Loja) |

---

## 🔄 Endpoint: Capas Personalizadas

```
PATCH /api/v1/capas-personalizadas/{id}/status
```

### Request
```json
{
    "status": 3,
    "notify_whatsapp": true
}
```

### Status Disponíveis

| Valor | Nome | Cor |
|-------|------|-----|
| 1 | Encomenda Solicitada | blue |
| 2 | Produto Indisponível | red |
| **3** | **Disponível na Loja** ✅ | yellow |
| 4 | Venda Realizada | green |
| 5 | Cancelada | gray |
| 6 | Enviado Produção | purple |
| 7 | No Carrinho | slate |

---

## 🔄 Endpoint: Pedidos

```
PATCH /api/v1/pedidos/{id}/status
```

### Request
```json
{
    "status": 3,
    "reason": "Produto chegou",
    "notify_whatsapp": true
}
```

### Status Disponíveis

| Valor | Nome | Cor |
|-------|------|-----|
| 1 | Solicitado | blue |
| 2 | Produto Indisponível | red |
| **3** | **Disponível na Loja** ✅ | yellow |
| 4 | Venda Realizada | green |
| 5 | Cancelado | gray |

---

## 📥 Schema da Request

```typescript
interface UpdateStatusRequest {
    /**
     * Novo status (1-7 para capas, 1-5 para pedidos)
     * @required
     */
    status: number;
    
    /**
     * Motivo da alteração (apenas para pedidos)
     * @optional
     */
    reason?: string;
    
    /**
     * Enviar notificação WhatsApp ao cliente
     * Só tem efeito quando status = 3 (Disponível na Loja)
     * @optional - default: false
     */
    notify_whatsapp?: boolean;
}
```

---

## 📤 Schema da Response

```typescript
interface UpdateStatusResponse {
    message: string;
    data: CapaPersonalizada | Pedido;
    
    /**
     * Resultado da notificação WhatsApp
     * SÓ PRESENTE quando notify_whatsapp=true foi enviado na request
     */
    whatsapp_notification?: WhatsAppNotificationResult;
}

interface WhatsAppNotificationResult {
    /** Se a mensagem foi enviada com sucesso */
    sent: boolean;
    
    /** Telefone mascarado (ex: "****9999") - null se cliente não tem telefone */
    phone: string | null;
    
    /** Mensagem de erro (só presente quando sent=false) */
    error?: string;
}
```

---

## 📊 Exemplos de Retorno

### ✅ 1. Status atualizado SEM notificação

**Request:**
```json
{
    "status": 3
}
```

**Response (200 OK):**
```json
{
    "message": "Status atualizado com sucesso.",
    "data": {
        "id": 1,
        "status": 3,
        "customer": { "name": "João Silva", "phone": "48999999999" },
        "store": { "name": "Loja Centro", "city": "Florianópolis" }
    }
}
```

> ⚠️ **Nota:** Sem o campo `whatsapp_notification` na response.

---

### ✅ 2. Status atualizado COM notificação enviada com SUCESSO

**Request:**
```json
{
    "status": 3,
    "notify_whatsapp": true
}
```

**Response (200 OK):**
```json
{
    "message": "Status atualizado com sucesso.",
    "data": {
        "id": 1,
        "status": 3,
        "customer": { "name": "João Silva" },
        "store": { "name": "Loja Centro", "city": "Florianópolis" }
    },
    "whatsapp_notification": {
        "sent": true,
        "phone": "****9999"
    }
}
```

---

### ⚠️ 3. Status atualizado, mas CLIENTE NÃO TEM TELEFONE

**Response (200 OK):**
```json
{
    "message": "Status atualizado com sucesso.",
    "data": { ... },
    "whatsapp_notification": {
        "sent": false,
        "phone": null,
        "error": "Cliente não possui telefone cadastrado."
    }
}
```

---

### ⚠️ 4. Status atualizado, mas SEM INSTÂNCIA WHATSAPP ATIVA

**Response (200 OK):**
```json
{
    "message": "Status atualizado com sucesso.",
    "data": { ... },
    "whatsapp_notification": {
        "sent": false,
        "phone": null,
        "error": "Nenhuma instância WhatsApp ativa disponível."
    }
}
```

---

### ⚠️ 5. Status atualizado, mas ERRO DE CONEXÃO com WhatsApp

**Response (200 OK):**
```json
{
    "message": "Status atualizado com sucesso.",
    "data": { ... },
    "whatsapp_notification": {
        "sent": false,
        "phone": "****9999",
        "error": "Erro de conexão com WhatsApp: Connection timeout"
    }
}
```

---

### ❌ 6. Erro de validação

**Request:**
```json
{
    "status": 99,
    "notify_whatsapp": "sim"
}
```

**Response (422 Unprocessable Entity):**
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "status": ["Status inválido."],
        "notify_whatsapp": ["O campo notify_whatsapp deve ser verdadeiro ou falso."]
    }
}
```

---

## 🎨 Fluxo Sugerido para UI

```
┌─────────────────────────────────────────────────────────────┐
│                  Alterar Status do Pedido                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Status atual: Solicitado                                   │
│                                                             │
│  Novo status: [Disponível na Loja ▼]                        │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ☑ Notificar cliente por WhatsApp                    │   │
│  │   Será enviada uma mensagem informando que o        │   │
│  │   pedido/capa está pronto para retirada.            │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│              [Cancelar]  [Confirmar Alteração]              │
└─────────────────────────────────────────────────────────────┘
```

### Lógica de exibição do checkbox:

```typescript
// Mostrar checkbox apenas quando status selecionado = 3
const showNotifyCheckbox = selectedStatus === 3;

// E cliente tem telefone cadastrado (opcional, para UX)
const canNotify = item.customer?.phone != null;
```

---

## 💻 Exemplo de Implementação

### Para Capas Personalizadas

```typescript
async function updateCapaStatus(
    capaId: number, 
    newStatus: number, 
    notifyWhatsApp: boolean = false
): Promise<UpdateStatusResponse> {
    const response = await api.patch(`/capas-personalizadas/${capaId}/status`, {
        status: newStatus,
        notify_whatsapp: newStatus === 3 ? notifyWhatsApp : undefined,
    });
    
    return response.data;
}
```

### Para Pedidos

```typescript
async function updatePedidoStatus(
    pedidoId: number, 
    newStatus: number, 
    reason?: string,
    notifyWhatsApp: boolean = false
): Promise<UpdateStatusResponse> {
    const response = await api.patch(`/pedidos/${pedidoId}/status`, {
        status: newStatus,
        reason: reason,
        notify_whatsapp: newStatus === 3 ? notifyWhatsApp : undefined,
    });
    
    return response.data;
}
```

### Uso no componente

```typescript
const handleStatusChange = async () => {
    try {
        const result = await updatePedidoStatus(pedidoId, 3, 'Produto chegou', notifyByWhatsApp);
        
        // Verifica resultado da notificação
        if (result.whatsapp_notification) {
            if (result.whatsapp_notification.sent) {
                toast.success(`Notificação enviada para ${result.whatsapp_notification.phone}`);
            } else {
                toast.warning(`Status atualizado, mas notificação falhou: ${result.whatsapp_notification.error}`);
            }
        } else {
            toast.success('Status atualizado com sucesso!');
        }
    } catch (error) {
        toast.error('Erro ao atualizar status');
    }
};
```

---

## 📝 Mensagens Enviadas ao Cliente

### Para Capas Personalizadas

```
Olá João! 👋

Sua capa personalizada está pronta para retirada! 🎉

📦 *Produto:* Capa Personalizada Floral
🏪 *Loja:* Loja Centro - Florianópolis
📋 *Pedido:* #123
👤 *Você foi atendido por:* Maria Silva

Aguardamos sua visita!
*+MaisCapinhas*
```

### Para Pedidos

```
Olá João! 👋

Seu pedido está disponível para retirada! 🎉

📦 *Produto:* Película de Vidro
🏪 *Loja:* Loja Centro - Florianópolis
📋 *Pedido:* #456
👤 *Você foi atendido por:* Maria Silva

Aguardamos sua visita!
*+MaisCapinhas*
```

> 📌 **Nota:** O nome do vendedor que criou o pedido/capa é automaticamente incluído na mensagem!

---

## ❓ FAQ

**P: O que acontece se eu enviar `notify_whatsapp: true` para um status diferente de 3?**
R: O parâmetro é ignorado. A notificação só é enviada quando `status = 3`.

**P: O status é atualizado mesmo se a notificação falhar?**
R: Sim! O status é SEMPRE atualizado. A falha na notificação é apenas informada na response.

**P: Qual instância WhatsApp é usada?**
R: O sistema busca nesta ordem: 1) Instância da loja → 2) Instância global padrão.

**P: Preciso mudar algo se não quiser usar notificação?**
R: Não! O endpoint funciona exatamente como antes. O parâmetro `notify_whatsapp` é opcional.

**P: O nome do vendedor sempre aparece na mensagem?**
R: Sim, se o pedido/capa tiver um vendedor associado (user_id), o nome dele será incluído automaticamente.

---

## 📊 Resumo de Alterações por Endpoint

| Endpoint | Parâmetro Novo | Status Afetado |
|----------|----------------|----------------|
| `PATCH /api/v1/capas-personalizadas/{id}/status` | `notify_whatsapp` | 3 |
| `PATCH /api/v1/pedidos/{id}/status` | `notify_whatsapp` | 3 |

Ambos os endpoints agora incluem o **nome do vendedor** na mensagem enviada ao cliente.

