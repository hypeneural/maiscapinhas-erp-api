<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const STORE_UNIQUE_PDV_STORE_ID = 'pdv_store_mappings_pdv_store_id_unique';
    private const STORE_UNIQUE_STORE_ID = 'pdv_store_mappings_store_id_unique';
    private const STORE_UNIQUE_COMPOSITE = 'pdv_store_mappings_unique_pdv_alias';
    private const STORE_INDEX_PDV_ACTIVE = 'pdv_store_mappings_idx_pdv_active';
    private const STORE_INDEX_STORE_ACTIVE = 'pdv_store_mappings_idx_store_active';
    private const STORE_INDEX_CNPJ_ACTIVE = 'pdv_store_mappings_idx_cnpj_active';

    private const USER_LEGACY_UNIQUE = 'pdv_user_mappings_unique_key';
    private const USER_LEGACY_INDEX_STORE_ACTIVE = 'pdv_user_mappings_idx_store_active';
    private const USER_LEGACY_INDEX_STORE_USER = 'pdv_user_mappings_idx_store_user';
    private const USER_UNIQUE_PDV_USER_ID = 'pdv_user_mappings_unique_pdv_user_id';
    private const USER_INDEX_ACTIVE = 'pdv_user_mappings_idx_active';
    private const USER_INDEX_USER_ACTIVE = 'pdv_user_mappings_idx_user_active';
    private const USER_INDEX_OPERATOR_ACTIVE = 'pdv_user_mappings_idx_operator_active';

    public function up(): void
    {
        $this->upgradeStoreMappings();
        $this->upgradeUserMappings();
    }

    public function down(): void
    {
        if (Schema::hasTable('pdv_user_mappings')) {
            $this->safeDropUnique('pdv_user_mappings', self::USER_UNIQUE_PDV_USER_ID);
            $this->safeDropIndex('pdv_user_mappings', self::USER_INDEX_ACTIVE);
            $this->safeDropIndex('pdv_user_mappings', self::USER_INDEX_USER_ACTIVE);
            $this->safeDropIndex('pdv_user_mappings', self::USER_INDEX_OPERATOR_ACTIVE);

            Schema::table('pdv_user_mappings', function (Blueprint $table): void {
                if (Schema::hasColumn('pdv_user_mappings', 'pdv_user_login')) {
                    $table->dropColumn('pdv_user_login');
                }
                if (Schema::hasColumn('pdv_user_mappings', 'pdv_user_name')) {
                    $table->dropColumn('pdv_user_name');
                }
                if (Schema::hasColumn('pdv_user_mappings', 'is_store_operator')) {
                    $table->dropColumn('is_store_operator');
                }
            });

            $this->safeAddUnique('pdv_user_mappings', ['store_pdv_id', 'pdv_user_id'], self::USER_LEGACY_UNIQUE);
            $this->safeAddIndex('pdv_user_mappings', ['store_pdv_id', 'active'], self::USER_LEGACY_INDEX_STORE_ACTIVE);
            $this->safeAddIndex('pdv_user_mappings', ['store_pdv_id', 'user_id'], self::USER_LEGACY_INDEX_STORE_USER);
        }

        if (Schema::hasTable('pdv_store_mappings')) {
            $this->safeDropUnique('pdv_store_mappings', self::STORE_UNIQUE_COMPOSITE);
            $this->safeDropIndex('pdv_store_mappings', self::STORE_INDEX_PDV_ACTIVE);
            $this->safeDropIndex('pdv_store_mappings', self::STORE_INDEX_STORE_ACTIVE);
            $this->safeDropIndex('pdv_store_mappings', self::STORE_INDEX_CNPJ_ACTIVE);

            Schema::table('pdv_store_mappings', function (Blueprint $table): void {
                if (Schema::hasColumn('pdv_store_mappings', 'cnpj')) {
                    $table->dropColumn('cnpj');
                }
            });

            $this->safeAddUnique('pdv_store_mappings', ['pdv_store_id'], self::STORE_UNIQUE_PDV_STORE_ID);
            $this->safeAddUnique('pdv_store_mappings', ['store_id'], self::STORE_UNIQUE_STORE_ID);
        }
    }

    private function upgradeStoreMappings(): void
    {
        if (!Schema::hasTable('pdv_store_mappings')) {
            return;
        }

        Schema::table('pdv_store_mappings', function (Blueprint $table): void {
            if (!Schema::hasColumn('pdv_store_mappings', 'cnpj')) {
                $table->string('cnpj', 18)->nullable()->after('alias');
            }
        });

        $this->safeDropUnique('pdv_store_mappings', self::STORE_UNIQUE_PDV_STORE_ID);
        $this->safeDropUnique('pdv_store_mappings', self::STORE_UNIQUE_STORE_ID);

        $this->safeAddUnique('pdv_store_mappings', ['pdv_store_id', 'alias'], self::STORE_UNIQUE_COMPOSITE);
        $this->safeAddIndex('pdv_store_mappings', ['pdv_store_id', 'active'], self::STORE_INDEX_PDV_ACTIVE);
        $this->safeAddIndex('pdv_store_mappings', ['store_id', 'active'], self::STORE_INDEX_STORE_ACTIVE);
        $this->safeAddIndex('pdv_store_mappings', ['cnpj', 'active'], self::STORE_INDEX_CNPJ_ACTIVE);
    }

    private function upgradeUserMappings(): void
    {
        if (!Schema::hasTable('pdv_user_mappings')) {
            return;
        }

        Schema::table('pdv_user_mappings', function (Blueprint $table): void {
            if (!Schema::hasColumn('pdv_user_mappings', 'pdv_user_name')) {
                $table->string('pdv_user_name', 100)->nullable()->after('pdv_user_id');
            }
            if (!Schema::hasColumn('pdv_user_mappings', 'pdv_user_login')) {
                $table->string('pdv_user_login', 100)->nullable()->after('pdv_user_name');
            }
            if (!Schema::hasColumn('pdv_user_mappings', 'is_store_operator')) {
                $table->boolean('is_store_operator')->default(false)->after('user_id');
            }
        });

        $this->safeDropForeign('pdv_user_mappings', ['user_id']);
        $this->makeUserMappingColumnsNullable();

        // Keep one row per pdv_user_id (highest confidence, then most recently updated).
        $winnerRows = DB::table('pdv_user_mappings')
            ->select(['id', 'pdv_user_id', 'confidence', 'updated_at'])
            ->orderBy('pdv_user_id')
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $this->deduplicateByPdvUserId($winnerRows);

        $this->safeDropUnique('pdv_user_mappings', self::USER_LEGACY_UNIQUE);
        $this->safeDropIndex('pdv_user_mappings', self::USER_LEGACY_INDEX_STORE_ACTIVE);
        $this->safeDropIndex('pdv_user_mappings', self::USER_LEGACY_INDEX_STORE_USER);

        $this->safeAddUnique('pdv_user_mappings', ['pdv_user_id'], self::USER_UNIQUE_PDV_USER_ID);
        $this->safeAddIndex('pdv_user_mappings', ['active'], self::USER_INDEX_ACTIVE);
        $this->safeAddIndex('pdv_user_mappings', ['user_id', 'active'], self::USER_INDEX_USER_ACTIVE);
        $this->safeAddIndex('pdv_user_mappings', ['is_store_operator', 'active'], self::USER_INDEX_OPERATOR_ACTIVE);

        $this->safeAddForeign('pdv_user_mappings', 'user_id', 'users', 'id');
    }

    private function makeUserMappingColumnsNullable(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE pdv_user_mappings MODIFY store_pdv_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE pdv_user_mappings MODIFY user_id BIGINT UNSIGNED NULL');

            return;
        }

        // sqlite/pgsql test environments: keep legacy shape if altering is unsupported.
        // Runtime code tolerates both legacy and normalized schemas.
    }

    /**
     * @param Collection<int, object> $rows
     */
    private function deduplicateByPdvUserId(Collection $rows): void
    {
        /** @var array<int, int> $winnerByPdvUserId */
        $winnerByPdvUserId = [];
        /** @var array<int, int> $deleteIds */
        $deleteIds = [];

        foreach ($rows as $row) {
            $pdvUserId = (int) ($row->pdv_user_id ?? 0);
            $id = (int) ($row->id ?? 0);
            if ($pdvUserId <= 0 || $id <= 0) {
                continue;
            }

            if (!isset($winnerByPdvUserId[$pdvUserId])) {
                $winnerByPdvUserId[$pdvUserId] = $id;
                continue;
            }

            $deleteIds[] = $id;
        }

        if ($deleteIds !== []) {
            DB::table('pdv_user_mappings')
                ->whereIn('id', $deleteIds)
                ->delete();
        }
    }

    private function safeDropUnique(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        } catch (\Throwable) {
            // no-op (index may not exist in all environments)
        }
    }

    private function safeDropIndex(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // no-op (index may not exist in all environments)
        }
    }

    private function safeAddUnique(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->unique($columns, $indexName);
            });
        } catch (\Throwable) {
            // no-op (already exists or incompatible data)
        }
    }

    private function safeAddIndex(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable) {
            // no-op (already exists)
        }
    }

    private function safeDropForeign(string $tableName, array $columns): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->dropForeign($columns);
            });
        } catch (\Throwable) {
            // no-op (foreign may not exist)
        }
    }

    private function safeAddForeign(string $tableName, string $column, string $refTable, string $refColumn): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($column, $refTable, $refColumn): void {
                $table->foreign($column)->references($refColumn)->on($refTable)->nullOnDelete();
            });
        } catch (\Throwable) {
            // no-op (foreign already exists)
        }
    }
};
