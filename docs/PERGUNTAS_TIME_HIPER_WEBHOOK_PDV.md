# Perguntas Tecnicas para o Time Hiper (Webhook PDV 10min)

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Canal sugerido: reuniao tecnica + documento de respostas versionado

## 1) Objetivo desta doc

Alinhar 100% o contrato tecnico entre:
- Time que gera/envia o JSON do ERP Hiper via webhook a cada 10 minutos.
- Nosso backend que recebe, valida, enfileira e processa os dados de forma idempotente.

Meta: evitar retrabalho, duplicacao, perda de dados e divergencia de regra de negocio.

## 2) Nossa stack e contrato atual (contexto para voces)

## 2.1 Stack backend atual

- Framework: Laravel 12 (API REST).
- Endpoint de ingestao: `POST /api/v1/pdv/sync`.
- Seguranca: HMAC global (`X-PDV-Signature`) + `X-PDV-Timestamp`.
- Idempotencia:
  - `sync_id` unico por payload/janela.
  - `id_operacao` por loja para venda.
- Processamento:
  - Ingestao rapida (ACK imediato).
  - Processamento assincrono em fila (queue).
  - Lock por loja para manter ordem e evitar corrida.
- Persistencia:
  - RAW payload (`pdv_sync_payloads`) com retencao planejada de 30 dias.
  - Metadados e status de sync (`pdv_syncs`) para auditoria/monitoramento.
  - Tabelas normalizadas de turnos/vendas/itens/pagamentos (`pdv_*`).
- Operacao:
  - Scheduler para limpeza de RAW e retry controlado de falhas.
  - Endpoints admin de monitoramento (`/api/v1/admin/pdv/syncs` e `/metrics`).

## 2.2 Politicas ja decididas aqui

- HMAC global.
- Timestamp de referencia de 10 min.
- Se `sync_id` duplicado: responder 200 (idempotente), sem bloquear por janela.
- Para `sync_id` novo:
  - modo `strict`: rejeita fora da janela;
  - modo `tolerant` (preferido se offline for comum): aceita e marca risco.

## 3) Perguntas P0 (bloqueantes para go-live)

## 3.1 Seguranca e autenticacao

1. Voces conseguem assinar no formato `hash_hmac('sha256', timestamp + "." + rawBody, secret)`?
2. Voces enviarao `X-PDV-Signature` em hex puro ou com prefixo `sha256=`?
3. Qual estrategia de rotacao de segredo HMAC voces preferem?
4. Em caso de comprometimento do segredo, qual o tempo de troca operacional esperado?
5. Conseguem enviar `X-Request-Id` unico por tentativa para rastreio ponta a ponta?

## 3.2 Semantica de idempotencia e retry

6. Confirmam que `integrity.sync_id` e deterministico por `store + window.from + window.to`?
7. Em retry de outbox, o payload e reenviado byte a byte igual (mesmo `sync_id`)?
8. Qual politica de retry do agente hoje? (intervalos, max tentativas, TTL de outbox)
9. Podem ocorrer reenvios fora de ordem de janela? Ex.: enviar janela 16:40 antes da 16:30.
10. Em queda longa de rede, qual o backlog maximo esperado por loja?

## 3.3 Contrato de tempo e timezone

11. Os datetimes do payload terao offset (`-03:00`) ou estao sem timezone?
12. Conseguem padronizar para ISO-8601 com timezone explicito (ou UTC com `Z`)?
13. `agent.sent_at` representa horario de geracao local da loja ou horario UTC?
14. Existe sincronizacao NTP nas maquinas das lojas para reduzir skew?

## 3.4 Integridade de dados de venda/turno

15. `id_operacao` e imutavel e unico por loja para sempre?
16. `id_turno` GUID e imutavel e nunca reutilizado?
17. `turnos[].totais_sistema.total` sempre representa acumulado completo do turno (nao so janela)?
18. `falta_caixa.total` sempre obedece `totais_sistema.total - fechamento_declarado.total`?
19. Quando `fechado=true`, os dados de fechamento podem mudar depois (correcao posterior)?
20. Como voces representam cancelamento/estorno/devolucao? Viram nova operacao ou atualizam operacao antiga?

