# Perguntas para o Time que Envia o JSON PDV v3

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Objetivo: alinhar contrato v3.0 com foco em relacoes de dados, chaves de idempotencia e ajustes de tabela.

---

## 1) Contexto rapido

Esta doc consolida:
- nossa stack atual (backend receptor do webhook);
- duvidas tecnicas abertas para o time que gera o JSON;
- pedidos de sugestoes de modelagem;
- ajustes de tabela propostos para validacao conjunta.

Referencia relacionada:
- `docs/ANALISE_COMPLETA_WEBHOOK_PDV_SYNC_V3_2026-02-11.md`

---

## 2) Nossa stack atual (estado real)

## 2.1 Runtime e framework

- PHP `^8.2` (`composer.json`)
- Laravel `^12.0` (`composer.json`)
- Auth API com Sanctum (`laravel/sanctum`)
- Validacao de schema JSON com `opis/json-schema`

## 2.2 Endpoint de ingestao

- Rota: `POST /api/v1/pdv/sync`
- Arquivo: `routes/api_v1.php`
- Middlewares: `pdv.signature` + `throttle:pdv`

## 2.3 Seguranca do webhook

- Modos suportados:
  - `hmac`
  - `bearer`
  - `auto` (prioriza HMAC, com fallback bearer opcional)
- Arquivo: `app/Http/Middleware/ValidatePdvSignature.php`

## 2.4 Ingestao e idempotencia

- Idempotencia por `integrity.sync_id` (duplicate retorna 200)
- Persistencia de metadados em `pdv_syncs`
- Persistencia de RAW payload em `pdv_sync_payloads`
- Enfileiramento assincrono para `ProcessPdvSyncJob`
- Arquivo: `app/Http/Controllers/Api/V1/PdvSyncController.php`

## 2.5 Processamento assinc

- Job unico por sync (`ShouldBeUniqueUntilProcessing`)
- Lock por loja (cache lock)
- UPSERT de:
  - `pdv_turnos`
  - `pdv_turno_pagamentos`
  - `pdv_vendas`
  - `pdv_venda_itens`
  - `pdv_venda_pagamentos`
- Arquivo: `app/Jobs/ProcessPdvSyncJob.php`

## 2.6 Banco e infraestrutura

- DB configuravel (mysql/pgsql/sqlsrv/sqlite) via Laravel
- Fila configuravel (`database` ou `redis`)
- Cache configuravel (`database` ou `redis`)
- Monitoramento operacional de fila/sync por comando:
  - `pdv:ops-monitor`
  - `pdv:infra-check`
  - `pdv:retry-failed`
  - `pdv:purge-raw-payloads`

## 2.7 Limites atuais importantes para v3

- Schema validado e mapeado oficialmente ainda em `2.0`
- Nao processamos hoje:
  - `vendas[].canal`
  - `snapshot_turnos[]`
  - `snapshot_vendas[]`
  - `ops.loja_count` e `ops.loja_ids`
- Modelo atual de venda usa chave `store_pdv_id + id_operacao`

---

## 3) Perguntas para o time que envia o JSON

## 3.1 P0 (bloqueantes)

1. `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA` na mesma loja?
2. Se puder colidir, qual chave canonica de venda voces recomendam para dual-database?
3. `vendas[].canal` e sempre obrigatorio na v3.0 ou pode faltar em alguns cenarios?
4. Existe algum caso em que uma mesma venda muda de canal apos emitida?
5. `ops.ids` contem ids de ambos os canais ou apenas de `HIPER_CAIXA`?
6. `ops.loja_ids` representa somente vendas do canal `HIPER_LOJA`?
7. `snapshot_vendas[]` e considerado fonte de verdade para correcoes retroativas recentes?
8. `snapshot_turnos[]` sempre traz os ultimos 10 fechados mesmo sem evento novo?
9. Para snapshot, um registro pode ser removido ou apenas atualizado?
10. Em caso de reprocesso/replay, `snapshot_*` vem igual ao envio anterior ou pode vir diferente?
11. `id_turno` pode ser reutilizado ou alterado?
12. `turnos[].responsavel` e sempre o vendedor principal por itens ou por valor?
13. Quando `responsavel` nao puder ser calculado, o campo vem `null` ou e omitido?
14. `event_type=mixed` significa necessariamente que houve `vendas` e `turnos` no mesmo payload?
15. Podem existir payloads `event_type=sales` com `turnos=[]`?
16. Podem existir payloads `event_type=turno_closure` com `vendas` nao vazio?
17. Existe garantia de ordenacao temporal entre payloads de uma mesma loja?
18. Qual regra oficial para cancelamento/estorno apos envio original?

## 3.2 P1 (alta prioridade)

