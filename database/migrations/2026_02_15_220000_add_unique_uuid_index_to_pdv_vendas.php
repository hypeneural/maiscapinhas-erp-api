<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            // Ensure fields are lowercase/normalized if possible, but DB collation usually handles case-insensitivity.
            // However, for UUIDs, exact match is preferred.

            // 1. Golden Key: Unique constraint on (loja_uuid, operacao_uuid)
            // Using a specific name 'udx_vendas_erp_uuid' to avoid auto-generated name length issues
            $table->unique(['erp_loja_uuid', 'erp_operacao_uuid'], 'udx_vendas_erp_uuid');

            // 2. Fiscal Fallback: Composite index on (loja_uuid, nfce_chave)
            // Useful for Level 2 lookups
            $table->index(['erp_loja_uuid', 'nfce_chave'], 'idx_vendas_erp_nfce');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            $table->dropUnique('udx_vendas_erp_uuid');
            $table->dropIndex('idx_vendas_erp_nfce');
        });
    }
};
