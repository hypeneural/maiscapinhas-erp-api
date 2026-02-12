# PDV v3 - Runbook de Ambiente e Fila (shared hosting)

## 1) Schema v3 hardcoded (sem dependencia de `.env`)

Ambiente alvo: aceitar somente `schema_version=3.0`.
No codigo atual, as versoes suportadas e o arquivo de schema foram fixados em `config/pdv.php`:
- `supported_schema_versions = ['3.0']`
- `json_schema_files['3.0'] = base_path('docs/schema_v3.0.json')`

Opcional (recomendado apos estabilizacao):

```env
PDV_JSON_SCHEMA_VALIDATION_ENABLED=true
```

Aplicar:

```bash
php artisan config:clear
php artisan config:cache
```

## 2) Seguranca do webhook

Durante teste temporario:

```env
PDV_AUTH_MODE=none
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=true
```

Voltar para seguro apos teste:

```env
PDV_AUTH_MODE=bearer
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=false
```

## 3) Fila em revenda (sem worker persistente)

Se o provedor derruba processos longos, use consumidor por cron.

### 3.1 Ativar consumidor via scheduler (recomendado)

`.env`:

```env
QUEUE_CONNECTION=redis
PDV_QUEUE_NAME=pdv
PDV_CRON_QUEUE_CONSUMER_ENABLED=true
PDV_CRON_QUEUE_CONSUMER_MAX_TIME=50
PDV_CRON_QUEUE_CONSUMER_SLEEP=1
PDV_CRON_QUEUE_CONSUMER_MEMORY=256
```

Cron unico (a cada minuto):

```cron
* * * * * /usr/bin/php /var/www/.../artisan schedule:run >> /dev/null 2>&1
```

O scheduler executa `pdv:queue-consume` a cada minuto, com stop-when-empty.

### 3.2 Alternativa sem scheduler

Cron direto com lock:

```cron
* * * * * flock -n /tmp/mc-pdv-queue.lock /usr/bin/php /var/www/.../artisan pdv:queue-consume --max-time=50 --sleep=1 --memory=256 >> /dev/null 2>&1
```

## 4) Redis/queue tuning

```env
REDIS_QUEUE_RETRY_AFTER=300
REDIS_QUEUE_BLOCK_FOR=5
PDV_WORKER_TIMEOUT_SECONDS=180
```

Regra: `retry_after` deve ser maior que `worker_timeout`.

## 5) Logs para troubleshooting

```env
PDV_LOG_CHANNEL=pdv
PDV_LOG_LEVEL=debug
PDV_LOG_PAYLOAD_ON_VALIDATION_ERROR=true
PDV_LOG_PAYLOAD_MAX_CHARS=6000
```

Arquivo:

```text
storage/logs/pdv-webhook-YYYY-MM-DD.log
```

Eventos chave:
- `pdv.sync.received`
- `pdv.sync.ingest`
- `pdv.sync.validation_error`
- `pdv.sync.request_validation_failed`
- `pdv.queue.consume.started`
- `pdv.queue.consume.finished`

## 6) Checklist de validacao

1. `php artisan pdv:infra-check --json`
2. `php artisan pdv:queue-smoke --wait=30`
3. Enviar payload v3 real (header `X-PDV-Schema-Version: 3.0`)
4. Confirmar em `pdv_syncs`:
   - `status=processed`
   - `processing_started_at` e `processed_at` preenchidos
5. Confirmar filhos:
   - `pdv_vendas`
   - `pdv_venda_itens`
   - `pdv_venda_pagamentos`
   - `pdv_turnos`
   - `pdv_vendas_resumo`

## 7) Diagnostico rapido em shell

Comando tinker sem erro de aspas:

```bash
php artisan tinker --execute='dump(config("pdv.supported_schema_versions"), config("pdv.cron_queue_consumer_enabled"), app()->basePath());'
```

Se os syncs ficarem em `queued`, validar em sequencia:

```bash
php artisan schedule:list
php artisan schedule:run -v
php artisan pdv:queue-consume --max-time=50 --sleep=1 --memory=256 --json
```
