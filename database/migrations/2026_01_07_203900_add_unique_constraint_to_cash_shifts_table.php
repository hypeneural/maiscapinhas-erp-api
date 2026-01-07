<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona constraint único em cash_shifts para garantir
 * unicidade de: Data + Loja + Turno + Vendedor
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->unique(
                ['store_id', 'date', 'shift_code', 'seller_id'],
                'unique_shift_per_store_date_code_seller'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropUnique('unique_shift_per_store_date_code_seller');
        });
    }
};
