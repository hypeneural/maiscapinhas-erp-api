# Analise Geral - Scribe Endpoints PDV v3

Data: 2026-02-12
Projeto: `maiscapinhas-erp-api`
Escopo: documentacao oficial dos endpoints PDV no Scribe (`/docs` e `public/docs/openapi.yaml`).

## 1) Escopo validado

Endpoints PDV incluidos e revisados:

1. `POST /api/v1/pdv/sync`
2. `GET /api/v1/pdv/reports/turnos`
3. `GET /api/v1/pdv/reports/vendas`
4. `GET /api/v1/pdv/reports/ranking-vendedores`
5. `GET /api/v1/pdv/reports/ranking-vendedor-loja`
6. `GET /api/v1/admin/pdv/syncs`
7. `GET /api/v1/admin/pdv/syncs/metrics`

## 2) Melhorias aplicadas

### 2.1 Controllers com annotations Scribe

Arquivos atualizados:
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Controllers/Api/V1/PdvReportsController.php`
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`

Ajustes aplicados:
- `@group` dedicado: `PDV - Sync`, `PDV - Relatorios`, `PDV - Admin`
- `@queryParam` completo nos filtros dos endpoints GET
- `@bodyParam` completo no webhook `POST /pdv/sync`
- `@response` com cenarios de sucesso/erro
- autenticao correta (`@unauthenticated` no webhook e `@authenticated` nos relatorios/admin)

### 2.2 FormRequests para padronizar validacao + docs

Arquivos novos:
- `app/Http/Requests/Pdv/PdvReportsTurnosRequest.php`
- `app/Http/Requests/Pdv/PdvReportsVendasRequest.php`
- `app/Http/Requests/Pdv/PdvReportsRankingVendedoresRequest.php`
- `app/Http/Requests/Pdv/PdvReportsRankingVendedorLojaRequest.php`
- `app/Http/Requests/Pdv/PdvSyncAdminIndexRequest.php`
- `app/Http/Requests/Pdv/PdvSyncAdminMetricsRequest.php`

Controllers migrados para usar `->validated()` desses requests.

### 2.3 Correcao tecnica no OpenAPI (GET sem requestBody)

Mudanca chave:
- FormRequests GET passaram a expor `queryParameters()` (sem `bodyParameters()`).

Resultado:
- OpenAPI dos endpoints PDV GET agora usa apenas `parameters: in: query`.
- O `requestBody` indevido nos GET PDV foi removido.

### 2.4 Ordem dos grupos no Scribe

Arquivo atualizado:
- `config/scribe.php`

Incluidos na ordem:
- `PDV - Sync`
- `PDV - Relatorios`
- `PDV - Admin`

## 3) Validacao executada

Comando executado:

```bash
php artisan scribe:generate --no-interaction
```

Saida relevante:
- endpoints PDV processados com sucesso
- arquivos gerados:
  - `public/docs/index.html`
  - `public/docs/openapi.yaml`
  - `public/docs/collection.json`

Verificacoes manuais no `public/docs/openapi.yaml`:
- tags PDV presentes
- 7 endpoints PDV presentes
- GET PDV sem `requestBody`
- `POST /api/v1/pdv/sync` com `requestBody` e headers documentados

## 4) Gaps atuais (fora do escopo PDV, mas afetam status do comando)

O `scribe:generate` ainda retorna status final com erro por rotas antigas de upload que esperam arquivos de exemplo inexistentes:
- `photo.jpg`
- `fachada.jpg`
- `avatar.jpg`

Exemplos de rotas afetadas:
- `POST /api/v1/capas-personalizadas/{capa}/upload-publico`
- `PUT/POST /api/v1/stores/{store}/photo`
- `PUT/POST /api/v1/users/{user}/avatar`

Importante:
- isso nao bloqueia a geracao dos endpoints PDV
- mas impede pipeline 100% verde no comando global do Scribe

## 5) Recomendacao para fechar 100% do Scribe

Escolher 1 estrategia:

1. Adicionar arquivos placeholder no projeto (`photo.jpg`, `fachada.jpg`, `avatar.jpg`) para o gerador.
2. Excluir temporariamente essas rotas do `routes.exclude` no `config/scribe.php` durante geracao.
3. Criar pipeline separado de docs para PDV (escopo dedicado) e manter full docs em job separado.

## 6) Criterio de aceite PDV (status)

- [x] 7 endpoints PDV documentados com descricao funcional
- [x] filtros GET em query params
- [x] webhook com contrato de headers/body/respostas
- [x] grupos PDV organizados no Scribe
- [x] OpenAPI util para integracao frontend/BI
- [ ] `scribe:generate` full sem qualquer erro (pendente por uploads nao-PDV)

## 7) Arquivos principais desta entrega

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
