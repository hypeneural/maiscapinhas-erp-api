# PR-48 - Documentacao Scribe dos Endpoints PDV v3

Prioridade: P0
Status: done tecnico (pendencia externa: upload examples fora do escopo PDV)
Responsavel: Backend API

## Objetivo
Publicar documentacao funcional e confiavel dos endpoints PDV v3 no Scribe (`/docs`) e OpenAPI (`public/docs/openapi.yaml`).

## Escopo

1. `POST /api/v1/pdv/sync`
2. `GET /api/v1/pdv/reports/turnos`
3. `GET /api/v1/pdv/reports/vendas`
4. `GET /api/v1/pdv/reports/ranking-vendedores`
5. `GET /api/v1/pdv/reports/ranking-vendedor-loja`
6. `GET /api/v1/admin/pdv/syncs`
7. `GET /api/v1/admin/pdv/syncs/metrics`

## Checklist tecnico

### A) Estrutura dos grupos
- [x] adicionar `@group` PDV nos 3 controllers PDV
- [x] incluir grupos PDV no `config/scribe.php` (`groups.order`)

### B) Contrato de parametros
- [x] documentar `@queryParam` de todos os filtros GET PDV
- [x] documentar `@bodyParam` do webhook `POST /pdv/sync`
- [x] documentar headers do webhook (`X-PDV-*`, bearer fallback)

### C) Exemplos de resposta
- [x] adicionar `@response` de sucesso e erro para os endpoints PDV
- [x] manter respostas alinhadas com retorno real dos controllers

### D) OpenAPI sem ruido em GET
- [x] criar FormRequests dedicados para endpoints GET PDV/admin
- [x] migrar controllers para usar os FormRequests
- [x] ajustar FormRequests para `queryParameters()` e evitar `requestBody` em GET

### E) Geracao e validacao
- [x] rodar `php artisan scribe:generate --no-interaction`
- [x] validar `public/docs/openapi.yaml` para os 7 endpoints
- [x] validar tags `PDV - Sync`, `PDV - Relatorios`, `PDV - Admin`

## Evidencia objetiva

- OpenAPI contem os 7 endpoints PDV com filtros em query nos GET.
- `requestBody` aparece apenas no endpoint de escrita (`POST /api/v1/pdv/sync`) dentro do escopo PDV.
- `/docs` atualizado com os grupos PDV separados.

## Pendencia nao-PDV (nao bloqueante para este PR)

`scribe:generate` ainda termina com erro global por arquivos de upload ausentes em outras rotas:
- `photo.jpg`
- `fachada.jpg`
- `avatar.jpg`

Acao recomendada para pipeline global 100% verde:
- provisionar arquivos placeholder, ou
- excluir temporariamente essas rotas do Scribe durante geracao.

## Arquivos impactados

- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Controllers/Api/V1/PdvReportsController.php`
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`
- `app/Http/Requests/Pdv/PdvReportsTurnosRequest.php`
- `app/Http/Requests/Pdv/PdvReportsVendasRequest.php`
- `app/Http/Requests/Pdv/PdvReportsRankingVendedoresRequest.php`
- `app/Http/Requests/Pdv/PdvReportsRankingVendedorLojaRequest.php`
- `app/Http/Requests/Pdv/PdvSyncAdminIndexRequest.php`
- `app/Http/Requests/Pdv/PdvSyncAdminMetricsRequest.php`
- `config/scribe.php`
- `public/docs/openapi.yaml`
- `public/docs/index.html`
- `public/docs/collection.json`
