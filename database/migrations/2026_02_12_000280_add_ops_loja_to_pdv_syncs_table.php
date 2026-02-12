<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const IDX_OPS_LOJA_COUNT = 'pdv_syncs_idx_ops_loja_count';

    public function up(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_syncs', 'ops_loja_count')) {
                $table->unsignedInteger('ops_loja_count')->default(0)->after('ops_count');
            }

            if (!Schema::hasColumn('pdv_syncs', 'ops_loja_ids')) {
                $table->json('ops_loja_ids')->nullable()->after('ops_loja_count');
            }

            $table->index('ops_loja_count', self::IDX_OPS_LOJA_COUNT);
        });
    }

    public function down(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            $table->dropIndex(self::IDX_OPS_LOJA_COUNT);

            if (Schema::hasColumn('pdv_syncs', 'ops_loja_ids')) {
                $table->dropColumn('ops_loja_ids');
            }

            if (Schema::hasColumn('pdv_syncs', 'ops_loja_count')) {
                $table->dropColumn('ops_loja_count');
            }
        });
    }
};
