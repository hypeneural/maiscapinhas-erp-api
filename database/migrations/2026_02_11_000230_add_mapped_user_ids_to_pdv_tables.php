<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_turnos', 'operador_user_id')) {
                $table->foreignId('operador_user_id')
                    ->nullable()
                    ->after('operador_pdv_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index(['store_id', 'operador_user_id'], 'pdv_turnos_idx_store_operador_user');
            }
        });

        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id')) {
                $table->foreignId('vendedor_user_id')
                    ->nullable()
                    ->after('vendedor_pdv_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index(['store_id', 'vendedor_user_id'], 'pdv_venda_itens_idx_store_vendedor_user');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id')) {
                $table->dropIndex('pdv_venda_itens_idx_store_vendedor_user');
                $table->dropConstrainedForeignId('vendedor_user_id');
            }
        });

        Schema::table('pdv_turnos', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_turnos', 'operador_user_id')) {
                $table->dropIndex('pdv_turnos_idx_store_operador_user');
                $table->dropConstrainedForeignId('operador_user_id');
            }
        });
    }
};

