<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table): void {
            if (!Schema::hasColumn('pdv_syncs', 'snapshot_turnos_count')) {
                $table->unsignedInteger('snapshot_turnos_count')
                    ->default(0)
                    ->after('ops_loja_ids');
            }

            if (!Schema::hasColumn('pdv_syncs', 'snapshot_vendas_count')) {
                $table->unsignedInteger('snapshot_vendas_count')
                    ->default(0)
                    ->after('snapshot_turnos_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table): void {
            if (Schema::hasColumn('pdv_syncs', 'snapshot_vendas_count')) {
                $table->dropColumn('snapshot_vendas_count');
            }

            if (Schema::hasColumn('pdv_syncs', 'snapshot_turnos_count')) {
                $table->dropColumn('snapshot_turnos_count');
            }
        });
    }
};
