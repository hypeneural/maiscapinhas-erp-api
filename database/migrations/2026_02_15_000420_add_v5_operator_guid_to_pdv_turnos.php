<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->string('operador_guid', 36)->nullable()->after('operador_login');
            $table->unsignedBigInteger('operador_hiper_id')->nullable()->after('operador_guid');
            $table->string('responsavel_guid', 36)->nullable()->after('responsavel_login');
            $table->unsignedBigInteger('responsavel_hiper_id')->nullable()->after('responsavel_guid');

            $table->index('operador_guid', 'pdv_turnos_idx_operador_guid');
            $table->index('responsavel_guid', 'pdv_turnos_idx_responsavel_guid');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turnos', function (Blueprint $table) {
            $table->dropIndex('pdv_turnos_idx_operador_guid');
            $table->dropIndex('pdv_turnos_idx_responsavel_guid');
            $table->dropColumn([
                'operador_guid',
                'operador_hiper_id',
                'responsavel_guid',
                'responsavel_hiper_id',
            ]);
        });
    }
};
