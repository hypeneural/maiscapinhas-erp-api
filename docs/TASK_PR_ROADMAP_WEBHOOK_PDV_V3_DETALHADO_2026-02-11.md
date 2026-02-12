# TASK + ROADMAP DE PRs - Webhook PDV v3.0 (Detalhado)

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Base de verdade: respostas do time JSON (PDV Sync Agent v3.0.0) + analises internas.

Board executavel (GitHub-friendly):
- `docs/pdv_v3_execution_board/README.md`

---

## 1) Objetivo desta doc

Evitar perda de contexto durante a implementacao do v3.0 no backend, com:
- ordem clara de PRs;
- escopo fechado por PR;
- subetapas objetivas;
- criterios de aceite tecnicos;
- dependencias e riscos.

---

## 2) Decisoes tecnicas ja fechadas (congeladas)

1. Chave canonica de venda no v3:
- `(store_pdv_id, canal, id_operacao)`.

2. `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA`:
- obrigatorio incluir `canal` na chave.

3. `vendas[].canal`:
- sempre presente no v3;
- valores: `HIPER_CAIXA` ou `HIPER_LOJA`.

4. Snapshot:
- `snapshot_turnos[]` e `snapshot_vendas[]` sao fonte de verdade atual;
- snapshots podem mudar entre replays;
- estrategia backend: UPSERT sempre.

5. Identidade:
- `store.id_ponto_venda` e globalmente unico e imutavel;
- `store.alias` pode mudar, nao usar como chave primaria.

6. Linha de item/pagamento:
- `line_id` e estavel e nao reciclado;
- manter fallback por `row_hash` como protecao.

---

## 3) Gates antes de iniciar PR funcional v3

## Gate G0 - Operacao baseline

Dependencias ja mapeadas no `task.md`:
- PR-18 (worker persistente);
- PR-19 (scheduler recorrente);
- PR-21 (monitor com alerta ativo real).

Sem G0 concluido, nao iniciar rollout de PRs funcionais v3 em producao.

---

## 4) Ordem de execucao recomendada

| Ordem | PR | Prioridade | Resultado esperado |
|---|---|---|---|
| 1 | PR-31 | P0 | Contrato v3 aceito no ingress (schema/rules/config) |
| 2 | PR-32 | P0 | `canal` persistido e chave canonica de venda corrigida |
| 3 | PR-33 | P0 | Campos novos de turno v3 persistidos |
| 4 | PR-34 | P0 | `snapshot_turnos[]` processado com UPSERT |
| 5 | PR-35 | P0 | `snapshot_vendas[]` processado com UPSERT em resumo |
| 6 | PR-36 | P0 | `ops.loja_count/loja_ids` persistidos + regras de consistencia |
| 7 | PR-37 | P1 | Tabelas master (`pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`) + auto-cadastro |
| 8 | PR-38 | P1 | Observabilidade v3 (canal, snapshots, lojas silenciosas >2h) |
| 9 | PR-39 | P1 | Endpoints PDV v3 para consulta (turno/vendas/ranking) |
| 10 | PR-40 | P2 | Hardening final (carga, regressao, runbook de go-live) |

---

## 5) Detalhamento por PR

## PR-31 (P0) - Habilitar contrato v3 no ingress

Objetivo:
- aceitar payload v3.0 sem quebrar compatibilidade com v2.0.

Entregaveis:
- suporte a `schema_version=3.0` em config e validacao;
- validacao de novos campos v3 no request;
- arquivo de schema v3 registrado.

Subetapas:
1. Atualizar `config/pdv.php`:
- incluir `'3.0'` em `supported_schema_versions`;
- mapear `json_schema_files['3.0']`.
2. Atualizar `.env.example`:
- `PDV_SUPPORTED_SCHEMA_VERSIONS=2.0,3.0`;
- `PDV_JSON_SCHEMA_FILE_3_0=docs/schema_v3.0.json`.
3. Criar `docs/schema_v3.0.json` (baseado no contrato final do time JSON).
4. Ajustar `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`:
- aceitar `vendas.*.canal`;
- aceitar `snapshot_turnos`, `snapshot_vendas`;
- aceitar `ops.loja_count`, `ops.loja_ids`;
- aceitar novos campos de turno (`responsavel`, `periodo`, `duracao_minutos` etc.).
5. Ajustar testes de ingestao para schema 3.0.

