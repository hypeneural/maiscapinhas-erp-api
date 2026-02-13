<?php

declare(strict_types=1);

namespace App\Support\Pdv;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PdvStoreResolver
{
    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   status:string,
     *   store_pdv_id:int,
     *   store_id:int|null,
     *   mapping_id:int|null,
     *   mapped_alias:string|null,
     *   matched_by:string|null,
     *   risk_flags:array<int,string>,
     *   candidate_store_ids:array<int,int>,
     *   candidate_aliases:array<int,string>
     * }
     */
    public function resolveFromPayload(array $payload): array
    {
        return $this->resolve(
            (int) data_get($payload, 'store.id_ponto_venda', 0),
            $this->normalizeText(data_get($payload, 'store.alias')),
            $this->normalizeText(data_get($payload, 'store.nome')),
            $this->normalizeCnpj(data_get($payload, 'store.cnpj'))
        );
    }

    /**
     * @return array{
     *   status:string,
     *   store_pdv_id:int,
     *   store_id:int|null,
     *   mapping_id:int|null,
     *   mapped_alias:string|null,
     *   matched_by:string|null,
     *   risk_flags:array<int,string>,
     *   candidate_store_ids:array<int,int>,
     *   candidate_aliases:array<int,string>
     * }
     */
    public function resolve(int $storePdvId, ?string $storeAlias = null, ?string $storeName = null, ?string $cnpj = null): array
    {
        if ($storePdvId <= 0 || !Schema::hasTable('pdv_store_mappings')) {
            return $this->missing($storePdvId);
        }

        $baseQuery = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $storePdvId)
            ->where('active', true);

        if ($cnpj !== null && Schema::hasColumn('pdv_store_mappings', 'cnpj')) {
            $cnpjMatches = DB::table('pdv_store_mappings')
                ->where('active', true)
                ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$cnpj])
                ->get(['id', 'pdv_store_id', 'store_id', 'alias']);

            if ($cnpjMatches->count() === 1) {
                return $this->resolved($storePdvId, $cnpjMatches->first(), 'cnpj');
            }
            if ($cnpjMatches->count() > 1) {
                return $this->ambiguous($storePdvId, $cnpjMatches, ['store_mapping_ambiguous']);
            }
        }

        if ($storeAlias !== null) {
            $aliasMatches = (clone $baseQuery)
                ->whereRaw('LOWER(alias) = ?', [$storeAlias])
                ->get(['id', 'store_id', 'alias']);
            if ($aliasMatches->count() === 1) {
                return $this->resolved($storePdvId, $aliasMatches->first(), 'pdv_store_id_alias');
            }
            if ($aliasMatches->count() > 1) {
                return $this->ambiguous($storePdvId, $aliasMatches, ['store_mapping_ambiguous']);
            }
        }

        if ($storeName !== null) {
            $nameMatches = (clone $baseQuery)
                ->whereRaw('LOWER(alias) = ?', [$storeName])
                ->get(['id', 'store_id', 'alias']);
            if ($nameMatches->count() === 1) {
                return $this->resolved($storePdvId, $nameMatches->first(), 'pdv_store_id_nome');
            }
            if ($nameMatches->count() > 1) {
                return $this->ambiguous($storePdvId, $nameMatches, ['store_mapping_ambiguous']);
            }
        }

        $candidates = (clone $baseQuery)->get(['id', 'store_id', 'alias']);
        if ($candidates->count() === 1) {
            $flags = ['store_mapping_by_id_fallback'];
            $candidateAlias = $this->normalizeText(data_get($candidates->first(), 'alias'));
            if ($storeAlias !== null && $candidateAlias !== null && $candidateAlias !== $storeAlias) {
                $flags[] = 'store_alias_mismatch';
            }

            return $this->resolved($storePdvId, $candidates->first(), 'pdv_store_id', $flags);
        }

        if ($candidates->count() > 1) {
            return $this->ambiguous(
                $storePdvId,
                $candidates,
                ['store_mapping_ambiguous']
            );
        }

        return $this->missing($storePdvId);
    }

    /**
     * @return Collection<int, object>
     */
    public function activeMappingsByPdvId(int $storePdvId): Collection
    {
        if ($storePdvId <= 0 || !Schema::hasTable('pdv_store_mappings')) {
            return collect();
        }

        return DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $storePdvId)
            ->where('active', true)
            ->get(['id', 'pdv_store_id', 'store_id', 'alias', 'cnpj']);
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCnpj(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }

    /**
     * @param object $row
     * @param array<int, string> $riskFlags
     * @return array{
     *   status:string,
     *   store_pdv_id:int,
     *   store_id:int|null,
     *   mapping_id:int|null,
     *   mapped_alias:string|null,
     *   matched_by:string|null,
     *   risk_flags:array<int,string>,
     *   candidate_store_ids:array<int,int>,
     *   candidate_aliases:array<int,string>
     * }
     */
    private function resolved(int $storePdvId, object $row, string $matchedBy, array $riskFlags = []): array
    {
        return [
            'status' => 'resolved',
            'store_pdv_id' => $storePdvId,
            'store_id' => data_get($row, 'store_id') !== null ? (int) data_get($row, 'store_id') : null,
            'mapping_id' => data_get($row, 'id') !== null ? (int) data_get($row, 'id') : null,
            'mapped_alias' => data_get($row, 'alias') !== null ? (string) data_get($row, 'alias') : null,
            'matched_by' => $matchedBy,
            'risk_flags' => array_values(array_unique($riskFlags)),
            'candidate_store_ids' => [],
            'candidate_aliases' => [],
        ];
    }

    /**
     * @param Collection<int, object> $rows
     * @param array<int, string> $riskFlags
     * @return array{
     *   status:string,
     *   store_pdv_id:int,
     *   store_id:int|null,
     *   mapping_id:int|null,
     *   mapped_alias:string|null,
     *   matched_by:string|null,
     *   risk_flags:array<int,string>,
     *   candidate_store_ids:array<int,int>,
     *   candidate_aliases:array<int,string>
     * }
     */
    private function ambiguous(int $storePdvId, Collection $rows, array $riskFlags): array
    {
        return [
            'status' => 'ambiguous',
            'store_pdv_id' => $storePdvId,
            'store_id' => null,
            'mapping_id' => null,
            'mapped_alias' => null,
            'matched_by' => null,
            'risk_flags' => array_values(array_unique($riskFlags)),
            'candidate_store_ids' => $rows
                ->pluck('store_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'candidate_aliases' => $rows
                ->pluck('alias')
                ->filter(static fn (mixed $alias): bool => is_string($alias) && trim($alias) !== '')
                ->map(static fn (mixed $alias): string => trim((string) $alias))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   store_pdv_id:int,
     *   store_id:int|null,
     *   mapping_id:int|null,
     *   mapped_alias:string|null,
     *   matched_by:string|null,
     *   risk_flags:array<int,string>,
     *   candidate_store_ids:array<int,int>,
     *   candidate_aliases:array<int,string>
     * }
     */
    private function missing(int $storePdvId): array
    {
        return [
            'status' => 'missing',
            'store_pdv_id' => $storePdvId,
            'store_id' => null,
            'mapping_id' => null,
            'mapped_alias' => null,
            'matched_by' => null,
            'risk_flags' => ['store_mapping_missing'],
            'candidate_store_ids' => [],
            'candidate_aliases' => [],
        ];
    }
};
