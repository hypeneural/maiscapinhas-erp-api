# PR-45 - P2: Tracking de cancelamento via snapshot

Status: `done_tecnico`  
Prioridade: `P2`  
Dependencias: PR-41

## Objetivo
Melhorar observabilidade de possiveis cancelamentos sem evento dedicado no contrato atual.

## Escopo in
- Campo `last_seen_in_snapshot_at` em `pdv_vendas`.
- Atualizacao durante processamento de `snapshot_vendas`.
- Comando de deteccao de vendas stale.

## Escopo out
- Cancelamento automatico de vendas.

## Checklist tecnico

## 1) Migration
- [x] Criar migration para `pdv_vendas.last_seen_in_snapshot_at` (`nullable datetime`).
- [x] Criar index em `last_seen_in_snapshot_at`.

## 2) Processamento no job
- [x] Em `processSnapshotVendas`, mapear chave canonica (`store_pdv_id`, `canal`, `id_operacao`) dos snapshots.
- [x] Atualizar `last_seen_in_snapshot_at = now()` nas vendas correspondentes.
- [x] Garantir que ausencia em snapshot nao altera status automaticamente.

## 3) Monitoramento operacional
- [x] Criar comando `pdv:stale-vendas-check` para detectar vendas nao vistas ha X horas/dias.
- [x] Definir threshold por config.
- [x] Emitir log estruturado para analise operacional.

## 4) Scheduler
- [x] Registrar comando no scheduler (periodicidade: 1h).
- [x] Garantir que comando nao impacte o consumo da fila.

## 5) Testes
- [x] Teste: venda presente no snapshot atualiza `last_seen_in_snapshot_at`.
- [x] Teste: venda ausente nao e atualizada.
- [x] Teste: comando marca corretamente vendas stale.
- [x] Executar smoke no ambiente alvo com migration `000340` aplicada.

## Criterio de aceite
- Time operacional consegue detectar suspeita de cancelamento sem alterar faturamento automaticamente.

## Evidencia de smoke (provisao)
- Migration `2026_02_12_000340_add_last_seen_in_snapshot_at_to_pdv_vendas_table` aplicada com sucesso.
- `Schema::hasColumn('pdv_vendas','last_seen_in_snapshot_at') = true`.
- `php artisan pdv:stale-vendas-check --json` (com flag enabled) retornou `status=alert` com amostra de vendas `last_seen_in_snapshot_at = null`, confirmando trilha operacional ativa.

## Riscos e mitigacoes
- Risco: falso positivo para vendas antigas fora da janela de snapshot.
- Mitigacao: limitar alerta por recencia (`created_at`) e tratar como sinal operacional, nao regra financeira.
