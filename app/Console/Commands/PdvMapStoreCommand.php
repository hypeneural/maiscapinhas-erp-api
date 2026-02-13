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
                            {--cnpj= : CNPJ opcional para desambiguacao}
                            {--inactive : Criar/atualizar mapping como inativo}';

    protected $description = 'Create or update PDV store mapping (pdv_store_id + alias -> store_id).';

    public function handle(): int
    {
        $pdvStoreId = (int) $this->argument('pdv_store_id');
        $storeId = (int) $this->argument('store_id');
        $alias = $this->normalizeAlias($this->option('alias'));
        $cnpj = $this->normalizeCnpj($this->option('cnpj'));
        $active = !$this->option('inactive');

        if ($pdvStoreId <= 0 || $storeId <= 0) {
            $this->error('pdv_store_id e store_id devem ser inteiros positivos.');

            return self::FAILURE;
        }

        $store = DB::table('stores')->where('id', $storeId)->first(['id', 'name', 'cnpj']);
        if (!$store) {
            $this->error("Store ID {$storeId} nao existe.");

            return self::FAILURE;
        }

        if ($alias === null) {
            $candidates = DB::table('pdv_store_mappings')
                ->where('pdv_store_id', $pdvStoreId)
                ->orderByDesc('active')
                ->orderByDesc('id')
                ->get(['id', 'alias', 'active']);

            if ($candidates->count() > 1) {
                $this->error(
                    'Existem multiplos mappings para este pdv_store_id. '
                    . 'Informe --alias para atualizar a linha correta.'
                );

                return self::FAILURE;
            }

            if ($candidates->count() === 1) {
                $alias = $this->normalizeAlias($candidates->first()->alias ?? null);
                $this->warn('Alias nao informado. Reutilizando alias existente: ' . ($alias ?? 'null'));
            }
        }

        if ($cnpj === null) {
            $cnpj = $this->normalizeCnpj($store->cnpj ?? null);
        }

        $lookup = ['pdv_store_id' => $pdvStoreId];
        if ($alias !== null) {
            $lookup['alias'] = $alias;
        } else {
            $lookup['alias'] = null;
        }

        DB::table('pdv_store_mappings')->updateOrInsert(
            $lookup,
            [
                'store_id' => $storeId,
                'alias' => $alias,
                'cnpj' => $cnpj,
                'active' => $active,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $mappingQuery = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $pdvStoreId);
        if ($alias !== null) {
            $mappingQuery->where('alias', $alias);
        } else {
            $mappingQuery->whereNull('alias');
        }

        $mapping = $mappingQuery->first(['id', 'pdv_store_id', 'store_id', 'alias', 'cnpj', 'active']);

        $this->info('PDV store mapping salvo com sucesso:');
        $this->line(sprintf(
            'id=%d pdv_store_id=%d store_id=%d alias=%s cnpj=%s active=%d',
            (int) $mapping->id,
            (int) $mapping->pdv_store_id,
            (int) $mapping->store_id,
            (string) ($mapping->alias ?? 'null'),
            (string) ($mapping->cnpj ?? 'null'),
            (int) $mapping->active
        ));
        $this->line(sprintf('store_name=%s', (string) $store->name));

        return self::SUCCESS;
    }

    private function normalizeAlias(mixed $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        $value = trim((string) $alias);

        return $value !== '' ? $value : null;
    }

    private function normalizeCnpj(mixed $cnpj): ?string
    {
        if ($cnpj === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $cnpj);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }
}