19. `line_id` e unico por loja ou por operacao?
20. `line_id` pode colidir entre item e pagamento?
21. `line_id` pode ser reciclado depois de muito tempo?
22. `line_no` sempre sera enviado nos itens e pagamentos?
23. Se `line_no` nao vier, qual campo voces recomendam como fallback oficial?
24. `id_finalizador` e universal entre bancos/lojas ou apenas local?
25. `id_produto` e universal entre bancos/lojas ou pode variar?
26. `vendedor.id_usuario` e universal mesmo para venda loja x caixa?
27. Existe possibilidade de `vendedor.id_usuario` nulo em itens validos?
28. `store.id_ponto_venda` e globalmente unico e imutavel?
29. `store.alias` pode mudar ao longo do tempo?
30. Existe campo futuro para identidade global de loja (ex.: `store_external_id`)?

## 3.3 P2 (operacao e evolucao)

31. Qual estrategia de versionamento para schema v3.x (3.0, 3.1, 3.2)?
32. Qual SLA de comunicacao para breaking changes?
33. Voces conseguem publicar JSON schema oficial versionado por release?
34. Podem compartilhar payloads reais anonimizados para casos de borda?
35. Qual volume medio e pico por payload (vendas, itens, pagamentos)?
36. Qual janela maxima de backlog em caso de loja offline por horas?
37. Existe roadmap para eventos explicitos de cancelamento/devolucao?
38. Existe roadmap para enviar hash por linha (`item_hash`/`payment_hash`)?

---

## 4) Pedidos de sugestao para o time do JSON

Precisamos da recomendacao de voces para estas decisoes:

1. Chave canonica de venda em dual-database:
- Voces preferem `store + canal + id_operacao`?
- Ou existe identificador unico global de operacao que possamos usar?

2. Chave canonica de linha:
- Voces recomendam `line_id` como estavel de longo prazo?
- Se nao, qual chave devemos usar para idempotencia de item/pagamento?

3. Politica de reconciliacao:
- Qual regra oficial para considerar snapshot como correcao de dado historico?
- Qual profundidade recomendada de snapshot para confianca operacional?

4. Contrato de campos obrigatorios v3:
- Lista final de campos que nunca podem faltar por tipo de `event_type`.

5. Sinalizacao de mudancas retroativas:
- Melhor forma de avisar backend sobre alteracao antiga (evento dedicado, flag, versao de linha etc.).

---

## 5) Ajustes de tabela propostos (para validacao/sugestao de voces)

| Tema | Estado atual backend | Proposta backend | Pergunta para voces |
|---|---|---|---|
| Identidade de venda | `UNIQUE(store_pdv_id, id_operacao)` | Incluir `canal` na chave | Confere que esta e a chave correta no cenario dual-db? |
| Vendas snapshot | Nao existe `pdv_vendas_resumo` | Criar tabela de resumo com upsert | A chave sugerida `store + canal + id_operacao` esta correta? |
| Turno v3 | Sem `responsavel`, `periodo`, `duracao` | Adicionar colunas v3 em `pdv_turnos` | Algum campo precisa semantica diferente? |
| Snapshot turnos | Nao processado | Upsert de `snapshot_turnos` por `store + id_turno` | Pode haver excecao de chave para turnos antigos? |
| Ops loja | Apenas `ops.count` | Persistir `ops.loja_count` e `ops.loja_ids` | Regra oficial desses campos? |
| Itens/pagamentos | Idempotencia por `line_id` ou `row_hash` | Manter `line_id` como principal e fallback hash | `line_id` e estavel o suficiente para ser chave principal? |
| Normalizacao master | Sem `pdv_lojas/pdv_usuarios/pdv_meios_pagamento` | Criar tabelas master com auto-cadastro | Voces recomendam algum dicionario oficial inicial? |

---

## 6) Relacoes de dados que precisamos fechar por escrito

1. Relacao loja:
- `store.id_ponto_venda` x `store.alias` x identidade global (imutabilidade).

2. Relacao venda:
- `id_operacao` x `canal` x `id_turno` x janela de sync.

3. Relacao turno:
- `operador` x `responsavel` x fechamento x snapshots.

4. Relacao item/pagamento:
- cardinalidade por venda, estabilidade de `line_id`, semantica de `line_no`.

5. Relacao snapshot x evento principal:
- quando snapshot corrige dado anterior e quando apenas confirma dado.

---

## 7) Checklist de resposta minima esperada

Para cada item P0/P1:
- resposta objetiva (sim/nao/regra)
- exemplo JSON
- impacto esperado
- prazo (se houver ajuste no agente)
- responsavel do lado de voces

---

## 8) Template pronto para retorno do time JSON

Use este formato para cada pergunta:

- Pergunta ID:
- Resposta:
- Exemplo:
- Decisao final:
- Impacto para backend:
- Data prevista (se houver ajuste):
- Responsavel:

