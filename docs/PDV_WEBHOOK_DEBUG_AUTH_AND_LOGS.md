# PDV Webhook - Debug temporario (producao)

## Objetivo
- Permitir teste temporario do endpoint `/api/v1/pdv/sync` sem bearer token de integracao.
- Melhorar rastreabilidade de erros e payloads de webhook.

## Configuracao temporaria (modo sem auth)

> Use apenas por janela curta de teste.

No `.env` de producao:

```env
PDV_AUTH_MODE=none
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=true
```

Aplicar config:

```bash
php artisan config:clear
php artisan config:cache
```

## Logs do webhook

Novas chaves:

```env
PDV_LOG_CHANNEL=pdv
PDV_LOG_LEVEL=debug
PDV_LOG_DAILY_DAYS=30
PDV_LOG_PAYLOAD_ON_VALIDATION_ERROR=true
PDV_LOG_PAYLOAD_MAX_CHARS=6000
```

Arquivo de log:

```text
storage/logs/pdv-webhook-YYYY-MM-DD.log
```

Eventos registrados:
- `pdv.sync.received`: request chegou.
- `pdv.sync.ingest`: created/duplicate/queued/blocked.
- `pdv.sync.validation_error`: erro de validacao de contrato/header/timestamp.
- `pdv.sync.request_validation_failed`: erro do FormRequest com contexto e excerpt do payload.
- `pdv.sync.schema_validation`: falha no validador de schema.
- logs de auth no middleware (`auth_mode=none`, token ausente, etc.).

## Rollback (obrigatorio apos testes)

Voltar para auth segura:

```env
PDV_AUTH_MODE=bearer
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=false
```

Aplicar config:

```bash
php artisan config:clear
php artisan config:cache
```

## Opcao mais segura

Se preferir nao abrir o endpoint sem auth, use:

```env
PDV_AUTH_MODE=hmac
PDV_HMAC_SECRET=<segredo-temporario>
PDV_ALLOW_BEARER_FALLBACK=false
```

Assim o webhook continua autenticado durante os testes.
