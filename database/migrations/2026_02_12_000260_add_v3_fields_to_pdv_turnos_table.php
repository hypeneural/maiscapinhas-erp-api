<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const IDX_STORE_PERIODO = 'pdv_turnos_idx_store_periodo';
    private const IDX_STORE_RESPONSAVEL = 'pdv_turnos_idx_store_responsavel_pdv';

    public function up(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_turnos', 'duracao_minutos')) {
                $table->unsignedInteger('duracao_minutos')->nullable()->after('data_hora_termino');
            }

            if (!Schema::hasColumn('pdv_turnos', 'periodo')) {
                $table->string('periodo', 20)->nullable()->after('duracao_minutos');
            }

            if (!Schema::hasColumn('pdv_turnos', 'responsavel_pdv_id')) {
                $table->unsignedBigInteger('responsavel_pdv_id')->nullable()->after('operador_nome');
            }

            if (!Schema::hasColumn('pdv_turnos', 'responsavel_nome')) {
                $table->string('responsavel_nome', 200)->nullable()->after('responsavel_pdv_id');
            }

            if (!Schema::hasColumn('pdv_turnos', 'qtd_vendas')) {
                $table->unsignedInteger('qtd_vendas')->default(0)->after('qtd_vendas_sistema');
            }

            if (!Schema::hasColumn('pdv_turnos', 'total_vendas')) {
                $table->decimal('total_vendas', 14, 2)->default(0)->after('qtd_vendas');
            }

            if (!Schema::hasColumn('pdv_turnos', 'qtd_vendedores')) {
                $table->unsignedInteger('qtd_vendedores')->default(0)->after('total_vendas');
            }

            $table->index(['store_id', 'periodo'], self::IDX_STORE_PERIODO);
            $table->index(['store_id', 'responsavel_pdv_id'], self::IDX_STORE_RESPONSAVEL);
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->dropIndex(self::IDX_STORE_PERIODO);
            $table->dropIndex(self::IDX_STORE_RESPONSAVEL);

            if (Schema::hasColumn('pdv_turnos', 'qtd_vendedores')) {
                $table->dropColumn('qtd_vendedores');
            }
            if (Schema::hasColumn('pdv_turnos', 'total_vendas')) {
                $table->dropColumn('total_vendas');
            }
            if (Schema::hasColumn('pdv_turnos', 'qtd_vendas')) {
                $table->dropColumn('qtd_vendas');
            }
            if (Schema::hasColumn('pdv_turnos', 'responsavel_nome')) {
                $table->dropColumn('responsavel_nome');
            }
            if (Schema::hasColumn('pdv_turnos', 'responsavel_pdv_id')) {
                $table->dropColumn('responsavel_pdv_id');
            }
            if (Schema::hasColumn('pdv_turnos', 'periodo')) {
                $table->dropColumn('periodo');
            }
            if (Schema::hasColumn('pdv_turnos', 'duracao_minutos')) {
                $table->dropColumn('duracao_minutos');
            }
        });
    }
};
