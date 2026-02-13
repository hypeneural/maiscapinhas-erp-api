# PDV v3 Execution Board

Data base: 2026-02-11  
Atualizado em: 2026-02-13  
Fontes principais:
- `docs/TASK_PR_ROADMAP_WEBHOOK_PDV_V3_DETALHADO_2026-02-11.md`
- `docs/REVISAO_CRITICA_PR_TASKS_PDV_SYNC_V3_2026-02-12.md`
- `Guia para o Time Backend (PHP/Laravel) - Agent v3.0 Melhorias (2026-02-12)`
- `docs/ANALISE_ATUALIZADA_BACKEND_POS_GUIA_AGENTE_V3_2026-02-12.md`
- `docs/DECISOES_BACKEND_POS_RESPOSTAS_TIME_PYTHON_PDV_V3_2026-02-12.md`
- `Guia de Integracao do Webhook v3.1 (Backend) - time agente`
- `docs/ANALISE_IMPACTO_WEBHOOK_V3_1_NORMALIZACAO_2026-02-13.md`

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

## Backlog atual (Fase 3 - Webhook v3.1)
1. [PR-49 - P0: Contrato v3.1 no ingress (schema/versionamento)](./PR-49-contrato-v31-ingress-schema.md)
2. [PR-50 - P0: Resolvers v3.1 (CNPJ first + Login first)](./PR-50-resolvers-v31-cnpj-login-first.md)
3. [PR-51 - P1: Persistencia de login nas tabelas operacionais](./PR-51-persistencia-login-operacional.md)
4. [PR-52 - P1: Observabilidade e documentacao do binding v3.1](./PR-52-observabilidade-e-docs-v31.md)

## Estado rapido (Fase 3)
- [~] PR-49 (em execucao: codigo e testes atualizados, pendente validacao manual em producao)
- [~] PR-50 (em execucao: resolver CNPJ/login implementado, pendente validacao manual/E2E)
- [~] PR-51 (em execucao: persistencia de login implementada e coberta por testes unitarios; pendente validacao manual/E2E)
- [~] PR-52 (em execucao: metricas/contadores de identidade e docs v3.1 atualizados; pendente checklist E2E e exemplos finais)

## Novidades do agente v3.1 (2026-02-13)
- `store.cnpj` no payload como identificador universal da loja.
- `login` em:
- `turnos[].operador.login`
- `turnos[].responsavel.login`
- `vendas[].itens[].vendedor.login`
- `resumo.by_vendor[].login`
- Recomendacao oficial do time agente:
- binding de loja por `cnpj` antes de alias/id.
- binding de usuario por `login` antes de `id_usuario`.

## Definicoes tecnicas congeladas
- Chave canonica de venda: `(store_pdv_id, canal, id_operacao)`.
- `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA`.
- `line_id` tambem pode colidir entre canais e exige discriminador por `canal` nas filhas.
- `canal` permitido no v3: `HIPER_CAIXA` e `HIPER_LOJA`.
- `snapshot_turnos[]` e `snapshot_vendas[]` sao fonte de verdade atual para reconciliacao.
- `store.id_ponto_venda` pode colidir entre lojas no contexto operacional; `store.alias` pode mudar; `cnpj` e a chave forte.
- `schema_version` deve ser alinhado para v3.1 no ingress.
- Campo `pagamentos[].meio` nao deve ser renomeado no agente.

## Regra de execucao
- Sempre executar PR por PR na ordem de prioridade.
- Cada PR deve fechar checklist tecnico e criterio de aceite do proprio arquivo.
- Nao iniciar proximo PR com pendencia de dado/integridade no PR anterior.

