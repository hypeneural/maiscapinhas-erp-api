<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const IDX_TURNOS_OPERADOR_LOGIN = 'pdv_turnos_idx_operador_login';
    private const IDX_TURNOS_RESPONSAVEL_LOGIN = 'pdv_turnos_idx_responsavel_login';
    private const IDX_ITENS_VENDEDOR_LOGIN_STORE = 'pdv_venda_itens_idx_vendedor_login_store';
    private const IDX_RESUMO_VENDEDOR_LOGIN_STORE = 'pdv_vendas_resumo_idx_vendedor_login_store';

    public function up(): void
    {
        if (Schema::hasTable('pdv_turnos')) {
            Schema::table('pdv_turnos', function (Blueprint $table): void {
                if (!Schema::hasColumn('pdv_turnos', 'operador_login')) {
                    $table->string('operador_login', 100)->nullable()->after('operador_nome');
                }
                if (!Schema::hasColumn('pdv_turnos', 'responsavel_login')) {
                    $table->string('responsavel_login', 100)->nullable()->after('responsavel_nome');
                }
            });

            $this->safeAddIndex('pdv_turnos', ['operador_login'], self::IDX_TURNOS_OPERADOR_LOGIN);
            $this->safeAddIndex('pdv_turnos', ['responsavel_login'], self::IDX_TURNOS_RESPONSAVEL_LOGIN);
        }

        if (Schema::hasTable('pdv_venda_itens')) {
            Schema::table('pdv_venda_itens', function (Blueprint $table): void {
                if (!Schema::hasColumn('pdv_venda_itens', 'vendedor_login')) {
                    $table->string('vendedor_login', 100)->nullable()->after('vendedor_nome');
                }
            });

            $this->safeAddIndex('pdv_venda_itens', ['vendedor_login', 'store_id'], self::IDX_ITENS_VENDEDOR_LOGIN_STORE);
        }

        if (Schema::hasTable('pdv_vendas_resumo')) {
            Schema::table('pdv_vendas_resumo', function (Blueprint $table): void {
                if (!Schema::hasColumn('pdv_vendas_resumo', 'vendedor_login')) {
                    $table->string('vendedor_login', 100)->nullable()->after('vendedor_nome');
                }
            });

            $this->safeAddIndex('pdv_vendas_resumo', ['vendedor_login', 'store_id'], self::IDX_RESUMO_VENDEDOR_LOGIN_STORE);
        }

        $this->backfillPdvUsuariosLoginFromMappings();
    }

    public function down(): void
    {
        if (Schema::hasTable('pdv_vendas_resumo')) {
            $this->safeDropIndex('pdv_vendas_resumo', self::IDX_RESUMO_VENDEDOR_LOGIN_STORE);
            Schema::table('pdv_vendas_resumo', function (Blueprint $table): void {
                if (Schema::hasColumn('pdv_vendas_resumo', 'vendedor_login')) {
                    $table->dropColumn('vendedor_login');
                }
            });
        }

        if (Schema::hasTable('pdv_venda_itens')) {
            $this->safeDropIndex('pdv_venda_itens', self::IDX_ITENS_VENDEDOR_LOGIN_STORE);
            Schema::table('pdv_venda_itens', function (Blueprint $table): void {
                if (Schema::hasColumn('pdv_venda_itens', 'vendedor_login')) {
                    $table->dropColumn('vendedor_login');
                }
            });
        }

        if (Schema::hasTable('pdv_turnos')) {
            $this->safeDropIndex('pdv_turnos', self::IDX_TURNOS_OPERADOR_LOGIN);
            $this->safeDropIndex('pdv_turnos', self::IDX_TURNOS_RESPONSAVEL_LOGIN);
            Schema::table('pdv_turnos', function (Blueprint $table): void {
                if (Schema::hasColumn('pdv_turnos', 'responsavel_login')) {
                    $table->dropColumn('responsavel_login');
                }
                if (Schema::hasColumn('pdv_turnos', 'operador_login')) {
                    $table->dropColumn('operador_login');
                }
            });
        }
    }

    private function safeAddIndex(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable) {
            // no-op
        }
    }

    private function safeDropIndex(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // no-op
        }
    }

    private function backfillPdvUsuariosLoginFromMappings(): void
    {
        if (!Schema::hasTable('pdv_usuarios') || !Schema::hasColumn('pdv_usuarios', 'login_hiper')) {
            return;
        }
        if (!Schema::hasTable('pdv_user_mappings') || !Schema::hasColumn('pdv_user_mappings', 'pdv_user_login')) {
            return;
        }

        $rows = \Illuminate\Support\Facades\DB::table('pdv_user_mappings')
            ->where('active', true)
            ->whereNotNull('pdv_user_login')
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['pdv_user_id', 'pdv_user_login']);

        foreach ($rows as $row) {
            $pdvUserId = (int) ($row->pdv_user_id ?? 0);
            if ($pdvUserId <= 0) {
                continue;
            }

            $login = trim((string) ($row->pdv_user_login ?? ''));
            if ($login === '') {
                continue;
            }

            \Illuminate\Support\Facades\DB::table('pdv_usuarios')
                ->where('id_usuario_hiper', $pdvUserId)
                ->where(function ($query): void {
                    $query->whereNull('login_hiper')
                        ->orWhere('login_hiper', '');
                })
                ->update([
                    'login_hiper' => $login,
                    'updated_at' => now(),
                ]);
        }
    }
};