Testes obrigatorios:
- payload v3 valido retorna `201`;
- payload v3 com header/schema inconsistente retorna `422`;
- payload v2 continua funcionando.

Criterio de aceite:
- ambiente aceita v2 e v3 simultaneamente sem regressao.

Dependencias:
- nenhuma funcional, apenas disponibilidade do schema v3 final.

Risco e mitigacao:
- risco: schema v3 incompleto.
- mitigacao: manter validacao schema flagada por env em homologacao ate estabilizar.

---

## PR-32 (P0) - Canal em vendas + chave canonica correta

Objetivo:
- eliminar colisao de `id_operacao` entre canais.

Entregaveis:
- coluna `canal` em `pdv_vendas`;
- unique key nova com canal;
- processamento do job gravando canal.

Subetapas:
1. Migration:
- adicionar `canal VARCHAR(20) NOT NULL DEFAULT 'HIPER_CAIXA'` em `pdv_vendas`;
- dropar unique atual `(store_pdv_id, id_operacao)`;
- criar unique nova `(store_pdv_id, canal, id_operacao)`;
- criar indice para `canal`.
2. Ajustar `ProcessPdvSyncJob`:
- mapear `vendas[].canal` com fallback `HIPER_CAIXA`;
- mudar `upsertRows('pdv_vendas')` para unique com `canal`.
3. Ajustar modelos/queries internos que assumem chave antiga.
4. Atualizar testes de processamento para colisao controlada:
- mesma loja + mesmo `id_operacao` + canais diferentes => duas vendas distintas.

Testes obrigatorios:
- nao sobrescrever venda de canal diferente;
- dedupe continua valido para mesma tripla `(store, canal, id_operacao)`.

Criterio de aceite:
- colisao cross-canal eliminada em homologacao.

Dependencias:
- PR-31.

Risco e mitigacao:
- risco: quebra de consultas existentes que usam chave antiga.
- mitigacao: revisar consultas e adicionar cobertura de teste de regressao.

---

## PR-33 (P0) - Campos de turno v3 (`responsavel`, `periodo`, `duracao`, metricas)

Objetivo:
- persistir completamente os novos dados de turno v3.

Entregaveis:
- colunas novas em `pdv_turnos`;
- mapeamento no job.

Subetapas:
1. Migration em `pdv_turnos`:
- `duracao_minutos INT`;
- `periodo VARCHAR(20)`;
- `responsavel_pdv_id BIGINT NULL`;
- `responsavel_nome VARCHAR(200) NULL`;
- `qtd_vendas INT DEFAULT 0`;
- `total_vendas DECIMAL(14,2) DEFAULT 0`;
- `qtd_vendedores INT DEFAULT 0`.
2. Adicionar indices:
- `(store_id, periodo)`;
- `(store_id, responsavel_pdv_id)`.
3. Atualizar `processTurnos()` no job:
- mapear campos do payload principal;
- preservar fallback seguro para null.
4. Atualizar responses/admin metrics se necessario para exibir novos campos.

Testes obrigatorios:
- turno com `responsavel` preenchido;
- turno com `responsavel=null`;
- UPSERT atualiza campos sem duplicar turno.

Criterio de aceite:
- payload v3 de turno entra sem perda de campo.

Dependencias:
- PR-31.

Risco e mitigacao:
- risco: tipos/precision inadequados.
- mitigacao: validar com amostras reais anonimizadas antes de subir producao.

---

## PR-34 (P0) - Processar `snapshot_turnos[]` com UPSERT

Objetivo:
- ativar auto-correcao de turnos no backend.

Entregaveis:
- pipeline de snapshot_turnos no `ProcessPdvSyncJob`.

Subetapas:
1. Criar metodo dedicado `processSnapshotTurnos()` no job.
2. Definir chave UPSERT:
- `(store_pdv_id, id_turno)`.
3. Definir precedencia:
- processar `turnos[]` primeiro;
- `snapshot_turnos[]` depois (ultimo write wins).
4. Mapear campos novos e antigos de turno no snapshot.
5. Registrar metrica/log de quantidade de snapshots aplicados.

Testes obrigatorios:
- snapshot atualiza turno antigo corretamente;
- snapshot nao cria duplicidade;
- replay com snapshot diferente corrige dado.

Criterio de aceite:
- divergencias recentes de turno sao autocorrigidas pelo snapshot.

