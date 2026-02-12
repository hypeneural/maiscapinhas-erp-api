# PR-43 - P1: Ranking vendedor x loja por periodo

Status: `done_tecnico`  
Prioridade: `P1`  
Dependencias: PR-41, PR-42

## Objetivo
Entregar visao analitica `vendedor x loja` por periodo para ranking comparativo entre unidades.

## Escopo in
- Endpoint novo de ranking cruzado.
- Reuso de regras de canal e periodo.
- Paginacao e ordenacao.

## Escopo out
- Metas e bonificacao automatica.

## Checklist tecnico

## 1) Contrato do endpoint
- [x] Definir rota (ex.: `/api/v1/pdv/reports/ranking-vendedor-loja`).
- [x] Definir filtros obrigatorios: `from`, `to`.
- [x] Definir filtros opcionais: `store_id`, `store_pdv_id`, `vendedor_id`, `canal`.
- [x] Definir modo de ordenacao (`total_vendido`, `qtd_vendas`, `qtd_itens`).

## 2) Implementacao de consulta
- [x] Basear query em `pdv_venda_itens` + `pdv_vendas` com join por chave canonica incluindo `canal`.
- [x] Agregar por `store_pdv_id + vendedor_pdv_id`.
- [x] Expor nome da loja (`pdv_lojas`/mapping) e nome do vendedor (`pdv_usuarios` ou fallback do item).
- [x] Incluir totais: `qtd_vendas`, `total_vendido`, `total_itens`.
- [x] Incluir ranking global e por loja (se aplicavel).

## 3) Camada de resposta
- [x] Definir bloco `summary` com totais gerais.
- [x] Definir bloco `data` com linhas agregadas.
- [x] Garantir formato consistente com endpoints existentes de relatorio.

## 4) Testes
- [x] Teste feature: ranking geral por periodo.
- [x] Teste feature: ranking filtrado por canal.
- [x] Teste feature: ranking de loja especifica.
- [x] Teste feature: ranking de vendedor especifico.
- [x] Teste feature: autorizacao de loja.

## 5) Documentacao
- [x] Atualizar `docs/API_PDV_REPORTS_V3.md` com contrato do endpoint.
- [x] Adicionar exemplos de request/response.

## Criterio de aceite
- API responde ranking confiavel por vendedor e loja no periodo informado.
- Nao ha mistura entre canais.

## Riscos e mitigacoes
- Risco: divergencia de nomes (vendedor/loja) entre tabelas.
- Mitigacao: usar fallback controlado e registrar gaps de master data.
