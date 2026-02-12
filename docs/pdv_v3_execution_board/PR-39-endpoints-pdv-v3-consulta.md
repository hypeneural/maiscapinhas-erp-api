# PR-39 - Endpoints PDV v3 de consulta

Status: `done`  
Prioridade: `P1`  
Dependencias: PR-32, PR-33, PR-37

## Objetivo
Entregar API de consulta orientada ao modelo `pdv_*` com filtros de negocio do v3.

## Escopo in
- Endpoint de fechamento por turno.
- Endpoint de vendas com filtro por canal.
- Endpoint de ranking por vendedor com base em `pdv_*`.

## Escopo out
- Substituicao completa das APIs legadas de `sales` (migracao gradual).

## Checklist tecnico

## 1) Design de API
- [x] Definir prefixo de rotas (ex.: `/api/v1/pdv/reports/*`).
- [x] Definir controller dedicado (ex.: `PdvReportsController`).
- [x] Definir politica de autorizacao.

## 2) Endpoint fechamento por turno
- [x] Filtros:
- [x] `store_id` ou `store_pdv_id`
- [x] data
- [x] `sequencial`
- [x] `periodo`
- [x] Incluir dados:
- [x] totais de turno
- [x] pagamentos por tipo (`sistema`, `declarado`, `falta`)
- [x] operador e responsavel

## 3) Endpoint vendas com filtros v3
- [x] Filtros:
- [x] loja
- [x] periodo
- [x] vendedor
- [x] `canal`
- [x] turnos (opcional)
- [x] Paginacao e ordenacao.

## 4) Endpoint ranking vendedor
- [x] Modos:
- [x] diario
- [x] semanal
- [x] mensal
- [x] Filtros:
- [x] loja
- [x] canal
- [x] periodo
- [x] Base principal: `pdv_venda_itens` + `pdv_vendas`.

## 5) Contrato e docs
- [x] Documentar request/response no formato padrao.
- [x] Incluir exemplos com `HIPER_CAIXA` e `HIPER_LOJA`.

## 6) Testes
- [x] Autorizacao por perfil.
- [x] Filtros por canal.
- [x] Paginacao.
- [x] Consistencia entre totais e agregacoes.

## Criterio de aceite
- Frontend consegue consumir os 3 endpoints com filtros v3 sem depender da tabela legacy `sales`.

## Arquivos alvo esperados
- `app/Http/Controllers/Api/V1/PdvReportsController.php` (ou equivalente)
- `routes/api_v1.php`
- `app/Http/Requests/*` (se necessario)
- `tests/Feature/Api/V1/*`
- `docs/*` de API

## Riscos e mitigacoes
- Risco: divergencia entre ranking legacy e ranking PDV.
- Mitigacao: publicar regra de calculo e validar com amostra controlada.

## Validacao manual sugerida
- [ ] Conferir mesma loja com e sem filtro de canal.
- [ ] Validar totals com SQL manual.

## Entregaveis implementados
- `app/Http/Controllers/Api/V1/PdvReportsController.php`
- `routes/api_v1.php`
- `tests/Feature/Api/V1/PdvReportsControllerTest.php`
- `docs/API_PDV_REPORTS_V3.md`