Dependencias:
- PR-33.

Risco e mitigacao:
- risco: sobrescrever dado "mais novo" com dado incorreto.
- mitigacao: aplicar somente para mesma loja + mesmo `id_turno` e manter trilha `last_sync_id`.

---

## PR-35 (P0) - Processar `snapshot_vendas[]` + tabela `pdv_vendas_resumo`

Objetivo:
- habilitar auto-correcao e leitura rapida de ultimas vendas por canal.

Entregaveis:
- tabela `pdv_vendas_resumo`;
- processamento de `snapshot_vendas[]`.

Subetapas:
1. Migration `create_pdv_vendas_resumo_table`:
- colunas conforme contrato v3;
- incluir `canal`;
- unique `(store_pdv_id, canal, id_operacao)`.
2. Implementar `processSnapshotVendas()` no job.
3. Mapear vendedor, turno, duracao, totais.
4. Logar volume de snapshot aplicados por sync.
5. Criar indices:
- `(store_pdv_id, vendedor_pdv_id)`;
- `(store_pdv_id, data_hora_inicio)`;
- `(canal)`.

Testes obrigatorios:
- snapshot de venda com canais distintos e mesmo `id_operacao`;
- UPSERT idempotente em replay;
- null-safe em campos opcionais.

Criterio de aceite:
- tabela resumo populada e consistente apos processar payload v3.

Dependencias:
- PR-32, PR-31.

Risco e mitigacao:
- risco: confundir resumo com tabela fato de vendas.
- mitigacao: documentar claramente que `pdv_vendas_resumo` e projeccao de snapshot.

---

## PR-36 (P0) - Persistir `ops.loja_*` e reforcar consistencia de `event_type`

Objetivo:
- separar claramente operacoes de caixa vs loja e melhorar validacao semantica do payload.

Entregaveis:
- metadados `ops.loja_count` e `ops.loja_ids` persistidos;
- validacoes de consistencia por `event_type`.

Subetapas:
1. Migration `pdv_syncs`:
- `ops_loja_count INT DEFAULT 0`;
- `ops_loja_ids JSON NULL`.
2. Ajustar ingestao no controller:
- ler e persistir `ops.loja_count`, `ops.loja_ids`.
3. Regras de consistencia:
- `turno_closure` com `vendas` nao vazio => risk flag;
- `mixed` sem `vendas` ou sem turno fechado => risk flag;
- manter modo nao bloqueante inicialmente (somente flag).
4. Expor campos e riscos no endpoint admin de sync.

Testes obrigatorios:
- persistencia de `ops.loja_*`;
- criacao de risk flags nos cenarios inconsistentes.

Criterio de aceite:
- backend passa a distinguir contagem de operacoes por canal tambem no nivel de sync.

Dependencias:
- PR-31.

Risco e mitigacao:
- risco: bloquear payload valido por regra muito rigida.
- mitigacao: iniciar em modo observabilidade (flag), sem rejeicao hard.

---

## PR-37 (P1) - Tabelas master de normalizacao + auto-cadastro

Objetivo:
- consolidar dimensoes de loja, usuario e meio pagamento para consumo limpo.

Entregaveis:
- `pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`;
- auto-cadastro durante processamento.

Subetapas:
1. Migrations das 3 tabelas master.
2. Criar models correspondentes.
3. Implementar upsert automatico:
- loja a partir de `store.*`;
- usuarios de `operador`, `responsavel`, `vendedor`;
- finalizadores de `vendas.pagamentos` e `turnos.*.por_pagamento`.
4. Definir estrategia de `nome_padronizado` inicial:
- copiar nome de origem no primeiro cadastro.
5. Documentar campos editaveis manualmente pelo time interno.

Testes obrigatorios:
- auto-cadastro sem duplicidade;
- update de nome original sem perder padronizado.

Criterio de aceite:
- dimensoes master populam sozinhas com sync real.

Dependencias:
- PR-32, PR-33, PR-36.

Risco e mitigacao:
- risco: ruidao de nomes por variacao de origem.
- mitigacao: separar `nome_hiper` de `nome_padronizado`.

---

## PR-38 (P1) - Observabilidade v3 (canal, snapshot e loja silenciosa)

Objetivo:
- ampliar visibilidade operacional para o novo contrato v3.

