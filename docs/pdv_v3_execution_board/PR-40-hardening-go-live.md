# PR-40 - Hardening e go live controlado

Status: `in_progress`  
Prioridade: `P2`  
Dependencias: PR-31 ate PR-39

## Objetivo
Fechar qualidade, performance e operacao para rollout seguro do v3 em ondas.

## Escopo in
- Cobertura de testes final (ingestao + processamento).
- Teste de carga controlado.
- Checklist de rollout/rollback.

## Escopo out
- Novas features funcionais de negocio.

## Checklist tecnico

## 1) Fixtures e cenarios
- [x] Criar payloads anonimizados v3 para:
- [x] `sales` (canal caixa)
- [x] `mixed` (caixa + loja)
- [x] `turno_closure`
- [x] snapshots completos
- [x] casos de borda:
- [x] `responsavel=null`
- [x] colisao de `id_operacao` entre canais
- [x] replay com snapshot diferente

## 2) Testes automatizados
- [x] Expandir testes de `PdvSyncWebhookTest`.
- [x] Criar testes do `ProcessPdvSyncJob` focados em persistencia real.
- [x] Garantir regressao v2.
- [ ] Executar suite completa em CI.

## 3) Teste de carga
- [x] Simular volume (15 lojas, backlog, replay).
- [x] Medir:
- [x] tempo medio de processamento
- [x] latencia fila
- [x] impacto no banco
- [ ] Ajustar indices/chunks se necessario.

## 4) Operacao e runbook
- [x] Checklist de rollout por ondas:
- [x] onda 1 (1-2 lojas piloto)
- [x] onda 2 (grupo intermediario)
- [x] onda 3 (full)
- [x] Definir gatilhos de rollback.
- [x] Definir comando de validacao pos deploy.

## 5) Rollback plan
- [x] Documentar rollback de migrations sensiveis.
- [x] Documentar retorno temporario para aceitar apenas v2 se necessario.
- [x] Definir comunicacao de incidente.

## Criterio de aceite
- Suite verde.
- Carga dentro do limite operacional aceito.
- Runbook de rollout e rollback aprovado.

## Arquivos alvo esperados
- `tests/*`
- `docs/PDV_MONITOR_RUNBOOK.md`
- `docs/*` de rollout
- scripts auxiliares de carga (se aplicavel)

## Riscos e mitigacoes
- Risco: regressao silenciosa em v2 durante rollout.
- Mitigacao: manter monitoramento por schema_version e canal durante ondas.

## Validacao manual sugerida
- [x] Rodar smoke de fila e ingestao.
- [x] Validar painel admin de syncs e metricas.
- [ ] Confirmar alertas e stale stores funcionando.

## Evidencias de validacao manual
- Ingestao real `sync_id=smoke-pr41-9f4243cc4bd1` retornou `201 created` e processou com sucesso (`pdv_syncs.status=processed`).
- Painel admin (`PdvSyncAdminController@metrics`) retornou:
  - `status_breakdown.processed=33`, `queued=0`, `failed=0`.
  - `stores.active_mapped_stores=12`, `stores.stale_count=11`.
- Observacao: execucao local de `php artisan pdv:ops-monitor --json` depende da extensao PHP Redis no host local; em ambiente sem `ext-redis` o comando falha antes de avaliar alertas.

## Entregaveis implementados
- `tests/Fixtures/pdv/v3/sales_caixa.json`
- `tests/Fixtures/pdv/v3/mixed_caixa_loja_collision.json`
- `tests/Fixtures/pdv/v3/turno_closure.json`
- `tests/Fixtures/pdv/v3/snapshot_replay_a.json`
- `tests/Fixtures/pdv/v3/snapshot_replay_b.json`
- `tests/Fixtures/pdv/v3/README.md`
- `tests/Feature/Api/V1/PdvSyncWebhookTest.php` (fixtures anonimizadas v3)
- `tests/Unit/Jobs/ProcessPdvSyncJobFixtureFilesTest.php`
- `scripts/pdv_v3_load_test.php`
- `docs/PDV_V3_GO_LIVE_ROLLOUT_ROLLBACK.md`
