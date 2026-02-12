<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEX_NAME = 'pdv_vendas_idx_last_seen_snapshot';

    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_vendas', 'last_seen_in_snapshot_at')) {
                $table->dateTime('last_seen_in_snapshot_at')
                    ->nullable()
                    ->after('last_window_to');
                $table->index('last_seen_in_snapshot_at', self::INDEX_NAME);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_vendas', 'last_seen_in_snapshot_at')) {
                $table->dropIndex(self::INDEX_NAME);
                $table->dropColumn('last_seen_in_snapshot_at');
            }
        });
    }
};
