# PDV Monitor Runbook (PR-21 + PR-38)

Data: 2026-02-12
Escopo: detectar degradacao operacional de ingestao PDV e responder rapido.

## 1) Comando de monitoramento

Comando:

```bash
/opt/plesk/php/8.3/bin/php artisan pdv:ops-monitor --json
```

Checks aplicados:
- backlog da fila `pdv` (`queue_backlog`);
- quantidade de syncs `status=queued` (`queued_syncs`);
- quantidade de `failed_jobs`;
- lojas silenciosas (`stale_stores`) acima do threshold.

Thresholds (configuraveis no `.env`):
- `PDV_MONITOR_MAX_QUEUE_BACKLOG`
- `PDV_MONITOR_MAX_QUEUED_SYNCS`
- `PDV_MONITOR_MAX_FAILED_JOBS`
- `PDV_MONITOR_SILENT_STORE_THRESHOLD_MINUTES`
- `PDV_MONITOR_MAX_STALE_STORES`

Regra de loja silenciosa:
- loja mapeada ativa (`pdv_store_mappings.active = true`)
- sem sync recente por mais de `PDV_MONITOR_SILENT_STORE_THRESHOLD_MINUTES`

## 2) Alertas externos

Canais suportados:
- Webhook generico: `PDV_MONITOR_ALERT_WEBHOOK_URL`
- Slack Incoming Webhook: `PDV_MONITOR_ALERT_SLACK_WEBHOOK_URL`
- E-mail: `PDV_MONITOR_ALERT_EMAILS` (lista separada por virgula)

Controle de spam:
- `PDV_MONITOR_ALERT_COOLDOWN_MINUTES` (default 30 min)
- Cache state key: `PDV_MONITOR_STATE_CACHE_KEY`

## 3) Scheduler (10 em 10 min)

Agendamento:

```php
Schedule::command('pdv:ops-monitor --json')->everyTenMinutes();
```

Validar:

```bash
/opt/plesk/php/8.3/bin/php artisan schedule:list | grep pdv:ops-monitor
```

## 4) Triage de loja silenciosa

Quando o alerta vier com `stale_stores_high`:

1. Confirmar lojas afetadas no payload (`metrics.stale_stores[]`):
```bash
/opt/plesk/php/8.3/bin/php artisan pdv:ops-monitor --json
```

2. Confirmar ultimo sync por loja no backend:
```sql
SELECT store_pdv_id, MAX(received_at) AS last_sync
FROM pdv_syncs
GROUP BY store_pdv_id
ORDER BY last_sync ASC;
```

3. Validar worker/fila:
```bash
redis-cli -h 127.0.0.1 -p 6379 LLEN mc:api:queues:pdv
/opt/plesk/php/8.3/bin/php artisan pdv:infra-check --json
pgrep -af "artisan queue:work"
```

4. Se fila parada, reiniciar processamento:
```bash
/opt/plesk/php/8.3/bin/php artisan queue:restart
/opt/plesk/php/8.3/bin/php artisan queue:work redis --queue=pdv,default --sleep=1 --tries=3 --timeout=180 --max-jobs=500 --max-time=3600
```

5. Se houver falhas de processamento:
```bash
/opt/plesk/php/8.3/bin/php artisan queue:failed
/opt/plesk/php/8.3/bin/php artisan pdv:retry-failed --limit=50
```

6. Validar recuperacao:
```bash
/opt/plesk/php/8.3/bin/php artisan pdv:ops-monitor --json
```

## 5) Acao imediata e escalonamento

Acao imediata:
- se 1 loja silenciosa e fila saudavel: abrir incidente de conectividade/agent para a loja.
- se multiplas lojas silenciosas + backlog alto: tratar como incidente de plataforma backend.

Escalonamento:
- N1: validar fila, worker e tabela `pdv_syncs`.
- N2 (backend): investigar regressao de ingestao/processamento.
- N2 (field/integracao): investigar agente na loja (host offline, task scheduler, rede).

## 6) Criterio de normalidade

- `queue_backlog <= threshold`
- `queued_syncs <= threshold`
- `failed_jobs <= threshold`
- `stale_stores_count <= PDV_MONITOR_MAX_STALE_STORES`
- `pdv:queue-smoke --wait=20` consumindo sem worker manual aberto.
