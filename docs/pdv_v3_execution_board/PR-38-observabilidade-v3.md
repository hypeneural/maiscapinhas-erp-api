# PR-38 - Observabilidade v3

Status: `done`  
Prioridade: `P1`  
Dependencias: PR-36

## Objetivo
Dar visibilidade operacional do v3: canal, snapshot e loja silenciosa (>2h).

## Escopo in
- Novas metricas em endpoints admin.
- Alerta de loja sem sync no monitor.
- Atualizacao de runbook.

## Escopo out
- Motor de anomalia avancado (fase posterior).

## Checklist tecnico

## 1) Admin metrics
- [x] Atualizar `PdvSyncAdminController`:
- [x] incluir visao por canal.
- [x] incluir visao por schema_version.
- [x] incluir contagem de snapshots processados (se disponivel).

## 2) Monitor de operacao
- [x] Atualizar `PdvOpsMonitorCommand`:
- [x] detectar lojas sem sync acima de 2h.
- [x] incluir lista no payload de alerta.
- [x] suportar threshold configuravel por env/config.

## 3) Configuracoes
- [x] Adicionar em `config/pdv.php`:
- [x] threshold de loja silenciosa (minutos).
- [x] opcional: max lojas silenciosas toleradas.
- [x] Atualizar `.env.example`.

## 4) Runbook
- [x] Atualizar `docs/PDV_MONITOR_RUNBOOK.md` com:
- [x] diagnostico de loja silenciosa.
- [x] passos de triagem.
- [x] acao imediata e escalonamento.

## 5) Testes
- [x] Teste de metrica stale store.
- [x] Teste de alerta contendo loja silenciosa.
- [x] Teste de regressao do monitor atual.

## Criterio de aceite
- Operacao identifica loja sem sync >2h em metrica e alerta.

## Arquivos alvo esperados
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`
- `app/Console/Commands/PdvOpsMonitorCommand.php`
- `config/pdv.php`
- `.env.example`
- `docs/PDV_MONITOR_RUNBOOK.md`
- `tests/*`

## Riscos e mitigacoes
- Risco: falsos positivos fora de horario operacional.
- Mitigacao: prever janela/whitelist por loja em proxima iteracao.

## Validacao manual sugerida
- [ ] Simular loja sem envio.
- [ ] Rodar metrics e monitor.
- [ ] Verificar payload de alerta.

## Entregaveis implementados
- `database/migrations/2026_02_12_000320_add_snapshot_counts_to_pdv_syncs_table.php`
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`
- `app/Console/Commands/PdvOpsMonitorCommand.php`
- `config/pdv.php`
- `.env.example`
- `docs/PDV_MONITOR_RUNBOOK.md`
- `tests/Unit/Console/PdvOpsMonitorCommandTest.php`
