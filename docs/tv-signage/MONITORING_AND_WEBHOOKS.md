# TV Digital Signage — Monitoramento Robusto Online/Offline, Endpoints Públicos (Pull) e Webhooks (Push)

## 1. Resumo da Auditoria em Produção (`2026-11-19`)

- **Resolução real das 12 TVs acionadas:** 100% operam em **`541×961` CSS px (`9:16` Vertical / Portrait)**, equivalente a **Full HD Vertical (`1080×1920` @ DPR `2.0`)**.
- **Hardware & Acionamento:** 100% rodam em **Android TV Box WebView** (`Android 14.0; TV BOX -3 Build/QP1A.191105.004; wv`, Chrome `101.0.4951.61`), acionadas diretamente pela URL pública `/tv/loja-loja-{N}-{nome}` e cacheadas via Service Worker PWA.
- **Problemas estruturais corrigidos nesta entrega:**
  1. **Tempestade HTTP `508` (8,48M reqs / 15.924 erros `MHL-E2BIG`):** corrigido loop de heartbeat 1s → **30s** (`TvPlayer.tsx`), polling de playlist 3s → **120s** com invalidação instantânea por `playlist_version`, e `config/cors.php` `max_age: 86400`.
  2. **Contaminação pelo simulador `/admin/tv`:** iframe agora abre com `?preview=1`, ignorado tanto no frontend quanto no backend.
  3. **Sessões zumbis e durações infladas (até 70 dias):** `TvScreenSession::close()` agora encerra em `last_ping_at` (nunca `now()`), e `TvHealthService::recordPing()` usa `DB::transaction` + `lockForUpdate()`.
  4. **Detecção real de queda e retorno:** comando agendado `php artisan tv:monitor-health` (a cada 1 minuto em `routes/console.php`, 100% SQL sem depender de Redis) + desconexão graciosa `POST /api/v1/tv/disconnect/{slug}` (`navigator.sendBeacon`) + integração com `OpeningHoursService` (`stores.opening_hours`).
  5. **Rate limiting seguro atrás de proxy reverso (`127.0.0.1`):** rate limiters `tv-runtime` (por `slugOrToken`) e `tv-status` (por `CF-Connecting-IP`/`X-Forwarded-For`).

---

## 2. Endpoints Públicos Pull (100% Abertos, Somente Leitura)

Nenhum destes endpoints exige autenticação e **nenhum** expõe `access_token`, `screen_key` ou dados internos:

| Endpoint | Descrição | Query Params |
| --- | --- | --- |
| `GET /api/v1/tv/status` | Estado atual de todas as TVs + resumo da frota + últimas atualizações | `status` (CSV), `store_id`, `only_monitored=0\|1`, `only_open_stores=0\|1`, `include_history=0\|1`, `history_limit=1..100`, `events=all\|status\|content\|commands\|errors` |
| `GET /api/v1/tv/status/{slug}` | Detalhe completo de 1 TV (aceita `slug` ou `store_id`) + sessões + histórico | `history_limit=1..100`, `sessions_limit=1..50`, `events=all\|status\|content\|commands\|errors` |
| `GET /api/v1/tv/history` | Feed cronológico global das últimas atualizações e transições | `slug`, `store_id`, `category=all\|status\|content\|commands\|errors`, `event` (CSV), `from`, `to`, `limit=1..100` |

### Limiares de Estado (`config/tv.php`)

- `online`: último ping ≤ **90s** (`TV_ONLINE_THRESHOLD_SECONDS`)
- `degraded`: último ping entre **91s e 180s**, ou ≥ **3 erros de mídia em 15 min**, ou modo `offline_cached`
- `offline`: último ping > **180s** (`TV_OFFLINE_THRESHOLD_SECONDS`) ou desconexão graciosa `pagehide`
- `standby`: `is_blackout = true` (desligada pelo ERP)
- `never_connected`: nunca enviou ping

---

## 3. Webhooks Automáticos Push (Saída)

Configure via:
- Variável de ambiente rápida no `.env`: `TV_WEBHOOK_URL=https://seu-n8n/webhook/tv`
- **E/ou** múltiplas URLs pela aba **Webhooks & Status** no `/admin/tv` (`GET/POST/PUT/DELETE /api/v1/admin/tv/webhooks`, `POST /api/v1/admin/tv/webhooks/{id}/test`, `GET /api/v1/admin/tv/webhooks/deliveries`).

### Eventos Disponíveis
- **Padrão:** `tv.went_offline`, `tv.came_online`
- **Opcionais:** `tv.became_degraded`, `tv.recovered_healthy`, `tv.standby_enabled`, `tv.standby_disabled`, `tv.player_error_spike`, `tv.device_changed`, `tv.playlist_updated`

### Headers Enviados no `POST`
- `Content-Type: application/json`
- `X-TV-Event: tv.went_offline` (ou `tv.came_online`, etc.)
- `X-TV-Delivery-Id: <uuid>`

### Campos Principais do Payload
- `delivery_id`, `event`, `event_label`, `severity` (`critical|warning|ok|info`), `occurred_at`, `occurred_at_local`, `detected_at`
- `summary_text` — texto pronto em PT-BR para envio direto em WhatsApp/Telegram/Slack
- `transition` — `previous_status`, `new_status`, `reason`, `reason_label`, `duration_previous_seconds`, `duration_previous_human`, `downtime_seconds`, `downtime_human`, `during_business_hours`
- `tv` — identificação da loja, resolução (`viewport`, `physical`, `orientation`, `aspect_ratio`), `client` (`launch_mode`, `device_label`, `device_id`), `store_hours` (`is_open_now`, `status_label`), `errors_last_1h`, `uptime_24h_pct`, `status_url`, `player_url`
- `fleet_context` — totais da frota + `concurrent_offline_last_2m` + `possible_server_incident`

---

## 4. Comandos Artisan Operacionais

```bash
# Watchdog agendado a cada 1 minuto em routes/console.php
php artisan tv:monitor-health --json

# Reparo pós-deploy das sessões zumbis/infladas legadas (sem --apply roda em dry-run)
php artisan tv:repair-legacy-sessions
php artisan tv:repair-legacy-sessions --apply
```
