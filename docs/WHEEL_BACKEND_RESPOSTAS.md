# Wheel Module - Backend Respostas para Frontend

> Documento de alinhamento Backend ↔ Frontend  
> Data: 19/01/2026

---

## ✅ Endpoints Implementados (Novos)

| Endpoint | Método | Descrição |
|----------|--------|-----------|
| `/campaigns/{key}/duplicate` | POST | Duplica campanha com segmentos e inventário |
| `/campaigns/{key}/preview` | GET | Dados para preview visual da roleta |
| `/campaigns/{key}/segments/reorder` | POST | Reordena segmentos (array de IDs) |
| `/analytics/detailed` | GET | Métricas completas com filtros |

---

## 📋 Respostas às Perguntas

### Arquitetura

**1. WebSocket ou Polling?**
> Implementado **Ably** (WebSocket gerenciado). A TV assina o canal `wheel:screen:{screen_key}` via Ably SDK.

**2. Heartbeat da TV:**
> Intervalo: **5 minutos** para considerar online. Endpoint `POST /screens/{key}/heartbeat` atualiza `last_seen_at`.

**3. Autenticação da TV:**
> Token **não expira**. Para invalidar, use `POST /screens/{key}/rotate-secret` que gera novo token.

### Negócio

**4. Limite por telefone:**
> - `1_per_campaign` = 1 giro em toda a campanha
> - `1_per_day` = Reset à **meia-noite UTC** (configurável)
> - Se já participou → retorna erro `PHONE_LIMIT_REACHED`

**5. Inventário zerado:**
> Segmento com prêmio esgotado é **automaticamente excluído do sorteio**. O peso é redistribuído para os demais. Se todos esgotarem exceto "nothing"/"try_again", apenas esses aparecem.

**6. Segmentos inativos:**
> Segmentos com `active: false` **não aparecem** na roleta (nem opacos). São ignorados completamente.

**7. Período da campanha:**
> `starts_at/ends_at` são **validados no sorteio**. Campanha fora do período retorna erro `CAMPAIGN_OUT_OF_PERIOD`.

**8. Códigos de resgate:**
> Formato: `{code_prefix}{random}` → `MC-A1B2C3`  
> Random: **6 caracteres** alfanuméricos  
> Unicidade: Garantida por timestamp + random

### Segurança

**9. Anti-fraude:**
> ✅ Validação de formato de telefone  
> ✅ Hash de telefone para comparação (não armazena limpo)  
> ✅ Rate limiting por IP (configurável via middleware)  
> ✅ Lock de sessão (1 giro por vez)  
> ✅ Idempotência via `client_nonce`

**10. Token da TV:**
> - Sem expiração (válido até rotate)
> - Revogação: `POST /screens/{key}/rotate-secret`
> - Desativação: `POST /screens/{key}/set-status` → "inactive"

### Performance

**11. Cache:**
> - Analytics summary: TTL 60s recomendado (não implementado ainda)
> - Segmentos: Invalidados automaticamente em update

**12. Limites:**
> - Máximo de segmentos por campanha: **Sem limite** (recomendamos 8-12)
> - Máximo de campanhas por TV: **1 ativa** (múltiplas inativas permitidas)
> - Máximo de TVs por loja: **Sem limite**

---

## 🔧 Fixes Aplicados (Erro 500)

Corrigidos os Models para gerar keys automaticamente:

| Model | Campo | Geração Automática |
|-------|-------|-------------------|
| WheelScreen | `screen_key` | `screen-{random12}` |
| WheelCampaign | `campaign_key` | `camp_{Y_m}_{random4}` |
| WheelPrize | `prize_key` | `prize_{random8}` |
| WheelSegment | `segment_key` | `seg_{random8}` |

---

## 📊 Analytics Detailed Response

```json
GET /admin/wheel/analytics/detailed?period=week

{
  "success": true,
  "data": {
    "period": { "from": "2026-01-12", "to": "2026-01-19" },
    "totals": {
      "spins": 523,
      "prizes_won": 145,
      "unique_phones": 412,
      "conversion_rate": 27.7
    },
    "by_day": [...],
    "by_campaign": [...],
    "by_prize": [...],
    "inventory_alerts": [...],
    "screens_needing_attention": [...]
  }
}
```

---

## 🚀 Próximos Passos

1. **Deploy** para produção
2. **Configurar ABLY_KEY** no `.env`
3. **Testar** endpoints criação de screen/campaign
4. **Integrar** Frontend com novos endpoints
