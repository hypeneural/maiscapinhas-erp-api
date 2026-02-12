# PR-34 - Snapshot de turnos com upsert

Status: `done`  
Prioridade: `P0`  
Dependencias: PR-33

Observacao: validado com suite dedicada do job (`tests/Unit/Jobs/ProcessPdvSyncJobSnapshotTurnosTest.php`).

## Objetivo
Ativar auto-correcao de turnos via `snapshot_turnos[]`.

## Escopo in
- Ler `snapshot_turnos[]` no job.
- Executar upsert por chave `(store_pdv_id, id_turno)`.
- Aplicar precedencia definida (snapshot como verdade mais recente).

## Escopo out
- Snapshot de vendas (PR-35).

## Checklist tecnico

## 1) Estrutura de processamento
- [x] Criar metodo `processSnapshotTurnos()` no `ProcessPdvSyncJob`.
- [x] Chamar metodo no fluxo principal apos `processTurnos()`.
- [x] Definir comportamento "ultimo write wins" para mesmo turno.

## 2) Mapeamento de campos
- [x] Mapear todos os campos disponiveis no snapshot para `pdv_turnos`.
- [x] Cobrir campos novos:
- [x] `responsavel_*`
- [x] `duracao_minutos`
- [x] `periodo`
- [x] `qtd_vendas`
- [x] `total_vendas`
- [x] `qtd_vendedores`
- [x] Garantir null-safe para campos opcionais.

## 3) Log e rastreabilidade
- [x] Logar `snapshot_turnos_count` por sync.
- [x] Registrar risco se snapshot vier malformado.

## 4) Testes
- [x] Snapshot atualiza turno existente com dado mais novo.
- [x] Snapshot cria turno ausente.
- [x] Replay com snapshot diferente corrige dado.
- [x] Nao cria duplicidade.

## Criterio de aceite
- Turnos recentes sao autocorrigidos sempre que snapshot chega.

## Arquivos alvo esperados
- `app/Jobs/ProcessPdvSyncJob.php`
- `tests/*`

## Riscos e mitigacoes
- Risco: sobrescrever dado esperado pelo time com snapshot inesperado.
- Mitigacao: manter trilha de `last_sync_id` e logs de auditoria.

## Validacao manual sugerida
- [ ] Persistir turno com valor antigo.
- [ ] Enviar snapshot com valor corrigido.
- [ ] Validar update apos processamento.