Entregaveis:
- metricas por canal;
- alerta de loja silenciosa >2h;
- metricas de aplicacao de snapshot.

Subetapas:
1. Estender `PdvSyncAdminController`:
- breakdown por `canal` e por `schema_version`;
- ultimos snapshots aplicados.
2. Estender `PdvOpsMonitorCommand`:
- detectar loja sem sync >2h;
- incluir no payload de alerta.
3. Adicionar configuracao de threshold de loja silenciosa em `config/pdv.php`.
4. Atualizar runbook `docs/PDV_MONITOR_RUNBOOK.md`.

Testes obrigatorios:
- metrica/stale por loja;
- alerta dispara com loja silenciosa simulada.

Criterio de aceite:
- operacao consegue identificar rapidamente loja sem envio.

Dependencias:
- PR-36.

Risco e mitigacao:
- risco: falso positivo em loja fechada.
- mitigacao: permitir whitelist/janela por loja em versao seguinte.

---

## PR-39 (P1) - Endpoints PDV v3 para consulta de negocio

Objetivo:
- oferecer API de consulta nativa do PDV v3 para frontend/BI.

Entregaveis:
- endpoint fechamento por turno;
- endpoint vendas com filtro de canal;
- endpoint ranking vendedor baseado em `pdv_*`.

Subetapas:
1. Criar controller dedicado (ex.: `PdvReportsController`).
2. Criar rotas protegidas em `routes/api_v1.php`.
3. Endpoint 1 - fechamento por turno:
- filtros: loja, data, sequencial, periodo.
4. Endpoint 2 - vendas:
- filtros: loja, periodo, vendedor, canal.
5. Endpoint 3 - ranking:
- diario/semanal/mensal por loja/canal.
6. Garantir paginacao e limites.

Testes obrigatorios:
- autorizacao;
- filtros corretos;
- resposta consistente com dados de `pdv_*`.

Criterio de aceite:
- frontend consegue consumir dados v3 sem depender da tabela legacy `sales`.

Dependencias:
- PR-32, PR-33, PR-37.

Risco e mitigacao:
- risco: divergencia entre ranking legacy e ranking PDV.
- mitigacao: publicar criterio de calculo e manter comparativo de validacao.

---

## PR-40 (P2) - Hardening final, regressao e go-live controlado

Objetivo:
- reduzir risco de producao antes de liberar v3 em escala.

Entregaveis:
- suite de testes ampliada;
- validacao de carga;
- checklist de rollout e rollback.

Subetapas:
1. Criar fixtures anonimizadas v3:
- `sales`, `mixed`, `turno_closure`, snapshots completos.
2. Criar testes de job (nao apenas ingestao):
- colisao de `id_operacao` entre canais;
- correcao por snapshot;
- `responsavel=null`.
3. Rodar carga controlada (simulacao 15 lojas + replay).
4. Revisar indices e tempos de processamento.
5. Criar playbook final:
- rollback de migrations de chave;
- desativacao controlada de schema v3 se necessario.

Testes obrigatorios:
- suite verde;
- tempos dentro do limite operacional acordado.

Criterio de aceite:
- go-live v3 pode ser feito por ondas com risco controlado.

Dependencias:
- PR-31 ate PR-39.

Risco e mitigacao:
- risco: regressao no contrato v2.
- mitigacao: manter testes e compatibilidade dual de schema durante rollout.

---

## 6) Task board resumido (executavel)

- [ ] G0 concluido: PR-18, PR-19, PR-21
- [ ] PR-31 mergeado
- [ ] PR-32 mergeado
- [ ] PR-33 mergeado
- [ ] PR-34 mergeado
- [ ] PR-35 mergeado
- [ ] PR-36 mergeado
- [ ] PR-37 mergeado
- [ ] PR-38 mergeado
- [ ] PR-39 mergeado
- [ ] PR-40 mergeado

---

## 7) Criterio de pronto final (DoD v3)

Considerar v3 pronto quando:
1. Payload v3 entra e processa com todas as estruturas novas.
2. Vendas dual-canal nao colidem.
3. Snapshots corrigem dados recentes por UPSERT.
4. `ops.loja_*` esta persistido e observavel.
5. Tabelas master estao populando automaticamente.
6. Monitor alerta loja silenciosa >2h.
7. Endpoints v3 entregam filtros de negocio por canal.
8. Testes de regressao v2 seguem verdes.
