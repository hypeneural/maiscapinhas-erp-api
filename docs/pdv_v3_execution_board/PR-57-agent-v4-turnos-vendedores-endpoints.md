# PR-57 - Turnos API: filtro `canal`, filtro `vendedor_id` e endpoint `turnos/{id_turno}/vendedores`

Status: `todo`  
Prioridade: `P1`  
Tipo: `backend-feature`  
Dependencias: `PR-55` (coluna `canal` em `pdv_turnos`)

## Objetivo
Expor o campo `canal` como filtro na API de turnos e adicionar endpoint de breakdown por vendedor, solicitado pelo Financeiro para reconciliacao.

## Implementacao

### 1. Filtro `canal` no endpoint `turnos`

#### 1a. `PdvReportsTurnosRequest` - nova regra de validacao

```php
// app/Http/Requests/Api/V1/PdvReportsTurnosRequest.php

'canal' => 'nullable|string|in:HIPER_CAIXA,HIPER_LOJA',
```

#### 1b. `PdvReportsController::turnos()` - aplicar filtro

```diff
 // Apos os filtros existentes (fechado, operador_id, responsavel_id)
+if (!empty($validated['canal'])) {
+    $query->where('t.canal', (string) $validated['canal']);
+}
```

Incluir no bloco `filters` da resposta:

```diff
 'filters' => [
     'store_id' => $storeId,
     'store_pdv_id' => $storePdvId,
     'date' => $date,
+    'canal' => $validated['canal'] ?? null,
```

### 2. Filtro `vendedor_id` no endpoint `turnos`

#### 2a. `PdvReportsTurnosRequest` - nova regra

```php
'vendedor_id' => 'nullable|integer|min:1',
```

#### 2b. `PdvReportsController::turnos()` - subquery

```php
if (isset($validated['vendedor_id'])) {
    $query->whereExists(function ($sub) use ($validated) {
        $sub->select(DB::raw(1))
            ->from('pdv_venda_itens as vi')
            ->whereColumn('vi.store_pdv_id', 't.store_pdv_id')
            ->whereColumn('vi.id_turno', 't.id_turno')
            ->where('vi.vendedor_pdv_id', (int) $validated['vendedor_id']);
    });
}
```

### 3. Novo endpoint: `GET /api/v1/pdv/reports/turnos/{id_turno}/vendedores`

Breakdown de vendas por vendedor dentro de um turno.

#### 3a. Rota

```php
// routes/api.php (grupo v1/pdv/reports)
Route::get('turnos/{id_turno}/vendedores', [PdvReportsController::class, 'turnoVendedores']);
```

#### 3b. Controller

```php
/**
 * Vendedores de um turno
 *
 * Retorna o breakdown de vendas por vendedor dentro de um turno especifico.
 *
 * @authenticated
 * @urlParam id_turno string required UUID do turno. Example: A1B2C3D4-E5F6-G7H8-I9J0-K1L2M3N4O5P6
 * @queryParam store_id integer ID da loja interna. Example: 1
 * @queryParam store_pdv_id integer ID da loja no PDV. Example: 13
 *
 * @response 200 {"data":[{"vendedor_pdv_id":55,"vendedor_nome":"Maria Silva","vendedor_login":"maria.silva","total_vendido":1250.90,"qtd_cupons":8,"qtd_itens":23}]}
 */
public function turnoVendedores(Request $request, string $idTurno): JsonResponse
{
    $storeId = $request->input('store_id');
    $storePdvId = $request->input('store_pdv_id');

    if ($storeId === null && $storePdvId === null) {
        throw ValidationException::withMessages([
            'store' => ['Informe store_id ou store_pdv_id.'],
        ]);
    }

    $scope = $this->resolveStoreScope($request, $storeId ? (int) $storeId : null, $storePdvId ? (int) $storePdvId : null);

    $query = DB::table('pdv_venda_itens as vi')
        ->join('pdv_vendas as v', 'v.id', '=', 'vi.pdv_venda_id')
        ->select([
            'vi.vendedor_pdv_id',
            'vi.vendedor_nome',
            'vi.vendedor_login',
            DB::raw('SUM(vi.total) as total_vendido'),
            DB::raw('COUNT(DISTINCT v.id_operacao) as qtd_cupons'),
            DB::raw('COUNT(*) as qtd_itens'),
        ])
        ->where('vi.id_turno', $idTurno)
        ->groupBy('vi.vendedor_pdv_id', 'vi.vendedor_nome', 'vi.vendedor_login')
        ->orderByDesc('total_vendido');

    $this->applyStoreScopeToQuery($query, $scope, 'vi');

    $vendedores = $query->get()->map(function ($row) {
        return [
            'vendedor_pdv_id' => (int) $row->vendedor_pdv_id,
            'vendedor_nome' => $row->vendedor_nome,
            'vendedor_login' => $row->vendedor_login,
            'total_vendido' => round((float) $row->total_vendido, 2),
            'qtd_cupons' => (int) $row->qtd_cupons,
            'qtd_itens' => (int) $row->qtd_itens,
        ];
    });

    return response()->json([
        'data' => $vendedores,
        'meta' => [
            'id_turno' => $idTurno,
            'request_id' => $request->header('X-Request-Id'),
            'timestamp' => now()->toIso8601String(),
        ],
    ]);
}
```

## Criterios de aceite
- [ ] Filtro `?canal=HIPER_LOJA` retorna apenas turnos do canal Loja
- [ ] Filtro `?vendedor_id=55` retorna apenas turnos com vendas desse vendedor
- [ ] Endpoint `turnos/{uuid}/vendedores` retorna breakdown correto por vendedor
- [ ] Scribe docs atualizados com novos parametros
- [ ] Respostas incluem `canal` no filters e no response de cada turno
