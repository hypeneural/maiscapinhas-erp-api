<?php

declare(strict_types=1);

namespace App\Support\Pdv;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PdvUserResolver
{
    /**
     * @return array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * }
     */
    public function loadActiveMappings(): array
    {
        if (!Schema::hasTable('pdv_user_mappings')) {
            return [
                'by_id' => [],
                'by_login' => [],
            ];
        }

        $rows = DB::table('pdv_user_mappings')
            ->where('active', true)
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'pdv_user_id',
                'user_id',
                'is_store_operator',
                'pdv_user_name',
                'pdv_user_login',
            ]);

        /** @var array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}> $byId */
        $byId = [];
        /** @var array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}> $byLogin */
        $byLogin = [];

        foreach ($rows as $row) {
            $mapping = [
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'is_store_operator' => (bool) ($row->is_store_operator ?? false),
                'pdv_user_name' => $row->pdv_user_name !== null ? (string) $row->pdv_user_name : null,
                'pdv_user_login' => $row->pdv_user_login !== null ? (string) $row->pdv_user_login : null,
            ];

            $pdvUserId = (int) ($row->pdv_user_id ?? 0);
            if ($pdvUserId > 0 && !isset($byId[$pdvUserId])) {
                $byId[$pdvUserId] = $mapping;
            }

            $loginKey = $this->normalizeLogin($row->pdv_user_login ?? null);
            if ($loginKey !== null && !isset($byLogin[$loginKey])) {
                $byLogin[$loginKey] = $mapping;
            }
        }

        return [
            'by_id' => $byId,
            'by_login' => $byLogin,
        ];
    }

    /**
     * @param array{
     *   by_id?:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login?:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * }|array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}> $mappings
     * @return array{status:string,user_id:int|null,is_store_operator:bool,flags:array<int,string>}
     */
    public function resolve(?int $pdvUserId, ?string $pdvUserLogin, array $mappings): array
    {
        $byId = $this->extractByIdMappings($mappings);
        $byLogin = $this->extractByLoginMappings($mappings);
        $loginKey = $this->normalizeLogin($pdvUserLogin);

        if (($pdvUserId === null || $pdvUserId <= 0) && $loginKey === null) {
            return [
                'status' => 'empty',
                'user_id' => null,
                'is_store_operator' => false,
                'flags' => [],
            ];
        }

        $idMapping = ($pdvUserId !== null && $pdvUserId > 0) ? ($byId[$pdvUserId] ?? null) : null;
        $loginMapping = $loginKey !== null ? ($byLogin[$loginKey] ?? null) : null;
        $flags = [];

        if ($loginMapping !== null) {
            if ($idMapping !== null && !$this->sameIdentity($idMapping, $loginMapping)) {
                $flags[] = 'user_login_mismatch';
            }

            if ((bool) ($loginMapping['is_store_operator'] ?? false)) {
                return [
                    'status' => 'operator',
                    'user_id' => null,
                    'is_store_operator' => true,
                    'flags' => array_values(array_unique($flags)),
                ];
            }

            $loginUserId = (int) ($loginMapping['user_id'] ?? 0);
            if ($loginUserId > 0) {
                return [
                    'status' => 'resolved',
                    'user_id' => $loginUserId,
                    'is_store_operator' => false,
                    'flags' => array_values(array_unique($flags)),
                ];
            }
        }

        if ($idMapping !== null) {
            if ($loginKey !== null) {
                $flags[] = 'user_mapping_by_id_fallback';
            }

            if ((bool) ($idMapping['is_store_operator'] ?? false)) {
                return [
                    'status' => 'operator',
                    'user_id' => null,
                    'is_store_operator' => true,
                    'flags' => array_values(array_unique($flags)),
                ];
            }

            $idUserId = (int) ($idMapping['user_id'] ?? 0);
            if ($idUserId > 0) {
                return [
                    'status' => 'resolved',
                    'user_id' => $idUserId,
                    'is_store_operator' => false,
                    'flags' => array_values(array_unique($flags)),
                ];
            }
        }

        if ($loginKey !== null) {
            $flags[] = 'user_login_missing';
        }

        return [
            'status' => 'missing',
            'user_id' => null,
            'is_store_operator' => false,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    private function normalizeLogin(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array{
     *   by_id?:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login?:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * }|array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}> $mappings
     * @return array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     */
    private function extractByIdMappings(array $mappings): array
    {
        $byId = $mappings['by_id'] ?? null;
        if (is_array($byId)) {
            return $byId;
        }

        return $mappings;
    }

    /**
     * @param array{
     *   by_id?:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login?:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * }|array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}> $mappings
     * @return array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     */
    private function extractByLoginMappings(array $mappings): array
    {
        $byLogin = $mappings['by_login'] ?? null;
        if (is_array($byLogin)) {
            return $byLogin;
        }

        return [];
    }

    /**
     * @param array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string} $left
     * @param array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string} $right
     */
    private function sameIdentity(array $left, array $right): bool
    {
        if ((bool) ($left['is_store_operator'] ?? false) !== (bool) ($right['is_store_operator'] ?? false)) {
            return false;
        }

        return (int) ($left['user_id'] ?? 0) === (int) ($right['user_id'] ?? 0);
    }
}
