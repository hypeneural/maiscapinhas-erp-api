<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->dropIndex('pdv_turnos_idx_store_canal_dt');
            $table->dropUnique(['store_pdv_id', 'canal', 'id_turno']);
            $table->unique(['store_pdv_id', 'id_turno']);
            $table->dropColumn('canal');
        });
    }
};
