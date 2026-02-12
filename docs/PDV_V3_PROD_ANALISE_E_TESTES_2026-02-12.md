# Analise Completa - PDV Sync v3 (producao)

Data: 2026-02-12
Escopo: validar readiness do webhook v3, fila e ingestao real com 6 payloads capturados.

## 1) Resumo Executivo

- Migrations v3 no banco: OK (todas aplicadas, incluindo `canal`, snapshots e tabelas master).
- Endpoint de producao (`/api/v1/pdv/sync`): ainda rejeitando payload v3 com `422`.
- Causa observada no endpoint publicado:
  - `schema_version` invalid (ambiente ainda restrito a `2.0`), e
  - validacao antiga para `snapshot_vendas.*.turno_seq` (exigindo inteiro, sem aceitar `null`).
- Fila: existem syncs `queued` acumulados (sem `processing_started_at`), indicando consumo incompleto.

## 2) Testes Executados

### 2.1 Health endpoint
- URL: `https://api.maiscapinhas.com.br/api/v1/health`
- Resultado: `200 OK`
- Body: `{"data":{"status":"ok",...}}`

### 2.2 Replay dos 6 JSON reais (`C:\Users\Usuario\Desktop\dados`)
- Metodo: extracao de `body` do envelope do n8n e POST para `/api/v1/pdv/sync`
- Header usado: `X-PDV-Schema-Version: 3.0`
- Resultado: `6/6` retornaram `422 validation`

Arquivos/sync_id testados:
- `1.json` -> `391afc859dcd2553f0b8dadab7c194d8ce08a2a2f8b85e88e622c5d391802263` -> 422
- `2.json` -> `09d42385db37ba87f1febcf32a27d67f5f50cb6d4a95cd9f09fd4fe0bd9939e4` -> 422
- `3.json` -> `7178db15b85ef7f1af5e62dca64a8f9fd63dab3fd840e144dd3afe34e3951117` -> 422
- `4.json` -> `de0f78f234bfa3b24b38892d64ea795160cace587b7ecc320b8b33ffa3763ff6` -> 422
- `5.json` -> `1328137588c300e79c2c3d826647f063c1735ff210cd338e2dfe2d5f3888bf80` -> 422
- `6.json` -> `d2bb90f7974e07772753a3460f35c01bff0e08776cbd0d59ce55f7d333cd109e` -> 422

Erro principal retornado:
- `schema_version` invalido
- (em `1.json`) `snapshot_vendas.2.turno_seq must be an integer`

### 2.3 Confirmacao no banco (sync_ids do replay)
- Consulta por `sync_id` dos 6 arquivos: nenhum inserido.
- Confirmacao: as rejeicoes ocorreram no ingress (antes de persistir em `pdv_syncs`).

### 2.4 Backlog atual em `pdv_syncs`
- Totais atuais:
  - `syncs=21`
  - `queued=3`
  - `processing=0`
  - `processed=18`
  - `failed=0`
- Filas paradas observadas: IDs `19`, `20`, `21` em `queued`.

### 2.5 Testes de codigo local
- `php artisan test tests/Unit`: **31 passed**.
- `php artisan test tests/Feature`: falha por acesso negado ao DB de teste (`maiscapinhas_erp_test`), nao por regra de negocio.

## 3) Causa Raiz

1. Endpoint de producao ainda nao esta com comportamento v3 efetivo (config e/ou deploy).
2. Validacao publicada ainda trata `turno_seq` como inteiro obrigatorio no snapshot (na pratica, v3 pode enviar `null`).
3. Consumo de fila nao esta continuo no ambiente de hospedagem (processos longos derrubados).

## 4) Melhorias Aplicadas no Codigo (workspace)

