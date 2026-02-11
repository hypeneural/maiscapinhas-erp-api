# TASK - Prioridades para Recebimento Webhook PDV Sync v2.0

Data: 2026-02-11
Escopo: 100% webhook `POST /api/v1/pdv/sync` (ingestao + processamento + operacao)
Fora de escopo: dashboards de negocio, metas, ranking e telas frontend.

## 1) Delta recebido do time PDV (v2.0)

Melhorias confirmadas no agente que mudam o backlog do backend:

1. Datetimes com timezone explicito (`-03:00`).
2. Campo novo raiz `schema_version` (ex.: `2.0`).
3. Header novo `X-PDV-Schema-Version`.
4. Header novo `X-Request-Id` (UUID unico por tentativa).
5. `line_id` e `line_no` em `vendas[].itens[]`.
6. `line_id` em `vendas[].pagamentos[]`.
7. Classificacao de retry no agente:
   - 4xx = dead_letter (sem retry automatico)
   - 5xx = retry/outbox.

## 2) Estado atual do backend (resumo)

Ja pronto:
- Endpoint, ingestao rapida, idempotencia por `sync_id`, fila, lock por loja e processamento em lote.
- Mapeamento de lojas PDV e comandos operacionais (`purge`/`retry`).
- Fallback Bearer funcional (via middleware atual).
- Parser de datetime aceita timezone explicito e normaliza para UTC.

Gap principal para fechar com o contrato novo:
- `schema_version` ainda nao esta formalizado/persistido em `pdv_syncs`.
- `X-Request-Id` ainda nao esta persistido em `pdv_syncs` (somente contexto de log).
- Sem validacao consistente de `X-PDV-Schema-Version` vs payload.
- Tabelas filhas ainda em estrategia `line_no/row_hash`; falta chave primaria por `line_id`.
- Status HTTP de sucesso para sync novo ainda precisa alinhar para `201` (hoje fluxo principal responde `200`).
- Estrutura de banco ainda com `dateTime`; contrato novo recomenda `TIMESTAMPTZ`.
- `docs/schema_v2.0.json` ainda nao esta no repositorio.

## 3) Priorizacao atualizada

- `P0` Bloqueante: aderir 100% ao contrato v2.0 sem risco de perda (4xx dead_letter).
- `P1` Alta: observabilidade/operacao para estabilizar rollout multi-loja.
- `P2` Media: hardening de carga e tuning final.

## 4) Backlog por prioridade

## P0 (bloqueante)

### PR-09 - Contrato HTTP v2.0 (Bearer + schema headers + codigos corretos)
Objetivo: alinhar autenticacao e semantica HTTP com o novo comportamento de retry do agente.

Subetapas:
- [x] Formalizar modo de autenticacao ativo para producao (`Bearer` agora; `HMAC` opcional futuro).
- [x] Ajustar middleware para nao depender de headers HMAC quando modo ativo for Bearer.
- [x] Validar `Authorization: Bearer` e retornar `401/403` apenas para auth invalida.
- [x] Implementar regra de resposta:
  - `201` para sync novo aceito;
  - `200` para duplicado (`sync_id` ja existente).
- [x] Garantir que erros transitorios internos retornem `5xx` (nao `4xx` indevido).
- [ ] Cobrir com testes automatizados (Bearer valido/invalido, novo=201, duplicado=200).
  - status: casos de teste atualizados em `tests/Feature/Api/V1/PdvSyncWebhookTest.php`, execucao bloqueada por ambiente de teste DB.
- [x] Validacao manual E2E com probe local (`scripts/pdv_ingest_probe.php`) em Bearer e HMAC:
  - primeiro envio `201 created`;
  - segundo envio `200 duplicate`;
  - persistencia e processamento confirmados.

Criterio de aceite:
- Backend nao manda `4xx` por erro interno e nao manda `200` para sync novo.

---

### PR-10 - Versionamento de schema + rastreabilidade por request
Objetivo: suportar roteamento por schema e correlacao completa por tentativa HTTP.

Subetapas:
- [x] Tornar `schema_version` obrigatorio no payload (com whitelist de versoes suportadas).
- [x] Ler `X-PDV-Schema-Version` e validar consistencia com `schema_version` (quando enviado).
- [x] Criar migration para `pdv_syncs.schema_version`.
- [x] Criar migration para `pdv_syncs.request_id`.
- [x] Persistir `schema_version` e `request_id` no ingest.
- [x] Expor filtros por `schema_version` e `request_id` no endpoint admin de syncs.
- [ ] Testes automatizados para schema suportado/nao suportado.
  - status: cenarios adicionados/atualizados em testes de webhook e admin; execucao automatizada bloqueada por ambiente de teste DB.

Criterio de aceite:
- Cada sync fica auditavel por `sync_id` e por `request_id`.

---

