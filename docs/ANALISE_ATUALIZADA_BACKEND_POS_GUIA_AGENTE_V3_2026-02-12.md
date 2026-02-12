# Analise Atualizada Backend PDV v3 apos Guia do Agent (2026-02-12)

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Base: "Guia para o Time Backend (PHP/Laravel) - Agent v3.0 Melhorias"

## 1) Resumo executivo

O backend esta alinhado com o contrato v3 na maior parte dos pontos criticos.  
Os ajustes que ainda precisavam de continuidade (principalmente PR-45) foram retomados e executados.

Status consolidado:
- `schema_version`: backend v3-only (`3.0`) em `config/pdv.php`.
- Header/body schema: backend ja valida consistencia; agente informa correção do header para `3.0`.
- TurnoDetail v3 (`duracao_minutos`, `periodo`, `qtd_vendas`, `total_vendas`, `qtd_vendedores`): leitura ja implementada no job.
- Canal em vendas e filhas: implementado com chave canonica por `canal`.
- Warning operacional `GESTAO_DB_FAILURE`: agora mapeado para `risk_flag` dedicado no ingest.
- Tracking de snapshot/cancelamento (PR-45): migration + update no job + comando operacional + scheduler + testes unitarios.

## 2) Matriz guia x backend

## 2.1 Header `X-PDV-Schema-Version`
- Guia: header corrigido para `3.0`.
- Backend: ja valida header suportado e mismatch header/body.
- Estado: `OK`.

## 2.2 Campos novos em `turnos[]`
- Guia: campos v3 agora tambem no detalhe (`turnos[]`).
- Backend: `ProcessPdvSyncJob` ja persiste esses campos.
- Estado: `OK`.

## 2.3 Canal em vendas
- Guia: `canal` continua `HIPER_CAIXA|HIPER_LOJA`.
- Backend: chaves canonicas com `canal` no pai e filhas.
- Estado: `OK`.

## 2.4 Correcao de troco em `HIPER_LOJA`
- Guia: troco nao vem mais duplicado em multi-finalizador.
- Backend: consome `pagamentos[].troco` sem regra incorreta local.
- Estado: `OK` para dados novos.
- Observacao: relatorios historicos podem divergir em periodos antes da correcao do agente.

## 2.5 Warning `GESTAO_DB_FAILURE`
- Guia: warning agora enviado em `integrity.warnings[]`.
- Backend: adicionada derivacao para `risk_flag=gestao_db_failure` no ingest.
- Estado: `OK` (visibilidade operacional habilitada).

## 3) Melhorias executadas nesta rodada

## 3.1 PR-45 (tracking snapshot/cancelamento) - progresso tecnico
- Migration criada: `database/migrations/2026_02_12_000340_add_last_seen_in_snapshot_at_to_pdv_vendas_table.php`.
- Job atualizado: `app/Jobs/ProcessPdvSyncJob.php`
  - Atualiza `pdv_vendas.last_seen_in_snapshot_at` para chaves presentes em `snapshot_vendas[]`.
  - Nao altera status/cancelamento automaticamente.
- Comando novo: `app/Console/Commands/PdvStaleVendasCheckCommand.php`
  - `pdv:stale-vendas-check --json`
  - Detecta vendas recentes nao vistas em snapshot acima do threshold.
- Scheduler atualizado: `routes/console.php`
  - agendamento horario condicionado por flag.
- Config adicionada: `config/pdv.php` + `.env.example`
  - `PDV_STALE_VENDAS_CHECK_ENABLED`
  - `PDV_STALE_VENDAS_THRESHOLD_HOURS`
  - `PDV_STALE_VENDAS_RECENT_WINDOW_DAYS`
  - `PDV_STALE_VENDAS_LIMIT`
- Runbook atualizado: `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md`.

## 3.2 Observabilidade de warning de gestao
- Controller atualizado: `app/Http/Controllers/Api/V1/PdvSyncController.php`
  - `integrity.warnings[]` com prefixo `GESTAO_DB_FAILURE` gera `risk_flag=gestao_db_failure`.

## 4) Validacao executada

- Lint PHP:
  - `app/Console/Commands/PdvStaleVendasCheckCommand.php`
  - `app/Http/Controllers/Api/V1/PdvSyncController.php`
  - `routes/console.php`
  - testes novos/ajustados
- Testes unitarios executados com sucesso:
  - `tests/Unit/Jobs/ProcessPdvSyncJobSnapshotVendasResumoTest.php` (incluindo `last_seen_in_snapshot_at`)
  - `tests/Unit/Console/PdvStaleVendasCheckCommandTest.php`
- Limite conhecido:
  - teste feature de webhook para risk flag novo nao rodou no ambiente atual por restricao de banco de teste (`maiscapinhas_erp_test`) e migracoes globais nao compatveis com sqlite para esse conjunto.

## 4.1 Validacao em banco de provisao (`.env`) - rodada adicional

- Unit tests completos: `40 passed`.
- Smoke real em runtime:
  - `PdvReportsController::turnos` -> `200`, `summary` com novos campos de classificacao.
  - `PdvReportsController::vendas` -> `200`, filtro `meio_pagamento=pix` funcionando.
  - `PdvReportsController::rankingVendedorLoja` -> `200`, agregacao consistente.
- Migration PR-45 aplicada em provisao:
  - `2026_02_12_000340_add_last_seen_in_snapshot_at_to_pdv_vendas_table`.
- Comando PR-45 validado:
  - `pdv:stale-vendas-check --json` (flag enabled) retornando `status=alert` com amostra operacional.
- Observabilidade PR-47 validada:
  - `pdv:ops-monitor --json` inclui metrica `gestao_db_failures_30m` e threshold correspondente.

## 5) Pendencias objetivas (sem perder contexto)

1. Executar migration `000340` no ambiente alvo onde PR-45 sera ativado.
2. Habilitar `PDV_STALE_VENDAS_CHECK_ENABLED=true` apenas apos migration aplicada.
3. Rodar smoke de webhook em ambiente com banco de teste provisionado para validar fluxo feature end-to-end do novo risk flag.
4. Fechar itens funcionais pendentes de PR-42/43/44 (perf/autorizaçao/smoke final).

## 6) Ordem de prioridade atual

1. P1 - PR-42: concluir pendencias de validacao/performance dos filtros de negocio.
2. P1 - PR-43: concluir testes pendentes (loja especifica e autorizacao).
3. P1 - PR-44: fechar consistencia de classificacao no `summary`.
4. P1 - PR-47: concluir visibilidade admin/monitor para `gestao_db_failure`.
5. P2 - PR-45: ativacao operacional controlada apos migration aplicada.
