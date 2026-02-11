# TASK - Prioridades Redis/Fila + Webhook PDV v2.0

Data: 2026-02-11  
Escopo: estabilidade de ingestao `POST /api/v1/pdv/sync` em producao (Plesk + Redis).  
Fora de escopo: telas e dashboards de negocio.

## 1) Snapshot atual (confirmado)

Ja concluido no codigo:
- webhook idempotente (`sync_id`) com `201` novo e `200` duplicado;
- processamento assincrono por job unico + lock por loja;
- schema versionado (`schema_version`) + `X-Request-Id`;
- idempotencia de filhos com `line_id` (fallback `row_hash`);
- purge de RAW + retry controlado via scheduler (`routes/console.php`);
- observabilidade basica em `/api/v1/admin/pdv/syncs` e `/metrics`.

Infra reportada do servidor:
- Redis `5.0.3`, standalone, localhost-only (`127.0.0.1:6379`), nao exposto externo;
- sem senha (`requirepass` vazio), aceitavel no cenario local-only;
- `maxmemory` alto e politica `noeviction`;
- PHP CLI correto para Artisan: `/opt/plesk/php/8.2/bin/php`;
- Toolkit Plesk disponivel para worker e scheduler.

## 2) Gap real hoje (o que ainda pode quebrar producao)

1. Worker ainda precisa ficar persistente no Laravel Toolkit (nao apenas `queue:work` manual em terminal).
2. Scheduler ainda precisa ficar recorrente no Toolkit (nao apenas `schedule:run` manual).
3. Falta formalizar alerta externo (Slack/WhatsApp/email) para backlog/falhas.
4. Falta disciplina operacional de monitoramento continuo dos indicadores de fila.

## 3) Prioridades (atualizado)

- `P0` ativar fila Redis e operacao baseline sem risco de perda de sync.
- `P1` monitoramento/alertas e disciplina operacional.
- `P2` carga, tuning fino e hardening.

## 4) Backlog por prioridade

## P0 (bloqueante)

### PR-17 - Ativar baseline Redis em producao
Objetivo: tirar o webhook do modo dependente de `sync` e garantir processamento desacoplado.

Subetapas:
- [x] Atualizar `.env.example` com estrutura recomendada Redis:
  - `REDIS_DB=0`, `REDIS_CACHE_DB=1`, `REDIS_PREFIX=mc:api:`;
  - `REDIS_QUEUE_RETRY_AFTER=300`, `REDIS_QUEUE_BLOCK_FOR=5`.
- [x] Parametrizar `block_for` no `config/queue.php` (`REDIS_QUEUE_BLOCK_FOR`).
- [x] Definir `.env` de producao:
  - `QUEUE_CONNECTION=redis`;
  - `CACHE_STORE=redis`;
  - `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`.
- [x] Rodar no servidor:
  - `/opt/plesk/php/8.2/bin/php artisan config:clear`
  - `/opt/plesk/php/8.2/bin/php artisan cache:clear`
  - validacao com `/opt/plesk/php/8.3/bin/php artisan pdv:infra-check --json` (`ok=true`).

Criterio de aceite:
- sync novo gera job em Redis e nao executa inline no request HTTP.

---

### PR-18 - Worker oficial no Laravel Toolkit (Plesk)
Objetivo: manter consumo continuo da fila sem gambiarra de nohup/screen.

Subetapas:
- [ ] Ativar "Fila" no Laravel Toolkit.
- [ ] Configurar worker inicial:
  - `stop-when-empty`: desabilitado;
  - `timeout`: `120` ou `180`;
  - `max-jobs`: `500`;
  - `max-time`: `3600`.
- [ ] Garantir reinicio pos deploy:
  - painel Toolkit, ou
  - `/opt/plesk/php/8.2/bin/php artisan queue:restart`.
- [x] Criar comando de smoke test de worker: `pdv:queue-smoke --wait=20`.
- [x] Validar consumo da fila `pdv` em tempo real durante ingestao com `pdv:queue-smoke` (execucao manual `queue:work` validada em producao).
- [ ] Validar consumo em modo persistente do Toolkit (sem terminal SSH aberto).

Evidencia de producao (2026-02-11):
- com worker manual ativo (`queue:work redis --queue=pdv,default ...`): `pdv:queue-smoke --wait=20` consumiu com sucesso.
- sem worker ativo: `pdv:queue-smoke --wait=20` expirou em 20s (comportamento esperado).

