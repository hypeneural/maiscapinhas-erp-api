# PR-56 - Persistir `store.id_filial` em `pdv_syncs` (rastreabilidade)

Status: `todo`  
Prioridade: `P1`  
Tipo: `backend-feature`  
Dependencias: nenhuma

## Objetivo
O agente v4.0 envia `store.id_filial` no payload — o ID da filial no banco Gestao. Esse valor pode ser **diferente** de `store.id_ponto_venda` (ex: PDV id=10, Gestao filial=9). Persistir `id_filial` em `pdv_syncs` permite rastrear de qual filial os dados vieram.

## Impacto
- Sem `id_filial`, nao ha como saber qual filial Gestao gerou os turnos HIPER_LOJA
- Util para debug e reconciliacao cruzada PDV ↔ Gestao

## Implementacao

### 1. Migration: `add_id_filial_to_pdv_syncs_table`

```php
// database/migrations/2026_02_14_000390_add_id_filial_to_pdv_syncs_table.php

Schema::table('pdv_syncs', function (Blueprint $table) {
    $table->unsignedInteger('store_id_filial')
          ->nullable()
          ->after('store_pdv_id');
});
```

### 2. `ProcessPdvSyncJob::resolveStoreContext()` (L223-276)

Arquivo: `app/Jobs/ProcessPdvSyncJob.php`

```diff
 private function resolveStoreContext(PdvSync $sync, array $payload): array
 {
     $storePdvId = (int) ($sync->store_pdv_id ?: (int) data_get($payload, 'store.id_ponto_venda', 0));
+
+    // Persistir id_filial para rastreabilidade v4
+    $storeIdFilial = $this->asInt(data_get($payload, 'store.id_filial'));
+    if ($storeIdFilial !== null && $sync->store_id_filial === null) {
+        $sync->store_id_filial = $storeIdFilial;
+        // save ocorre abaixo no fluxo normal
+    }
```

Nota: o `$sync->save()` ja e chamado na L269-270 quando `store_id` e resolvido. Para garantir que `store_id_filial` seja salvo mesmo quando `store_id` ja existia (L231-236), adicionar:

```diff
     if ($storeId !== null) {
+        if ($sync->isDirty('store_id_filial')) {
+            $sync->save();
+        }
         return [
             'store_pdv_id' => $storePdvId,
             'store_id' => (int) $storeId,
         ];
     }
```

### 3. PdvSync Model - fillable

Se o modelo `PdvSync` usa `$fillable`, adicionar `store_id_filial`.

## Compatibilidade
| Cenario | Comportamento |
|---|---|
| Agent v3 (sem `id_filial`) | Campo permanece NULL |
| Agent v4 com `id_filial` | Valor persistido |
| Dados historicos | Permanecem NULL |

## Criterios de aceite
- [ ] Migration roda sem erro
- [ ] `resolveStoreContext()` persiste `id_filial` quando presente no payload
- [ ] Campo e nullable e nao quebra agents v3
- [ ] Valor visivel em `pdv_syncs` table para debug