- `PdvSyncIngestRequest`: `snapshot_vendas.*.turno_seq` ajustado para nullable integer.
- Novo comando `pdv:queue-consume` (batch cron-friendly, `--stop-when-empty`, heartbeat).
- Scheduler configurado para rodar `pdv:queue-consume` a cada minuto (feature flag).
- `PdvInfraCheckCommand` atualizado para checar heartbeat do consumidor de fila.
- Config `pdv.php` estendida com flags de cron consumer.
- `.env` local ajustado para baseline v3 + fila redis + cron consumer.
- Runbook criado: `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md`.

## 5) Ajustes Obrigatorios em Producao

### 5.1 Schema versionamento (v3-only)

No codigo atual, o versionamento foi hardcoded para `3.0` em `config/pdv.php`:
- `supported_schema_versions = ['3.0']`
- `json_schema_files['3.0'] = base_path('docs/schema_v3.0.json')`

No `.env`, apenas manter (opcional) o toggle de validacao formal:

```env
PDV_JSON_SCHEMA_VALIDATION_ENABLED=false
```

Durante teste sem auth (temporario):

```env
PDV_AUTH_MODE=none
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=true
```

Pos-teste (recomendado):

```env
PDV_AUTH_MODE=bearer
PDV_ALLOW_NONE_MODE_IN_PRODUCTION=false
```

### 5.2 Fila por cron (shared hosting)

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
PDV_CRON_QUEUE_CONSUMER_ENABLED=true
PDV_CRON_QUEUE_CONSUMER_MAX_TIME=50
PDV_CRON_QUEUE_CONSUMER_SLEEP=1
PDV_CRON_QUEUE_CONSUMER_MEMORY=256
REDIS_QUEUE_RETRY_AFTER=300
REDIS_QUEUE_BLOCK_FOR=5
PDV_WORKER_TIMEOUT_SECONDS=180
```

Cron unico:

```cron
* * * * * /usr/bin/php /caminho/do/projeto/artisan schedule:run >> /dev/null 2>&1
```

### 5.3 Aplicar/recarregar

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:clear
php artisan optimize:clear
```

## 6) Validacao Pos-Deploy (go/no-go)

1. Enviar novamente os 6 JSON (`body` apenas) para `/api/v1/pdv/sync`.
2. Esperado no ingress:
   - `201 Created` (ou `200 duplicate` em replay).
3. Esperado em `pdv_syncs`:
   - `schema_version=3.0`
   - `status=processed`
   - `processing_started_at` e `processed_at` preenchidos.
4. Esperado nas tabelas filhas:
   - `pdv_vendas` com `canal` preenchido.
   - `pdv_turnos` com campos v3 (`responsavel`, `periodo`, `duracao_minutos`, etc.).
   - `pdv_vendas_resumo` populada por `snapshot_vendas`.
5. Executar `php artisan pdv:infra-check --json` e confirmar sem erros criticos.

## 7) Observacoes Importantes

- A API publicada e o workspace local podem estar em revisoes diferentes.
- O fato de `schema_version` ser rejeitado em producao, mesmo com migrations v3 no banco, indica gap de deploy/config no app web.
- A regressao de `turno_seq` em producao confirma que o fix de validacao precisa estar no deploy ativo.

## 8) Reteste apos ajuste de `.env` + cron (2026-02-12 02:29 UTC)

### 8.1 Resultado do reteste

- `GET /api/v1/health`: `200 OK`.
- Replay dos 6 payloads reais v3: `6/6` ainda retornando `422`.
- Erro em todos os 6: `schema_version invalid` (servidor continua com suporte efetivo apenas a v2).

### 8.2 Prova de estado atual do ingress

- Probe manual `schema_version=2.0` foi aceito com `201 created` e `status=queued`.
- `sync_id` probe: `probe-2-042bcbdb9a0a` (registro `id=22` em `pdv_syncs`).

### 8.3 Estado atual da fila apos cron habilitado

- O probe `id=22` ficou em `queued` por mais de 3 minutos (6 polls de 30s).
- Campos continuam `processing_started_at=null` e `processed_at=null`.
- Totais atuais observados:
  - `total=22`
  - `queued=4`
  - `processing=0`
  - `processed=18`
  - `failed=0`