Criterio de aceite:
- fila `pdv` sem backlog crescente em operacao nominal (15 lojas / 10 min).

---

### PR-19 - Scheduler de producao no Toolkit
Objetivo: garantir automacoes (`purge`/`retry`) sem dependencias manuais.

Subetapas:
- [ ] Criar tarefa agendada no Toolkit (cada 1 minuto):
  - `/opt/plesk/php/8.2/bin/php /var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/artisan schedule:run`
- [x] Validar execucao manual dos jobs agendados:
  - `pdv.scheduler.heartbeat` executando via `schedule:run`.
- [ ] Validar execucao recorrente dos jobs agendados (Toolkit):
  - `pdv:purge-raw-payloads` diario;
  - `pdv:retry-failed` a cada 10 min quando habilitado.
- [ ] Confirmar timezone/clock do servidor para consistencia dos horarios.

Criterio de aceite:
- comandos de manutencao executam automaticamente sem intervencao manual.

---

### PR-20 - Seguranca operacional de fila (timeout x retry_after)
Objetivo: evitar redelivery indevido e duplicacao por timeout mal ajustado.

Subetapas:
- [x] Definir regra final:
  - `REDIS_QUEUE_RETRY_AFTER` > timeout max do worker.
- [x] Consolidar baseline sugerido em config/env de referencia:
  - timeout worker `180`;
  - `REDIS_QUEUE_RETRY_AFTER=300`.
- [x] Revisar `tries/backoff` dos jobs PDV:
  - `ProcessPdvSyncJob` agora usa config/env (`PDV_JOB_TRIES`, `PDV_JOB_BACKOFF_SECONDS`).
- [x] Registrar decisao em doc operacional para nao regredir em futuros deploys.

Criterio de aceite:
- job longo nao e reentregue enquanto ainda esta processando.

## P1 (alta)

### PR-21 - Observabilidade de fila e incidentes
Objetivo: detectar ruptura em minutos.

Subetapas:
- [x] Definir rotina operacional com comandos:
  - `queue:failed`, `queue:retry all`, `queue:flush`.
- [x] Criar comando de prontidao `pdv:infra-check` (Redis/queue/cache/scheduler/backlog).
- [x] Adicionar heartbeat de scheduler em cache (`pdv:scheduler:heartbeat`) para deteccao de parada.
- [ ] Monitorar indicadores minimos:
  - backlog por fila;
  - `failed_jobs` por janela;
  - lojas sem sync > 20 min.
- [ ] Integrar alerta externo (Slack/WhatsApp/email).

Criterio de aceite:
- alerta dispara antes de gerar perda operacional perceptivel.

---

### PR-22 - Politica de retencao e limpeza
Objetivo: manter rastreabilidade sem crescer custo/risco.

Subetapas:
- [ ] Confirmar regra final:
  - `pdv_sync_payloads`: 30 dias;
  - `pdv_syncs` (metadados): 12+ meses.
- [ ] Revisar se o purge remove somente RAW e preserva metadados.
- [ ] Adicionar check mensal de tamanho de tabelas e storage.

Criterio de aceite:
- retencao consistente e auditavel sem crescimento descontrolado.

## P2 (media)

### PR-23 - Hardening de carga e resiliencia
Objetivo: validar margem de seguranca antes de escalar tudo.

Subetapas:
- [ ] Teste de carga com retries/outbox (15 lojas).
- [ ] Ajustar batch de upsert conforme telemetria real.
- [ ] Revisar indices de maior custo em consultas/admin.
- [ ] Documentar playbook de incidente:
  - worker parado;
  - Redis indisponivel;
  - backlog alto.

Criterio de aceite:
- sem perda/duplicacao em carga prolongada e com falhas transitorias.

## 5) Ordem recomendada de execucao (faltantes)

1. PR-18
2. PR-19
3. PR-21
4. PR-22
5. PR-23

## 6) Definicao de pronto (infra webhook PDV)

Pronto quando:
- webhook responde rapido e sempre enfileira via Redis;
- worker + scheduler estao ativos no Toolkit;
- timeout/retry_after estao alinhados e documentados;
- backlog/falhas sao monitorados com alerta;
- retencao RAW 30 dias e metadados 12+ meses estao operando.
