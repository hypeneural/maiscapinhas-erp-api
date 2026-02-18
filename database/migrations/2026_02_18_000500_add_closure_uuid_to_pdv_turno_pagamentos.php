<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->string('closure_uuid', 36)
                ->nullable()
                ->after('id_turno');

            $table->index('closure_uuid', 'pdv_turno_pag_idx_closure_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->dropIndex('pdv_turno_pag_idx_closure_uuid');
            $table->dropColumn('closure_uuid');
        });
    }
};