### 8.4 Conclusao do reteste

- Ainda **nao** esta 100%.
- Existem dois bloqueios ativos no ambiente publicado:
  1. Ingress ainda sem suporte efetivo a `schema_version=3.0`.
  2. Consumo de fila nao esta acontecendo (cron/scheduler/queue consumer sem efeito pratico).

### 8.5 Acao imediata recomendada no servidor

1. Confirmar deploy do codigo mais recente (incluindo `PdvSyncIngestRequest` com `turno_seq` nullable).
2. Recarregar config no servidor web:
   - `php artisan optimize:clear`
   - `php artisan config:clear`
   - `php artisan config:cache`
3. Confirmar que o cron roda no mesmo path/deploy da API publicada.
4. Executar manualmente no servidor e observar saida:
   - `php artisan schedule:run`
   - `php artisan pdv:queue-consume --max-time=50 --sleep=1 --memory=256 --json`
5. Repetir replay dos 6 JSON e validar `pdv_syncs.status=processed`.

## 9) Hardening aplicado (v3-only)

Mudancas aplicadas no codigo para operar somente com `schema_version=3.0` por padrao:

- `config/pdv.php`
  - `supported_schema_versions` hardcoded para `['3.0']` (sem env);
  - `json_schema_files` hardcoded apenas com `3.0` (sem env).
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`
  - fallback de versoes suportadas alterado para `['3.0']`.
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
  - fallback de versoes suportadas no header check alterado para `['3.0']`.
- `.env.example`
  - removida configuracao de versao/schema do PDV para evitar ambiguidade;
  - mantido apenas toggle `PDV_JSON_SCHEMA_VALIDATION_ENABLED`.
- Runbooks
  - `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md` atualizado para v3-only.

Validacao local apos hardening:
- `schema_version=3.0` passa na validacao.
- `schema_version=2.0` falha na validacao.

## 10) Estado atual do endpoint publico (reteste rapido)

No momento do reteste externo, a API publica retornou `503 Service Unavailable` (maintenance mode), inclusive em `/api/v1/health`.

Enquanto esse estado persistir, nao e possivel concluir o veredito final de ingestao v3 ponta a ponta no endpoint externo.

## 11) Reteste completo adicional (2026-02-12 02:47 UTC)

### 11.1 Health

- `GET /api/v1/health` voltou `200 OK`.

### 11.2 Probes de schema

- Probe `schema_version=3.0` (`sync_id=probe-schema3-416801ea9b6e`):
  - Resultado: `422 validation`
  - Erro: `schema_version invalid`.
- Probe `schema_version=2.0` (`sync_id=probe-schema2-8f039e4fb2cd`):
  - Resultado: `201 created`
  - Persistido em `pdv_syncs.id=23`, `status=queued`.

Conclusao: endpoint publicado continua aceitando v2 e rejeitando v3.

### 11.3 Replay 6 JSON reais (schema 3)

- `1.json` a `6.json`: `6/6` retornaram `422`.
- Erro em todos: `schema_version invalid`.
- Nenhum dos `sync_id` dos 6 arquivos foi inserido em `pdv_syncs`.

### 11.4 Fila (consumo)

Monitoramento de `sync_id=probe-schema2-8f039e4fb2cd` por ~2 minutos:
- status permaneceu `queued` em todos os polls;
- `processing_started_at=null`;
- `processed_at=null`.

Snapshot de status da fila apos reteste:
- `total=23`
- `queued=5`
- `processing=0`
- `processed=18`
- `failed=0`

Conclusao: fila ainda nao esta consumindo no ambiente publicado.

### 11.5 Veredito deste reteste

- `schema 3`: **reprovado** no endpoint publicado.
- fila/worker via cron: **reprovado** (sem consumo observado).
- estado geral: **nao 100%**.
