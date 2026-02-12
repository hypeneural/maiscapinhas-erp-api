# PR-35 - Snapshot de vendas e tabela resumo

Status: `done`  
Prioridade: `P0`  
Dependencias: PR-31, PR-32

Observacao: validado com suite dedicada do job (`tests/Unit/Jobs/ProcessPdvSyncJobSnapshotVendasResumoTest.php`).

## Objetivo
Processar `snapshot_vendas[]` e manter uma projecao rapida e autocorretiva das ultimas vendas.

## Escopo in
- Criacao de `pdv_vendas_resumo`.
- Upsert de snapshots por chave canonica com canal.

## Escopo out
- Endpoints de consumo dessa tabela (PR-39).

## Checklist tecnico

## 1) Migration `pdv_vendas_resumo`
- [x] Criar tabela `pdv_vendas_resumo`.
- [x] Colunas minimas:
- [x] `store_pdv_id`
- [x] `store_id`
- [x] `canal`
- [x] `id_operacao`
- [x] `data_hora_inicio`
- [x] `data_hora_termino`
- [x] `duracao_segundos`
- [x] `id_turno`
- [x] `turno_seq`
- [x] `vendedor_pdv_id`
- [x] `vendedor_nome`
- [x] `qtd_itens`
- [x] `total_itens`
- [x] `last_sync_id`
- [x] `updated_at`
- [x] Unique key: `(store_pdv_id, canal, id_operacao)`.
- [x] Indices recomendados:
- [x] `(store_pdv_id, data_hora_inicio)`
- [x] `(store_pdv_id, vendedor_pdv_id)`
- [x] `(canal)`

## 2) Processamento no job
- [x] Criar metodo `processSnapshotVendas()`.
- [x] Ler `snapshot_vendas[]`.
- [x] Normalizar canal.
- [x] Upsert por chave `(store_pdv_id, canal, id_operacao)`.
- [x] Rodar apos `processVendas()` para priorizar estado mais novo.

## 3) Log e metrica
- [x] Logar `snapshot_vendas_count`.
- [ ] Logar diffs relevantes em debug (opcional).

## 4) Testes
- [x] Snapshot com mesmo `id_operacao` e canais diferentes cria linhas separadas.
- [x] Snapshot com replay atualiza sem duplicar.
- [x] Campos null nao quebram processamento.

## Criterio de aceite
- `snapshot_vendas[]` passa a atualizar a projecao local em toda sync.

## Arquivos alvo esperados
- `database/migrations/*_create_pdv_vendas_resumo_table.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `tests/*`

## Riscos e mitigacoes
- Risco: usar tabela resumo como fonte fato sem criterio.
- Mitigacao: documentar que e tabela de snapshot/projecao.

## Validacao manual sugerida
- [ ] Enviar payload com snapshot_vendas.
- [ ] Conferir upsert em `pdv_vendas_resumo`.
