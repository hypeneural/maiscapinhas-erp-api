# PR-53 - Endpoint de Venda Detalhada (Itens + Pagamentos)

Status: `done`  
Prioridade: `P1`  
Tipo: `backend-api`  
Dependencias: `PR-41`, `PR-50`, `PR-51` (chaves canonicas + binding CNPJ/Login)

## Objetivo
Expor na API o **extrato detalhado** de uma venda (itens + pagamentos) que ja existe no banco (`pdv_venda_itens` / `pdv_venda_pagamentos`), para uso em telas de auditoria, suporte e fechamento.

Hoje o endpoint `GET /api/v1/pdv/reports/vendas` retorna apenas agregados por venda (contagem/soma). O detalhe granular nao e exposto.

## Contrato proposto

Endpoint (sugestao):
- `GET /api/v1/pdv/reports/vendas/detalhe`

Query params:
- `store_id` (ou) `store_pdv_id`
- `store_alias` (obrigatorio quando `store_pdv_id` colide e `store_id` nao for informado)
- `canal` (`HIPER_CAIXA|HIPER_LOJA`)
- `id_operacao` (int)

Resposta:
- `venda` (cabecalho): `store_id`, `store_pdv_id`, `canal`, `id_operacao`, `id_turno`, `data_hora`, `total`
- `itens[]`: `line_id`, `line_no`, `id_produto`, `codigo_barras`, `nome_produto`, `qtd`, `preco_unit`, `total`, `desconto`, `vendedor_pdv_id`, `vendedor_nome`, `vendedor_login`, `vendedor_user_id`
- `pagamentos[]`: `line_id`, `line_no`, `id_finalizador`, `meio_pagamento`, `valor`, `troco`, `parcelas`
- `summary`: totais calculados (opcional)

## Implementacao (tarefas)

1. Rotas
- [x] adicionar rota em `routes/api_v1.php` dentro do grupo `pdv/reports`
  - `GET /api/v1/pdv/reports/vendas/detalhe`

2. Request validation
- [x] criar `app/Http/Requests/Pdv/PdvReportsVendaDetalheRequest.php`
  - reaproveitar regras de `store_id/store_pdv_id/store_alias`
  - validar `canal` e `id_operacao`

3. Controller
- [x] implementar metodo `vendaDetalhe()` em `app/Http/Controllers/Api/V1/PdvReportsController.php`
  - usar `resolveStoreScope()` para desambiguar e aplicar ACL
  - buscar venda em `pdv_vendas` por `(store_pdv_id, canal, id_operacao)` (e `store_id` quando aplicavel)
  - buscar itens e pagamentos nas tabelas filhas com as mesmas chaves
  - ordenar:
    - itens: `line_no` asc, fallback `id`
    - pagamentos: `line_no` asc, fallback `id`

4. Scribe
- [x] documentar o novo endpoint no docblock (exemplo de resposta com itens/pagamentos)

5. Testes
- [x] adicionar testes feature (Pest) em `tests/Feature/Api/V1/PdvReportsControllerTest.php`
  - Obs: no momento os testes nao rodam localmente via MySQL porque o usuario do DB nao tem permissao no `maiscapinhas_erp_test` (phpunit.xml). Rodar em ambiente com DB de teste/CI.
- [x] executar validacao manual em producao (E2E) com venda real/controlada:
  - retorna itens + pagamentos + summary
  - ACL respeitada (token Sanctum + store scope)
- [x] teste de ambiguidade: `store_pdv_id` colidido sem `store_alias` retorna 422 orientativo (quando aplicavel)

## Criterios de aceite
- [x] endpoint retorna detalhe completo para uma venda real, sem N+1 e com ordenacao consistente
- [x] ACL respeitada (usuario comum so ve lojas permitidas)
- [x] `store_pdv_id` ambiguo sem `store_alias` retorna 422 orientativo
- [x] doc Scribe gerada com exemplo real
