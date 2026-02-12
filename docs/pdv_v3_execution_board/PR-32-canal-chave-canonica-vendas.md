# PR-32 - Canal e chave canonica de vendas

Status: `done`  
Prioridade: `P0`  
Dependencias: PR-31

Observacao: validado com suite dedicada do job (`tests/Unit/Jobs/ProcessPdvSyncJobCanalTest.php`).

## Objetivo
Eliminar colisao de vendas entre `HIPER_CAIXA` e `HIPER_LOJA` ajustando schema e upsert.

## Escopo in
- Coluna `canal` em `pdv_vendas`.
- Nova unique key de venda com `canal`.
- Ajuste de processamento no `ProcessPdvSyncJob`.

## Escopo out
- Snapshots (PR-34/35).
- Endpoints de consulta por canal (PR-39).

## Checklist tecnico

## 1) Migration
- [x] Criar migration para adicionar `canal` em `pdv_vendas`.
- [x] Tipo recomendado: `VARCHAR(20) NOT NULL DEFAULT 'HIPER_CAIXA'`.
- [x] Backfill de registros antigos para `HIPER_CAIXA`.
- [x] Remover unique antiga `(store_pdv_id, id_operacao)`.
- [x] Criar unique nova `(store_pdv_id, canal, id_operacao)`.
- [x] Criar indice em `canal`.

## 2) Job de processamento
- [x] Atualizar `processVendas()` em `app/Jobs/ProcessPdvSyncJob.php`.
- [x] Ler `vendas[].canal` com fallback seguro `HIPER_CAIXA`.
- [x] Upsert de `pdv_vendas` com nova chave composta.
- [x] Atualizar colunas de update para incluir `canal` quando necessario.

## 3) Validacoes defensivas
- [x] Normalizar canal para uppercase (`HIPER_CAIXA`, `HIPER_LOJA`).
- [x] Se valor invalido: registrar risk flag e usar fallback controlado (decisao de implementacao).

## 4) Testes
- [x] Teste de processamento:
- [x] mesma loja + mesmo `id_operacao` + `canal` diferente = 2 registros.
- [x] mesma tripla `(store, canal, id_operacao)` em replay = nao duplica.
- [x] fallback para payload sem `canal` (compatibilidade) grava `HIPER_CAIXA`.

## 5) Revisao de consultas
- [x] Revisar queries internas que usam `store_pdv_id + id_operacao`.
- [x] Ajustar qualquer join/lookup que precise considerar canal.

## Criterio de aceite
- Colisao cross-canal impossivel no banco e no job.
- Replays permanecem idempotentes.

## Arquivos alvo esperados
- `database/migrations/*_add_canal_to_pdv_vendas*.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `tests/*` (job/feature)

## Riscos e mitigacoes
- Risco: quebra em consultas antigas sem canal.
- Mitigacao: revisar todos os pontos de acesso a `pdv_vendas` antes do merge.

## Validacao manual sugerida
- [ ] Inserir dois payloads com mesmo `id_operacao` e canais diferentes.
- [ ] Verificar duas linhas em `pdv_vendas`.
