# PDV v3 Execution Board

Data base: 2026-02-11  
Atualizado em: 2026-02-12  
Fontes principais:
- `docs/TASK_PR_ROADMAP_WEBHOOK_PDV_V3_DETALHADO_2026-02-11.md`
- `docs/REVISAO_CRITICA_PR_TASKS_PDV_SYNC_V3_2026-02-12.md`
- `Guia para o Time Backend (PHP/Laravel) - Agent v3.0 Melhorias (2026-02-12)`
- `docs/ANALISE_ATUALIZADA_BACKEND_POS_GUIA_AGENTE_V3_2026-02-12.md`

## Objetivo
Organizar execucao em PRs independentes, com contexto, dependencias e checklist tecnico para evitar perda de informacao.

## Gate operacional (G0)
Antes de PR funcional v3, garantir:
- PR-18 concluido (worker persistente)
- PR-19 concluido (scheduler recorrente)
- PR-21 concluido (monitor e alerta ativo)

## Historico (Fase 1 - concluido)
PRs concluidos/anteriores permanecem documentados nos arquivos:
- `PR-31` ate `PR-40` nesta mesma pasta.

## Backlog atual (Fase 2 - prioridade de execucao)
1. [PR-42 - P1: Filtros de negocio em turnos e vendas](./PR-42-filtros-negocio-relatorios-pdv.md)
2. [PR-43 - P1: Ranking vendedor x loja por periodo](./PR-43-ranking-vendedor-loja-periodo.md)
3. [PR-44 - P1: Classificacao falta/sobra no fechamento](./PR-44-classificacao-falta-sobra-caixa.md)
4. [PR-47 - P1: Observabilidade de warnings do agente (GESTAO_DB_FAILURE)](./PR-47-observabilidade-warnings-gestao.md)
5. [PR-45 - P2: Tracking de cancelamento via snapshot](./PR-45-tracking-snapshot-cancelamento.md)
6. [PR-46 - Externo: Alinhamento com time do agente JSON](./PR-46-alinhamento-agente-externo.md)

## Estado rapido
- [x] PR-41 (done validado, smoke funcional concluido)
- [x] PR-42 (done tecnico, aguardando feature tests em DB de teste)
- [x] PR-43 (done tecnico, aguardando feature tests em DB de teste)
- [x] PR-44 (done tecnico, aguardando feature tests em DB de teste)
- [x] PR-47 (done tecnico + regra KPI documentada + teste admin criado; execucao feature depende DB de teste)
- [x] PR-45 (done tecnico, ativacao por flag)
- [x] PR-46 (done externo, evidencia sanitizada anexada)

## Novidades do agente v3.0 (2026-02-12)
- Header `X-PDV-Schema-Version` corrigido para `3.0`.
- `turnos[]` agora envia `duracao_minutos`, `periodo`, `qtd_vendas`, `total_vendas`, `qtd_vendedores` no detalhe.
- Correcao de troco em `HIPER_LOJA` para pagamentos multi-finalizador.
- Novo warning operacional: `integrity.warnings[]` com prefixo `GESTAO_DB_FAILURE`.
- Contrato de campo pagamento continua `pagamentos[].meio` (backend converte para `meio_pagamento`).

## Definicoes tecnicas congeladas
- Chave canonica de venda: `(store_pdv_id, canal, id_operacao)`.
- `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA`.
- `line_id` tambem pode colidir entre canais e exige discriminador por `canal` nas filhas.
- `canal` permitido no v3: `HIPER_CAIXA` e `HIPER_LOJA`.
- `snapshot_turnos[]` e `snapshot_vendas[]` sao fonte de verdade atual para reconciliacao.
- `store.id_ponto_venda` e chave global; `store.alias` pode mudar.
- `schema_version` no backend esta v3-only.
- Campo `pagamentos[].meio` nao deve ser renomeado no agente.

## Regra de execucao
- Sempre executar PR por PR na ordem de prioridade.
- Cada PR deve fechar checklist tecnico e criterio de aceite do proprio arquivo.
- Nao iniciar proximo PR com pendencia de dado/integridade no PR anterior.

