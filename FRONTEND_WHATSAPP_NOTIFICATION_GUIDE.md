# 📱 Guia de Integração Frontend: Notificação WhatsApp para Capas Personalizadas

## 📋 Resumo das Melhorias

O endpoint `PATCH /api/capas-personalizadas/{id}/status` foi melhorado para suportar **notificações automáticas via WhatsApp** quando o status é alterado para "Disponível na Loja" (status = 3).

---

## 🔄 Endpoint Modificado

```
PATCH /api/v1/capas-personalizadas/{id}/status
```

### Antes (sem mudanças no uso atual)
```json
{
    "status": 3
}
```

### Agora (com notificação opcional)
```json
{
    "status": 3,
    "notify_whatsapp": true
}
```

---

## 📥 Schema da Request

```typescript
interface UpdateCapaStatusRequest {
    /**
     * Novo status da capa (1-7)
     * @required
     */
    status: CapaStatus;
    
    /**
     * Enviar notificação WhatsApp ao cliente
     * Só tem efeito quando status = 3 (Disponível na Loja)
     * @optional - default: false
     */
    notify_whatsapp?: boolean;
}

// Valores de status
enum CapaStatus {
    ENCOMENDA_SOLICITADA = 1,   // blue
    PRODUTO_INDISPONIVEL = 2,   // red
    DISPONIVEL_LOJA = 3,        // yellow  ← Notificação disponível aqui!
    VENDA_REALIZADA = 4,        // green
    CANCELADA = 5,              // gray
    ENVIADO_PRODUCAO = 6,       // purple/orange
    NO_CARRINHO = 7             // slate
}
```

---

## 📤 Schema da Response

```typescript
interface UpdateCapaStatusResponse {
    message: string;
    data: CapaPersonalizada;
    
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

## 📊 Possíveis Retornos

### ✅ 1. Status atualizado SEM notificação (comportamento padrão)

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
        "status_label": "Disponível na Loja",
        "customer": {
            "id": 1,
            "name": "João Silva",
            "phone": "48999999999"
        },
        "store": {
            "id": 1,
            "name": "Loja Centro",
            "city": "Florianópolis"
        }
        // ... outros campos
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
│                  Alterar Status da Capa                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Status atual: Encomenda Solicitada                         │
│                                                             │
│  Novo status: [Disponível na Loja ▼]                        │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ☑ Notificar cliente por WhatsApp                    │   │
│  │   Será enviada uma mensagem informando que a capa   │   │
│  │   está pronta para retirada.                        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│              [Cancelar]  [Confirmar Alteração]              │
└─────────────────────────────────────────────────────────────┘
```

### Lógica de exibição do checkbox:

```typescript
// Mostrar checkbox apenas quando status selecionado = 3
const showNotifyCheckbox = selectedStatus === 3;

// E cliente tem telefone cadastrado
const canNotify = capa.customer?.phone != null;
```

---

## 💻 Exemplo de Implementação

```typescript
async function updateCapaStatus(
    capaId: number, 
    newStatus: number, 
    notifyWhatsApp: boolean = false
): Promise<UpdateCapaStatusResponse> {
    const response = await api.patch(`/capas-personalizadas/${capaId}/status`, {
        status: newStatus,
        notify_whatsapp: newStatus === 3 ? notifyWhatsApp : undefined,
    });
    
    return response.data;
}

// Uso no componente
const handleStatusChange = async () => {
    try {
        const result = await updateCapaStatus(capaId, 3, notifyByWhatsApp);
        
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

## 📝 Mensagem Enviada ao Cliente

Quando a notificação é bem-sucedida, o cliente recebe:

```
Olá João! 👋

Sua capa personalizada está pronta para retirada! 🎉

📦 *Produto:* Capa Personalizada Floral
🏪 *Loja:* Loja Centro - Florianópolis
📋 *Pedido:* #123

Aguardamos sua visita!
*+MaisCapinhas*
```

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
