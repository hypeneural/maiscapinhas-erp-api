<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PdvMapStoreCommand extends Command
{
    protected $signature = 'pdv:map-store
                            {pdv_store_id : ID da loja no PDV (id_ponto_venda)}
                            {store_id : ID interno da loja no ERP}
                            {--alias= : Alias opcional da loja PDV}
                            {--inactive : Criar/atualizar mapping como inativo}';

    protected $description = 'Create or update PDV store mapping (pdv_store_id -> store_id).';

    public function handle(): int
    {
        $pdvStoreId = (int) $this->argument('pdv_store_id');
        $storeId = (int) $this->argument('store_id');
        $alias = $this->option('alias');
        $active = !$this->option('inactive');

        if ($pdvStoreId <= 0 || $storeId <= 0) {
            $this->error('pdv_store_id e store_id devem ser inteiros positivos.');

            return self::FAILURE;
        }

        $store = DB::table('stores')->where('id', $storeId)->first(['id', 'name']);
        if (!$store) {
            $this->error("Store ID {$storeId} nao existe.");

            return self::FAILURE;
        }

        DB::table('pdv_store_mappings')->updateOrInsert(
            ['pdv_store_id' => $pdvStoreId],
            [
                'store_id' => $storeId,
                'alias' => is_string($alias) && trim($alias) !== '' ? trim($alias) : null,
                'active' => $active,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $mapping = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $pdvStoreId)
            ->first(['id', 'pdv_store_id', 'store_id', 'alias', 'active']);

        $this->info('PDV store mapping salvo com sucesso:');
        $this->line(sprintf(
            'id=%d pdv_store_id=%d store_id=%d alias=%s active=%d',
            (int) $mapping->id,
            (int) $mapping->pdv_store_id,
            (int) $mapping->store_id,
            (string) ($mapping->alias ?? 'null'),
            (int) $mapping->active
        ));
        $this->line(sprintf('store_name=%s', (string) $store->name));

        return self::SUCCESS;
    }
}
