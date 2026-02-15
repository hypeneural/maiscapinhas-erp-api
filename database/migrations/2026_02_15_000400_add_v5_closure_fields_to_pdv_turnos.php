<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->string('closure_uuid', 36)->nullable()->after('total_falta');
            $table->dateTime('data_hora_fechamento')->nullable()->after('closure_uuid');
            $table->string('falta_uuid', 36)->nullable()->after('data_hora_fechamento');
            $table->string('sobra_uuid', 36)->nullable()->after('falta_uuid');
            $table->decimal('total_sobra', 14, 2)->nullable()->after('sobra_uuid');
            $table->string('tipo_operacao_fechamento', 30)->nullable()->after('total_sobra');

            $table->index('closure_uuid', 'pdv_turnos_idx_closure_uuid');
            $table->index('falta_uuid', 'pdv_turnos_idx_falta_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->dropIndex('pdv_turnos_idx_closure_uuid');
            $table->dropIndex('pdv_turnos_idx_falta_uuid');
            $table->dropColumn([
                'closure_uuid',
                'data_hora_fechamento',
                'falta_uuid',
                'sobra_uuid',
                'total_sobra',
                'tipo_operacao_fechamento',
            ]);
        });
    }
};
