# Decisoes Backend - Pos Respostas do Time Python (Webhook PDV)

Data: 2026-02-13  
Projeto: `maiscapinhas-erp-api`

## 1) Decisoes confirmadas com o time Python

1. O agente mantera `schema_version=3.0` por enquanto, mesmo enviando `store.cnpj` e campos `login`.
2. `duracao_minutos` pode vir `null` em turnos abertos (`turnos[]` e `snapshot_turnos[]`).
3. `window.minutes` e informativo de configuracao; para calculo real usar `window.to - window.from`.
4. `snapshot_turnos[]` e a fonte mais autoritativa para totais consolidados do turno.
5. `login` pode vir `null` em snapshots e nao ha fallback no agente.

## 2) Ajustes aplicados no backend

### Ingress validation

Arquivo: `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`

- `store.cnpj` passou para `nullable`.
- `turnos.*.duracao_minutos` passou para `nullable`.
- `snapshot_turnos.*.duracao_minutos` passou para `nullable`.

### JSON Schema v3.0

Arquivo: `docs/schema_v3.0.json`

Incluidos como opcionais:
- `store.cnpj`
- `operatorInfo.login`
- `sellerInfo.login`
- `summaryByVendor.login`

Objetivo: evitar falha quando `PDV_JSON_SCHEMA_VALIDATION_ENABLED=true` e o agente enviar payload `3.0` com campos extras.

### Documentacao do endpoint

Arquivo: `app/Http/Controllers/Api/V1/PdvSyncController.php`

- DocBlock atualizado para indicar que `login` faz parte do contrato atual em payloads `3.0/3.1`.

### Testes adicionados

Arquivo: `tests/Feature/Api/V1/PdvSyncWebhookTest.php`

- Aceite de payload `3.0` com `duracao_minutos=null` em turnos abertos.
- Aceite de payload `3.0` com `cnpj/login` quando JSON Schema esta habilitado.

## 3) Pontos ainda pendentes de validacao operacional

1. Revalidar E2E em ambiente de producao/provisao com payload real do n8n.
2. Confirmar que nao ha mais `422` por `duracao_minutos`.
3. Confirmar logs de risco em casos de turnos abertos antigos repetidos (ruido esperado da base).

## 4) Regra operacional para dashboards/relatorios

1. Para consolidado de turno, priorizar `snapshot_turnos`.
2. Para diagnostico de execucao da janela atual, usar `turnos`.
3. Para metricas de cobertura temporal, calcular com `window.from/window.to` e nao com `window.minutes`.

