# PR-51 - Persistencia de Login nas Tabelas Operacionais PDV

Status: `in_progress`  
Prioridade: `P1`  
Tipo: `backend-data`  
Dependencia: `PR-50`

## Objetivo
Persistir `login` recebido no webhook v3.1 para rastreabilidade, auditoria e reconciliao consistente.

## Contexto
- O contrato v3.1 trouxe `login` como identificador estavel de usuario.
- Hoje persistimos principalmente `id_usuario` e `nome`.
- Em cenarios de restore/reimplantacao de base, `id_usuario` pode variar; login tende a permanecer.

## Escopo tecnico
- Adicionar colunas de login nas tabelas relevantes.
- Popular colunas durante processamento de payload e snapshots.
- Atualizar master data (`pdv_usuarios.login_hiper`) usando evidencias do payload.

## Tarefas
- [x] Criar migration para colunas de login:
- [x] `pdv_turnos.operador_login`
- [x] `pdv_turnos.responsavel_login`
- [x] `pdv_venda_itens.vendedor_login`
- [x] `pdv_vendas_resumo.vendedor_login`
- [x] Adicionar indices de apoio:
- [x] `pdv_turnos (operador_login)`
- [x] `pdv_venda_itens (vendedor_login, store_id)`
- [x] `pdv_vendas_resumo (vendedor_login, store_id)`
- [x] Atualizar `ProcessPdvSyncJob` para preencher login:
- [x] turnos detalhe e snapshot.
- [x] itens de venda.
- [x] snapshot_vendas.
- [x] Atualizar `processMasterData()` para manter `pdv_usuarios.login_hiper`.
- [x] Criar backfill inicial de login a partir de `pdv_user_mappings.pdv_user_login`.
- [x] Atualizar testes de job para validar persistencia de login.

## Criterios de aceite
- [x] Novos syncs gravam login nas tabelas operacionais.
- [x] `pdv_usuarios.login_hiper` deixa de ficar vazio para usuarios observados.
- [ ] Relatorios/queries conseguem filtrar por login sem join adicional.

## Verificacao manual
- [ ] Enviar payload com `operador.login` e verificar coluna em `pdv_turnos`.
- [ ] Enviar payload com `itens[].vendedor.login` e verificar `pdv_venda_itens`.
- [ ] Verificar `snapshot_vendas` preenchendo `pdv_vendas_resumo.vendedor_login`.

## Execucao realizada
- Migration `database/migrations/2026_02_13_000360_add_login_columns_to_pdv_operational_tables.php`:
  - colunas de login adicionadas em `pdv_turnos`, `pdv_venda_itens` e `pdv_vendas_resumo`.
  - indices de apoio adicionados.
  - backfill de `pdv_usuarios.login_hiper` via `pdv_user_mappings.pdv_user_login`.
- `app/Jobs/ProcessPdvSyncJob.php`:
  - persistencia de login em turnos (detalhe + snapshot), itens e snapshot_vendas.
  - `processMasterData()` atualizado para manter `pdv_usuarios.login_hiper` a partir do payload e mapping.
- Testes adicionados/atualizados:
  - `tests/Unit/Jobs/ProcessPdvSyncJobLoginPersistenceTest.php`
  - `tests/Unit/Jobs/ProcessPdvSyncJobTurnoV3Test.php`
  - `tests/Unit/Jobs/ProcessPdvSyncJobSnapshotVendasResumoTest.php`
