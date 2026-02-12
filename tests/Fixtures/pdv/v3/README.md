# Fixtures PDV v3 (anonimizadas)

Arquivos:
- `sales_caixa.json`: evento `sales` com venda `HIPER_CAIXA`.
- `mixed_caixa_loja_collision.json`: evento `mixed` com colisao de `id_operacao` entre canais.
- `turno_closure.json`: evento `turno_closure` sem vendas.
- `snapshot_replay_a.json` / `snapshot_replay_b.json`: replay de snapshots com correcoes.

Casos de borda cobertos:
- `responsavel = null`.
- `id_operacao` igual em `HIPER_CAIXA` e `HIPER_LOJA`.
- snapshot corrigindo turno/venda em replay.
