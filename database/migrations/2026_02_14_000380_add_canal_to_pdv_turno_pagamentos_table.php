<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->string('canal', 20)
                ->default('HIPER_CAIXA')
                ->after('store_id');

            // Recriar unique key incluindo canal
            $table->dropUnique('pdv_turno_pagamentos_unique_key');
            $table->unique(
                ['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'],
                'pdv_turno_pag_unique_canal'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->dropUnique('pdv_turno_pag_unique_canal');
            $table->unique(
                ['store_pdv_id', 'id_turno', 'tipo', 'id_finalizador'],
                'pdv_turno_pagamentos_unique_key'
            );
            $table->dropColumn('canal');
        });
    }
};
