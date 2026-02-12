# Perguntas Finais ao Time Python (JSON PDV v3) - Pos E2E

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Destino: Time Integracao PDV (agente Python)

---

## 1) Contexto atual resumido

Estado do backend apos rollout v3:
- Ingestao `schema_version=3.0` funcionando em producao.
- Fila processando (`queued -> processed`) validado com payload real.
- Persistencia por `canal` validada em vendas, itens e pagamentos.
- Endpoints de relatorio (`vendas`, `turnos`, `ranking`) funcionando com filtros.
- Risco `GESTAO_DB_FAILURE` mapeado para `risk_flag` operacional.

Schema atual de referencia:
- `docs/schema_v3.0.json` (draft 2020-12, `additionalProperties=false`).

---

## 2) Perguntas P0 (impacto direto de contrato e dados)

## P0.1 - Reprocessamento com mesmo `sync_id` e dados alterados

Pergunta:
- Voces confirmam que `integrity.sync_id` e sempre deterministicamente derivado de `store + window`?
- Existe algum cenario real em que o mesmo `sync_id` possa ser reenviado com conteudo diferente no corpo principal (fora snapshot)?

Por que importa:
- O backend usa idempotencia por `sync_id`; se o corpo principal mudar com o mesmo id, podemos ignorar correcoes sem perceber.

## P0.2 - Invariante oficial entre `vendas[]` e `ops.*`

Pergunta:
- Regra oficial deve ser:
  - `ops.count == qtd de vendas HIPER_CAIXA`
  - `ops.loja_count == qtd de vendas HIPER_LOJA`
  - `len(vendas[]) == ops.count + ops.loja_count`
?
- Existe excecao conhecida para essa regra?

Por que importa:
- Permite validar consistencia automatica e alertar desvio real de coleta.

## P0.3 - Taxonomia oficial de `integrity.warnings[]`

Pergunta:
- Podem compartilhar lista oficial de codigos/prefixos de warning (ex.: `GESTAO_DB_FAILURE`, `responsavel_missing`, etc.)?
- Existe compromisso de estabilidade de nomenclatura (sem breaking em texto livre)?

Por que importa:
- Hoje warnings sao string livre; monitoramento e risk flags ficam mais robustos com catalogo oficial.

## P0.4 - Politica para payload muito grande (backlog longo)

Pergunta:
- Em backlog de varias horas, ainda sera enviado um unico payload grande?
- Existe limite maximo esperado de:
  - vendas por payload
  - itens por payload
  - tamanho em KB/MB
?

Por que importa:
- Ajuste de timeout/chunk no worker e protecao de memoria.

## P0.5 - Semantica de correcao por snapshot

Pergunta:
- Confirmam regra final: snapshot sempre prevalece sobre dado historico salvo, sem excecao?
- Existe algum campo que NAO deve ser sobrescrito por snapshot (ex.: metadado de auditoria)?

Por que importa:
- Fecha regra unica de reconciliacao para evitar comportamentos diferentes entre lojas.

---

## 3) Perguntas P1 (qualidade de relatorio e negocio)

## P1.1 - `qtd_vendedores` no `turnos[]` detalhe

Pergunta:
- Hoje podemos assumir que `turnos[].qtd_vendedores` pode vir parcial/placeholder, e o valor preciso deve ser confiado no `snapshot_turnos[]`?
- Ha plano de tornar o detalhe sempre preciso no futuro?

Por que importa:
- Evita divergencia entre tela de tempo real e consolidado.

## P1.2 - Troco em `HIPER_LOJA` com multiplos pagamentos

Pergunta:
- Com o fix recente, confirmam que `troco` aparece apenas em uma linha relevante e nao sera mais duplicado entre finalizadores?
- Existe regra oficial para qual linha carrega o troco quando ha varios meios?

Por que importa:
- Evita dupla contagem em fechamento financeiro.

## P1.3 - Campos com valor negativo em venda/item

Pergunta:
- Pode haver `itens[].qtd`, `itens[].total`, `pagamentos[].valor` negativos no v3 atual?
- Devolucao/estorno entra no mesmo evento (`sales`) ou ficara para contrato futuro?

Por que importa:
- Define validacoes e calculos de ranking/faturamento.

## P1.4 - `id_turno` em vendas `HIPER_LOJA`

Pergunta:
- Podem confirmar a regra operacional atual:
  - pode vir preenchido quando existe turno associado
  - pode vir `null` em operacao de loja sem turno
?
- Existe chance de uma venda `HIPER_LOJA` mudar de `id_turno` apos enviada?

Por que importa:
- Filtros por turno e conciliacao de fechamento.

## P1.5 - Timezone por loja

Pergunta:
- O agente continuara fixo em UTC-3 (`-03:00`) para todas as lojas?
- Ha qualquer loja planejada fora desse timezone no curto prazo?

Por que importa:
- Mantem seguranca dos filtros por janela/periodo.

---

## 4) Perguntas P2 (evolucao de contrato)

## P2.1 - Novos canais alem de `HIPER_CAIXA` e `HIPER_LOJA`

Pergunta:
- Existe previsao de terceiro canal no horizonte v3.x?
- Se sim, podem avisar o nome sugerido de antemao para prepararmos observabilidade?

## P2.2 - Evento explicito de cancelamento/correcao

Pergunta:
- Status atual do plano `corrections[]` ou `event_type=cancellation`?
- Qual payload base voces preferem hoje para discutirmos antes da v3.1?

## P2.3 - Estrategia de compatibilidade com `additionalProperties=false`

Pergunta:
- Como voces pretendem liberar novos campos opcionais sem quebrar ambientes com schema estrito?
- Fluxo sugerido:
  1) publicar schema novo
  2) backend atualizar
  3) agente habilitar campo
?

---

## 5) Sugestoes objetivas para o time Python

1. Padronizar `integrity.warnings` com codigo estruturado (`code`, `message`) no v3.1.  
2. Publicar changelog de contrato com exemplos de payload diffs por versao.  
3. Fornecer pacote fixo de payloads de regressao com cenarios de borda:
- backlog longo
- `GESTAO_DB_FAILURE`
- `responsavel=null`
- colisao de `id_operacao`
- replay com snapshot alterado
- vendas com multi-pagamento e troco

---

## 6) Bloco para resposta do time Python

- Pergunta ID:
- Resposta curta:
- Regra final:
- Exemplo de payload:
- Impacto para backend:
- Prazo (se houver mudanca no agente):

