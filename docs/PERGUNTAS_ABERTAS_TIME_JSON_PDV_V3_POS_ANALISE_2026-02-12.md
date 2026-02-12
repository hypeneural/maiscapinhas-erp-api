# Perguntas Abertas para o Time do JSON PDV v3 (Pos-analise)

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Times envolvidos: Backend API + Time Integracao PDV (gerador do JSON)

---

## 1) Objetivo desta doc

Esta doc lista **duvidas abertas** apos a analise tecnica do backend e dos payloads reais.
O foco e fechar ambiguidades de logica, semantica de dados, reconcilicao e operacao para evitar regressao em producao.

Referencia de contexto:
- `docs/ANALISE_COMPLETA_ESTRUTURA_JSON_TABELAS_FILTROS_PDV_V3_2026-02-12.md`
- `docs/PERGUNTAS_TIME_JSON_PDV_V3_STACK_DUVIDAS_AJUSTES_2026-02-11.md`

---

## 2) Como responder (padrao sugerido)

Para cada pergunta, responder com:

- `ID`
- `Resposta curta` (sim/nao/regra)
- `Detalhe tecnico`
- `Exemplo de payload`
- `Impacto esperado para o backend`
- `Prazo` (se exigir mudanca no agente)
- `Responsavel`

---

## 3) Perguntas P0 (bloqueantes)

## P0.1 - Colisao de `line_id` entre canais no mesmo `id_operacao`