## 4) Perguntas P1 (alta prioridade)

## 4.1 Granularidade de itens/pagamentos

21. Voces conseguem incluir `line_no` estavel em `vendas[].itens[]` e `vendas[].pagamentos[]`?
22. Se nao, qual campo pode servir de chave natural estavel para evitar duplicacao em reprocessamento?
23. Um item pode trocar de vendedor apos venda? Se sim, como isso aparece nos payloads seguintes?
24. `parcelas` pode vir `null`? Qual default correto quando nao informado?
25. Pagamento em dinheiro com troco: `valor` e bruto recebido ou liquido da venda?

## 4.2 Qualidade e consistencia funcional

26. `resumo.by_vendor` considera venda por item-vendedor ou cupom-vendedor?
27. Como tratar vendedor null em item: regra de negocio esperada?
28. Meio de pagamento (`id_finalizador`) tem dicionario estavel entre lojas/versoes?
29. Existe tabela oficial de finalizadores para mapearmos corretamente?
30. Existe risco de uma venda chegar sem `id_turno`?

## 4.3 Versionamento de contrato

31. Como sera versionado o payload (campo, header, URL ou todos)?
32. Qual o SLA de aviso previo para breaking changes no JSON?
33. Conseguem disponibilizar JSON Schema oficial versionado por release?
34. Em rollout gradual por lojas, pode haver lojas em `v1` e `v2` ao mesmo tempo?

## 5) Perguntas P2 (operacao e suporte)

35. Qual ambiente de homologacao voces disponibilizam (dados reais mascarados)?
36. Conseguem enviar payloads de replay para testes de carga e resiliencia?
37. Qual canal de suporte em incidente de integracao e SLA de resposta?
38. Voces monitoram taxa de erro HTTP por loja? Podem compartilhar dashboard?
39. Existe plano de contingencia quando webhook ficar indisponivel por horas?
40. Qual volume medio por payload (bytes, qtd vendas, qtd itens)?

## 6) Sugestoes tecnicas (nossas) para melhorar robustez

1. Incluir `line_no` em itens e pagamentos (idempotencia de filhos sem ambiguidade).
2. Enviar timezone explicito em todos os datetimes.
3. Manter `sync_id` deterministico e documentado.
4. Incluir `schema_version` no payload e changelog formal.
5. Enviar `X-Request-Id` por tentativa de POST.
6. Publicar JSON Schema + exemplos reais (casos normais e borda).
7. Catalogar eventos de excecao:
   - venda cancelada
   - estorno parcial
   - vendedor null
   - turno reaberto/corrigido
8. Definir matriz de erro/retry entre times:
   - `200`: recebido/duplicado
   - `422`: payload invalido (nao retry)
   - `401/403`: assinatura invalida (nao retry ate corrigir chave)
   - `500/503`: erro transitorio (retry)

## 7) Duvidas especificas que precisamos fechar por escrito

- Pode existir sobreposicao de janela (`window.from/to`)?
- Existe garantia de ordenacao de `ops.ids`?
- Pode vir venda duplicada em janelas diferentes por bug conhecido?
- Pode existir alteracao retroativa de venda ja enviada?
- Pode haver mudanca de `id_finalizador` no meio do turno por configuracao local?

## 8) Proposta de proximo passo com voces

1. Reuniao tecnica curta (45-60 min) para responder perguntas P0.
2. Fechamento de contrato em doc unico (JSON schema + regras de retry + erros).
3. Teste de ponta a ponta em homologacao com 3 cenarios:
   - fluxo normal 10/10 min,
   - offline com backlog e replay,
   - payload com casos de borda (cancelamento, vendedor null, multipagamento).
4. Go-live gradual por 1-2 lojas com monitoramento reforcado.

## 9) Template de resposta (para o time Hiper preencher)

Para cada pergunta:
- Resposta:
- Exemplo JSON/HTTP:
- Impacto tecnico:
- Data prevista (se houver ajuste):
- Responsavel:
