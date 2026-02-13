# PR-55 - Persistir `canal` em `pdv_turnos` + `pdv_turno_pagamentos`

Status: `todo`  
Prioridade: `P0`  
Tipo: `backend-feature`  
Dependencias: nenhuma (backward compatible)

## Objetivo
O agente v4.0 envia `canal` em cada turno (`HIPER_CAIXA` ou `HIPER_LOJA`). Hoje esse campo e **silenciosamente descartado** pelo `processTurnos()` e `processSnapshotTurnos()`. Sem persistir `canal`, o Financeiro nao consegue distinguir turnos de Caixa vs Loja.

## Impacto
- Turnos de ambos canais aparecem misturados nos relatorios
- Upsert key `(store_pdv_id, id_turno)` e semanticamente incorreta com 2 canais
- Pagamentos por turno tambem ficam sem identificador de canal

## Implementacao

### 1. Migration: `add_canal_to_pdv_turnos_table`

```php
// database/migrations/2026_02_14_000370_add_canal_to_pdv_turnos_table.php

Schema::table('pdv_turnos', function (Blueprint $table) {
    $table->string('canal', 20)
          ->default('HIPER_CAIXA')
          ->after('store_id');

    // Recriar unique key incluindo canal
    $table->dropUnique(['store_pdv_id', 'id_turno']);
    $table->unique(['store_pdv_id', 'canal', 'id_turno']);

    // Indice para filtros por canal
    $table->index(['store_id', 'canal', 'data_hora_inicio'], 'pdv_turnos_idx_store_canal_dt');
});
```

### 2. Migration: `add_canal_to_pdv_turno_pagamentos_table`

```php
// database/migrations/2026_02_14_000380_add_canal_to_pdv_turno_pagamentos_table.php

Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
    $table->string('canal', 20)
          ->default('HIPER_CAIXA')
          ->after('store_id');

    // Recriar unique key incluindo canal
    $table->dropUnique('pdv_turno_pagamentos_unique_key');
    $table->unique(
        ['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'],
        'pdv_turno_pag_unique_canal'
    );
});
```

### 3. `ProcessPdvSyncJob::processTurnos()` (L278-443)

Arquivo: `app/Jobs/ProcessPdvSyncJob.php`

#### 3a. Ler `canal` do payload (dentro do foreach, ~L300)

```diff
 foreach ($turnos as $turno) {
+    $canal = $this->resolveTurnoCanal($sync, $storePdvId, data_get($turno, 'canal'));

     $turnoRows[] = [
         'store_pdv_id' => $storePdvId,
         'store_id' => $storeId,
+        'canal' => $canal,
         'id_turno' => $idTurno,
```

#### 3b. Passar canal para buildTurnoPagamentoRows (~L360-380)

```diff
     $pagamentoRows = array_merge(
         $pagamentoRows,
         $this->buildTurnoPagamentoRows(
             $storePdvId,
             $storeId,
+            $canal,
             $idTurno,
             'sistema',
```

Repetir para os 3 calls (sistema, declarado, falta).

#### 3c. Atualizar upsert key (L423-428)

```diff
     $this->upsertRows(
         'pdv_turnos',
         $turnoRows,
-        ['store_pdv_id', 'id_turno'],
+        ['store_pdv_id', 'canal', 'id_turno'],
         $turnoUpdateColumns
     );
```

#### 3d. Atualizar upsert key de pagamentos (L430-442)

```diff
     $this->upsertRows(
         'pdv_turno_pagamentos',
         $pagamentoRows,
-        ['store_pdv_id', 'id_turno', 'tipo', 'id_finalizador'],
+        ['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'],
```

### 4. `ProcessPdvSyncJob::buildTurnoPagamentoRows()` (L988-1027)

```diff
 private function buildTurnoPagamentoRows(
     int $storePdvId,
     ?int $storeId,
+    string $canal,
     string $idTurno,
     string $tipo,
     mixed $values,
     PdvSync $sync,
     mixed $now
 ): array {
     // ...
     $rows[] = [
         'store_pdv_id' => $storePdvId,
         'store_id' => $storeId,
+        'canal' => $canal,
         'id_turno' => $idTurno,
```

### 5. `ProcessPdvSyncJob::processSnapshotTurnos()` (L855-986)

Mesma mudanca que processTurnos:
- [x] Ler `canal` de cada snapshot turno
- [x] Incluir `canal` no row array
- [x] Atualizar upsert unique key para `['store_pdv_id', 'canal', 'id_turno']`

### 6. Novo helper: `resolveTurnoCanal()`

Seguindo padrao de `resolveVendaCanal()` (L1582-1605):

```php
private function resolveTurnoCanal(PdvSync $sync, int $storePdvId, mixed $value): string
{
    $rawValue = $this->asString($value);
    if ($rawValue === null) {
        return self::CANAL_HIPER_CAIXA;
    }

    $normalized = Str::upper(str_replace('-', '_', $rawValue));
    if (in_array($normalized, [self::CANAL_HIPER_CAIXA, self::CANAL_HIPER_LOJA], true)) {
        return $normalized;
    }

    $this->markRuntimeRiskFlag('turno_canal_invalid');
    Log::warning('pdv.sync.turno_canal_invalid', [
        'pdv_sync_id' => $sync->id,
        'sync_id' => $sync->sync_id,
        'store_pdv_id' => $storePdvId,
        'canal' => $rawValue,
    ]);

    return self::CANAL_HIPER_CAIXA;
}
```

### 7. `PdvReportsController::turnos()` (L94-233)

```diff
 $query = DB::table('pdv_turnos as t')
     ->select([
+        't.canal',
         't.store_id',
         't.store_pdv_id',
```

Incluir `canal` na resposta de cada turno:

```diff
 return [
+    'canal' => $turno->canal ?? 'HIPER_CAIXA',
     'id_turno' => $turno->id_turno,
```

### 8. `loadTurnoPagamentos()` - Atualizar turnoCompositeKey

- No metodo que associa pagamentos aos turnos, adicionar `canal` na chave composta usada para lookup

## Compatibilidade
| Cenario | Comportamento |
|---|---|
| Agent v3 (sem `canal`) | Default `HIPER_CAIXA` |
| Agent v4 com `canal` | Persistido corretamente |
| Dados historicos | Todos recebem `HIPER_CAIXA` via default da migration |

## Criterios de aceite
- [ ] Migration roda sem erro em staging
- [ ] `processTurnos()` persiste `canal` corretamente para HIPER_CAIXA e HIPER_LOJA
- [ ] `processSnapshotTurnos()` persiste `canal`
- [ ] `buildTurnoPagamentoRows()` persiste `canal`
- [ ] Upsert nao duplica turnos (unique key com canal)
- [ ] Endpoint `turnos` retorna campo `canal` em cada turno
- [ ] Agentes v3 (sem canal) continuam funcionando (default HIPER_CAIXA)