### PR-11 - Idempotencia granular por `line_id`
Objetivo: trocar chave de UPSERT de filhos para chave estavel da origem.

Subetapas:
- [ ] Migration `pdv_venda_itens`:
  - adicionar `line_id` (nullable na transicao),
  - manter `line_no` para exibicao,
  - criar unique por `(store_pdv_id, line_id)` (parcial quando `line_id` nao nulo).
- [ ] Migration `pdv_venda_pagamentos`:
  - adicionar `line_id` (nullable na transicao),
  - criar unique por `(store_pdv_id, line_id)` (parcial quando `line_id` nao nulo).
- [ ] Atualizar validacao do request para aceitar `line_id`.
- [ ] Atualizar `ProcessPdvSyncJob`:
  - UPSERT por `line_id` quando presente;
  - fallback `row_hash` apenas quando `line_id` vier ausente.
- [ ] Testes de reprocessamento com:
  - payload completo com `line_id`;
  - payload legado sem `line_id` (fallback).

Criterio de aceite:
- Reprocessar payload nao duplica filhos e nao perde atualizacao de item/pagamento.

---

### PR-12 - Timezone no banco + compatibilidade de datas
Objetivo: alinhar armazenamento com datetimes offsetados do agente.

Subetapas:
- [ ] Planejar migracao `dateTime` -> `timestampTz` nas tabelas `pdv_*` relevantes.
- [ ] Executar migracao com estrategia segura (ambiente produtivo com dados existentes).
- [ ] Revisar casts/models para manter leitura consistente em UTC.
- [ ] Validar consultas de janela e relatorios apos mudanca.
- [ ] Testes E2E com payload real contendo `-03:00`.

Criterio de aceite:
- Filtro por data nao quebra em virada de dia nem em comparacoes de janela.

---

### PR-13 - Publicar e consumir schema formal v2.0
Objetivo: reduzir erro de contrato e evitar 422 indevido.

Subetapas:
- [ ] Versionar `docs/schema_v2.0.json` no repositorio.
- [ ] (Opcional por feature flag) validar payload via JSON Schema no ingest.
- [ ] Padronizar corpo de erro `422` com detalhes do campo invalido.
- [ ] Testes para payload valido e payload invalido conforme schema.

Criterio de aceite:
- Contrato do webhook fica verificavel e reproduzivel.

## P1 (alta)

### PR-14 - Observabilidade e operacao de rollout
Objetivo: detectar rapido qualquer perda de sync por loja.

Subetapas:
- [ ] Incluir `request_id` e `schema_version` em logs estruturados de ingest/process.
- [ ] Expandir metricas de admin para separar:
  - duplicado,
  - processado,
  - failed,
  - bloqueado por validacao.
- [ ] Integrar alerta externo (Slack/WhatsApp/email):
  - loja sem sync > 20 min,
  - aumento de `failed` em janela curta.
- [ ] Revisar rotina `pdv:retry-failed` para evitar requeue de erros deterministas.

Criterio de aceite:
- Time consegue identificar em minutos qual loja/parada/erro ocorreu.

---

### PR-15 - Fechamento de transicao operacional
Objetivo: encerrar fase de migracao sem ambiguidade de contrato.

Subetapas:
- [ ] Confirmar regra final de mapeamento PDV x ERP para todas as lojas.
- [ ] Se aplicavel, desligar modo legado nao utilizado (ex.: fallback de auth nao desejado).
- [ ] Congelar versao de contrato ativa em doc unico de go-live.
- [ ] Validar checklist de producao (workers, scheduler, filas, retention).

Criterio de aceite:
- Operacao fica com um unico contrato ativo e sem regras conflitantes.

## P2 (media)

### PR-16 - Hardening de performance
Objetivo: validar margem de seguranca em carga real.

Subetapas:
- [ ] Teste de carga: 15 lojas, 10 em 10 min, com retries e backlog.
- [ ] Ajustar batch size de upsert/insert.
- [ ] Revisar indices apos telemetria real.
- [ ] Tuning final de worker (`tries`, `timeout`, concorrencia).
- [ ] Documentar playbook de incidente (fila parada, lock, backlog).

Criterio de aceite:
- Sem perda, sem duplicacao e sem degradacao de latencia sob pico.

## 5) Ordem recomendada de execucao

1. PR-09
2. PR-10
3. PR-11
4. PR-12
5. PR-13
6. PR-14
7. PR-15
8. PR-16

## 6) Definicao de pronto (recebimento webhook v2.0)

Consideraremos pronto quando:

- contrato HTTP estiver aderente (`201 novo`, `200 duplicado`, 4xx/5xx corretos);
- schema versionado estiver persistido e auditavel;
- itens/pagamentos estiverem idempotentes por `line_id`;
- datas com timezone estiverem corretas em armazenamento e consulta;
- observabilidade permitir detectar falha por loja em tempo curto.