Contexto:
- `id_operacao` ja pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA`.
- No backend, as tabelas filhas ainda nao possuem `canal`.

Pergunta:
- O `line_id` de item e pagamento tambem pode colidir entre os dois bancos para a mesma loja?

Impacto:
- Define se a chave canonica de item/pagamento precisa obrigatoriamente incluir `canal`.

## P0.2 - Garantia de cardinalidade entre venda e linhas no dual-db

Pergunta:
- Existe algum cenario onde a mesma tupla (`store`, `id_operacao`, `line_id`) possa existir com conteudo diferente entre canais?

Impacto:
- Confirma risco real de mistura de linhas em agregacoes de relatorio.

## P0.3 - Header `X-PDV-Schema-Version` na operacao real

Contexto:
- Nos 6 payloads reais do n8n, o body veio `schema_version=3.0`, mas o header estava `2.0`.

Pergunta:
- O valor correto em producao deve ser sempre `3.0` no header tambem?
- Existe algum proxy/integrador que ainda sobrescreve esse header?

Impacto:
- Evita `422` por mismatch header x payload no ingress.

## P0.4 - Semantica oficial de cancelamento em curto prazo

Pergunta:
- Ate sair evento dedicado de cancelamento, qual regra oficial devemos adotar?
  - "sumiu do snapshot => considerar cancelado"?
  - ou apenas "nao confiar para cancelamento automatico"?

Impacto:
- Define regra de negocio para status de venda e reconciliacao automatica.

## P0.5 - Comportamento de `vendas[].id_turno` no canal `HIPER_LOJA`

Pergunta:
- Para `HIPER_LOJA`, `id_turno` deve vir sempre `null` ou pode vir preenchido em alguns cenarios?
- Se puder vir preenchido, qual e a regra de origem desse vinculo?

Impacto:
- Afeta filtros por turno e consistencia das telas de fechamento.

## P0.6 - Eventual terceiro canal

Pergunta:
- Existe roadmap para novos valores de `canal` alem de `HIPER_CAIXA` e `HIPER_LOJA`?
- Se sim, qual estrategia de compatibilidade recomendada no backend?

Impacto:
- Evita hardcode excessivo e quebra futura de contrato.

---

## 4) Perguntas P1 (alta prioridade de negocio/dados)

## P1.1 - Regra exata de `responsavel` no turno

Pergunta:
- Em caso de empate de qtd de itens entre vendedores, qual desempate oficial?
  - maior valor vendido?
  - menor `id_usuario`?
  - primeiro por horario?

Impacto:
- Garante reproducibilidade do responsavel entre agente e backend.

## P1.2 - Definicao de `total_sistema` x `total_vendas` no turno

Pergunta:
- Existe diferenca semantica oficial entre:
  - `turnos[].totais_sistema.total`
  - `turnos[].total_vendas`
- Quando podem divergir?

Impacto:
- Evita interpretacao incorreta nos dashboards de fechamento.

## P1.3 - Regra de `falta_caixa` com sinal

Pergunta:
- O campo `falta_caixa.total` pode ser negativo?
- Qual formula oficial (sistema - declarado, ou declarado - sistema)?

Impacto:
- Corrige exibicao de sobra/falta nas telas e relatorios.

## P1.4 - Precisao decimal oficial

Pergunta:
- Qual escala oficial por campo?
  - moeda: 2 casas
  - quantidade: 3 casas
  - existe excecao?

Impacto:
- Evita diferencas de arredondamento entre agente e backend.

## P1.5 - Timezone operacional

Pergunta:
- Todos datetime devem vir sempre com offset?
- Existe alguma loja/instancia emitindo datetime sem offset?

Impacto:
- Evita deslocamento de janela e erro em filtros por periodo.

## P1.6 - Classificacao de `periodo` (MATUTINO/VESPERTINO/NOTURNO)

Pergunta:
- Quais faixas horarias oficiais para cada periodo?
- Considera horario de inicio do turno, fim, ou predominancia?

Impacto:
- Permite validacao de consistencia e filtros confiaveis por periodo.

## P1.7 - Semantica de `ops.ids` e `ops.loja_ids` em payload sem vendas

Pergunta:
- Em `turno_closure` sem vendas, ambos devem ser arrays vazios?
- Existe cenario de `ops.count > 0` com `vendas=[]`?

Impacto:
- Evita falsos positivos em monitoramento de evento inconsistente.

## P1.8 - Ordem de janelas e replay

Pergunta:
- Qual estrategia recomendada para ordenar payloads quando houver replay de outbox?
  - sempre por `window.from/window.to`?
  - existe outro campo de ordenacao mais confiavel?

Impacto:
- Define politica correta de processamento e auditoria temporal.

## P1.9 - Vendedor nulo em item

Pergunta:
- Em item com `vendedor=null`, ha regra recomendada para atribuicao posterior?
  - manter nulo sempre?
  - mapear para operador?

Impacto:
- Afeta ranking e metricas de produtividade.

## P1.10 - Meio de pagamento e troco

Pergunta:
- `troco` pode existir em meios nao-dinheiro?
- `parcelas` pode ser nulo em cartao e qual default oficial?

Impacto:
- Evita interpretacao errada na analise financeira por meio.

---

## 5) Perguntas P2 (operacao, observabilidade, evolucao)

## P2.1 - Envelopes de amostra oficiais

Pergunta:
- Podem fornecer pacote fixo de payloads anonimizados de regressao com matriz minima:
  - `sales` so caixa
  - `sales` so loja
  - `mixed` com colisao de `id_operacao`
  - `turno_closure` sem vendas
  - replay com snapshots alterados

Impacto:
- Vira suite padrao de teste de contrato entre times.

## P2.2 - Sinalizacao de correcoes retroativas

Pergunta:
- Planejam enviar `corrections[]` (ou equivalente) no v3.1?
- Qual estrutura sugerida?

Impacto:
- Permite auditoria mais simples de mudanca de estado de venda/turno.

## P2.3 - SLA de comunicacao de mudanca de contrato

Pergunta:
- Mantem aviso minimo de 7 dias para breaking change?
- Qual canal oficial de notificacao (PR, doc versionada, webhook de changelog)?

Impacto:
- Reduz risco de quebra silenciosa em producao.

## P2.4 - Limites de volume e burst

Pergunta:
- Qual pico real esperado por payload e por minuto por loja?
- Ha limite recomendado de throughput do endpoint?

Impacto:
- Ajuste de rate limit e capacidade da fila.

## P2.5 - Comportamento de outbox em backlog longo

Pergunta:
- Em backlog grande, o agente divide em varias janelas menores ou envia janela unica muito ampla?

Impacto:
- Define estrategia de timeout e tamanho maximo de payload aceito.

---

## 6) Perguntas P3 (dicas e boas praticas do time JSON)

## P3.1 - Dicas de reconciliacao recomendadas por voces

Pergunta:
- Quais checks de consistencia voces usam internamente que recomendam reproduzir no backend?

Impacto:
- Aumenta robustez de validacao automatica.

## P3.2 - Dicionario oficial de meios de pagamento

Pergunta:
- Existe tabela oficial e estavel de `id_finalizador -> nome/categoria` para inicializar normalizacao?

Impacto:
- Melhora qualidade do agrupamento financeiro.

## P3.3 - Dicas para monitoramento de saude

Pergunta:
- Quais sinais operacionais voces consideram mais criticos:
  - ausencia de sync por loja
  - queda de snapshots
  - mudanca brusca de proporcao caixa x loja

Impacto:
- Ajuda a calibrar alertas com menos falso positivo.

## P3.4 - Recomendacao de politica para dados ambiguos

Pergunta:
- Quando houver conflito entre evento principal e snapshot, confirmam que snapshot deve sempre prevalecer?
- Existe alguma excecao formal?

Impacto:
- Fecha regra unica de reconcilicao no backend.

---

## 7) Checklist de resposta minima (para reuniao tecnica)

1. Responder todos os P0 com decisao final clara.  
2. Confirmar (ou ajustar) semantica de valores de fechamento (`total_sistema`, `declarado`, `falta`).  
3. Confirmar estrategia oficial para cancelamento ate haver evento dedicado.  
4. Confirmar padrao definitivo de header `X-PDV-Schema-Version=3.0`.  
5. Enviar pacote de payloads anonimizados de regressao.

---

## 8) Bloco para preencher na call (ata)

- Data da call:
- Participantes:
- Perguntas resolvidas:
- Decisoes finais:
- Itens com prazo:
- Responsaveis:
- Riscos remanescentes:
