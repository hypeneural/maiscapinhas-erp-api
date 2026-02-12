# Incidente - Cron/Scheduler nao drena fila PDV automaticamente

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Ambiente: Producao (Plesk / revenda)

## 1) Resumo

O webhook PDV v3 esta funcionando na borda de ingestao, mas o consumo automatico da fila via scheduler/cron ainda esta inconsistente.

- Ingestao `schema_version=3.0`: **OK**
- Bloqueio de `schema_version=2.0`: **OK**
- `pdv:queue-consume` manual: **OK** (processa jobs)
- Execucao automatica por `schedule:run` (cron): **NOK** no teste de observacao

## 2) Evidencias objetivas

### 2.1 Ingestao v3 funcionando

- Probe v3: `sync_id=probe3-d231a0b300` -> `201 created`.
- Probe v2: `sync_id=probe2-5a7a5d6f26` -> `422 validation` (`schema_version invalid`).
- Replay dos 6 JSON reais (`C:\Users\Usuario\Desktop\dados`): `6/6` aceitos com `201` (`pdv_sync_id` 25..30).

### 2.2 Processamento manual da fila funcionando

Comando executado com sucesso:

```bash
php artisan pdv:queue-consume --max-time=50 --sleep=1 --memory=256 --json
```

Output reportado mostra `ProcessPdvSyncJob ... DONE` para multiplos jobs.

### 2.3 Processamento automatico via cron/scheduler falhando no teste

Cron configurado:

```cron
* * * * * /opt/plesk/php/8.3/bin/php '/var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/artisan' 'schedule:run'
```

`php artisan schedule:list` lista `pdv:queue-consume` como agendado a cada minuto, porem `schedule:run -v` mostrou apenas `pdv.scheduler.heartbeat` em execucoes sequenciais.

Teste controlado sem execucao manual:

- Inserido `sync_id=probe-auto-5c75d45cfd` (`id=31`, schema `3.0`, status `queued`).
- Monitorado por ~3 minutos.
- Resultado: continuou `queued` sem `processing_started_at`.

Estado atual (apos todos os testes):

- `total=31`
- `queued=2`
- `processed=29`
- `failed=0`

Queued remanescentes:

- `id=19` (`sync_id=ingest-real-20260211023646015`, schema `2.0`) - backlog antigo
- `id=31` (`sync_id=probe-auto-5c75d45cfd`, schema `3.0`) - teste de cron automatico

## 3) Possiveis causas (prioridade)

1. **Mutex stale do scheduler** para `pdv.queue.consume` (overlap lock preso em cache).
2. **Divergencia de config/runtime** entre shell manual e contexto do cron (ex.: `pdv.cron_queue_consumer_enabled`).
3. **Execucao em path/contexto diferente** no painel da hospedagem (basePath/env distintos).
4. **Limitacao da extensao Laravel no Plesk** (executa heartbeat, mas nao dispara todos os eventos due).

## 4) Comandos de diagnostico para a revenda

## 4.1 Corrigir comandos que deram erro de sintaxe

Erro anterior de `Unexpected end of input` foi por aspas nao fechadas.
Use exatamente:

```bash
php artisan tinker --execute="dump(config('pdv.supported_schema_versions'), config('pdv.cron_queue_consumer_enabled'), app()->basePath());"
```

Erro `Command "artisan" is not defined` foi por typo:

```bash
php artisan artisan schedule:run -v
```

Correto:

```bash
php artisan schedule:run -v
```

## 4.2 Checklist tecnico

Rodar nesta ordem e guardar saida:

```bash
php artisan optimize:clear
php artisan config:clear
php artisan config:cache
php artisan schedule:clear-cache
php artisan schedule:list
php artisan schedule:run -vvv
php artisan tinker --execute="dump(config('pdv.supported_schema_versions'), config('pdv.cron_queue_consumer_enabled'), app()->basePath());"
```

Esperado:

- `supported_schema_versions = ['3.0']`
- `cron_queue_consumer_enabled = true`
- `basePath` = `/var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br`
- `schedule:run -vvv` deve executar `pdv.queue.consume` quando houver `queued`

## 5) Mitigacao imediata (recomendada)

Enquanto o scheduler estiver instavel no painel, criar **cron direto** para o consumidor:

```cron
* * * * * /opt/plesk/php/8.3/bin/php '/var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/artisan' 'pdv:queue-consume' '--max-time=50' '--sleep=1' '--memory=256' >> /var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/storage/logs/pdv-queue-cron.log 2>&1
```

Manter tambem o `schedule:run` para os demais jobs.

## 6) Reprocessar pendencias atuais

Para nao deixar `queued` orfao, disparar manualmente (uma vez):

```bash
php artisan tinker --execute="App\\Jobs\\ProcessPdvSyncJob::dispatch(19)->onQueue(config('pdv.queue_name','pdv')); App\\Jobs\\ProcessPdvSyncJob::dispatch(31)->onQueue(config('pdv.queue_name','pdv'));"
php artisan pdv:queue-consume --max-time=50 --sleep=1 --memory=256 --json
```

## 7) Criterio de aceite

Considerar incidente resolvido quando:

1. Novo sync v3 de teste vira `processed` em <= 2 minutos sem comando manual.
2. `queued` nao cresce continuamente.
3. `schedule:run -vvv` mostra execucao de `pdv.queue.consume` em janelas com backlog.
4. Logs de cron confirmam execucao por minuto sem erro de permissao/path.

## 8) Observacao de arquitetura

O backend esta v3-only hardcoded em codigo:

- `supported_schema_versions = ['3.0']`
- `json_schema_files['3.0'] = docs/schema_v3.0.json`

Nao depende de variavel `.env` para versionamento.

## 9) Atualizacao de status (retorno final de validacao)

Apos os ajustes aplicados pela equipe da revenda:

- scheduler voltou a executar `pdv:queue-consume`;
- novos syncs v3 foram processados automaticamente sem comando manual;
- replay real do webhook v3 processou com sucesso.

Status atual:
- incidente principal: RESOLVIDO;
- pendencia residual: 1 registro legado (`id=19`, schema `2.0`) ainda em `queued`, recomendado tratar como legado (failed/rejected) para limpar metricas.
